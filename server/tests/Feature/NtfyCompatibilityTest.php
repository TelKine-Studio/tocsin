<?php

namespace Tests\Feature;

use App\Jobs\SendMessagePush;
use App\Models\Message;
use App\Models\Topic;
use App\Models\User;
use App\Support\Ntfy\ActionParser;
use App\Support\Ntfy\DelayParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NtfyCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        User::factory()->admin()->create(); // instance installed
    }

    protected function basic(User $user): array
    {
        return ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode($user->email.':password')];
    }

    public function test_curl_d_to_the_topic_url_publishes(): void
    {
        $topic = Topic::factory()->create(['name' => 'backups']);

        $this->call('POST', '/backups', [], [], [], [
            ...$this->basic($topic->owner),
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_TITLE' => 'Backup',
            'HTTP_PRIORITY' => 'high',
            'HTTP_TAGS' => 'warning,skull',
            'HTTP_CLICK' => 'https://example.com',
        ], 'Backup completed')
            ->assertOk()
            ->assertJsonPath('event', 'message')
            ->assertJsonPath('topic', 'backups')
            ->assertJsonPath('message', 'Backup completed')
            ->assertJsonPath('title', 'Backup')
            ->assertJsonPath('priority', 4)
            ->assertJsonPath('tags', ['warning', 'skull']);

        Queue::assertPushed(SendMessagePush::class);
    }

    public function test_put_and_short_headers_work(): void
    {
        $topic = Topic::factory()->create(['name' => 'alerts']);

        $this->call('PUT', '/alerts', [], [], [], [...$this->basic($topic->owner), 'HTTP_T' => 'Short', 'HTTP_P' => '5'], 'Disk full')
            ->assertOk()->assertJsonPath('title', 'Short')->assertJsonPath('priority', 5);
    }

    public function test_json_publishing_to_the_root_url(): void
    {
        $topic = Topic::factory()->create(['name' => 'deploys']);

        $this->withServerVariables($this->basic($topic->owner))->postJson('/', [
            'topic' => 'deploys',
            'message' => 'v1.2 is live',
            'title' => 'Deploy',
            'tags' => ['rocket'],
            'priority' => 4,
            'actions' => [['action' => 'view', 'label' => 'Open', 'url' => 'https://example.com']],
        ])->assertOk()
            ->assertJsonPath('message', 'v1.2 is live')
            ->assertJsonPath('actions.0.label', 'Open');
    }

    public function test_publishing_with_query_parameters_for_webhooks(): void
    {
        $topic = Topic::factory()->anonymousPublish()->create(['name' => 'incoming']);

        $this->get('/incoming/trigger')->assertOk()->assertJsonPath('message', 'triggered');
        $this->get('/incoming/publish?message=Hello&title=Hi&priority=2')->assertOk()
            ->assertJsonPath('message', 'Hello')->assertJsonPath('title', 'Hi')->assertJsonPath('priority', 2);

        $this->assertSame(2, $topic->messages()->count());
    }

    public function test_auth_query_parameter_and_tokens(): void
    {
        $topic = Topic::factory()->create(['name' => 'private']);
        $token = $topic->owner->createToken('cli')->plainTextToken;

        $auth = rtrim(strtr(base64_encode('Bearer '.$token), '+/', '-_'), '=');
        $this->post('/private?auth='.$auth, [], [])->assertOk();

        $basic = base64_encode('Basic '.base64_encode($topic->owner->email.':password'));
        $this->get('/private/json?poll=1&auth='.$basic)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->get('/private/json?poll=1')->assertUnauthorized()->assertJsonPath('code', 40101);
    }

    public function test_topics_are_created_on_first_publish(): void
    {
        $user = User::factory()->create();

        $this->call('POST', '/brand-new', [], [], [], $this->basic($user), 'First!')->assertOk();

        $topic = Topic::where('name', 'brand-new')->sole();
        $this->assertTrue($topic->owner->is($user));
        $this->assertFalse($topic->isPublic());

        // Guests never create topics.
        $this->app['auth']->forgetGuards();
        $this->call('POST', '/ghost', [], [], [], [], 'x')->assertUnauthorized();
    }

    public function test_reserved_and_invalid_names_are_refused(): void
    {
        $user = User::factory()->create();

        $this->call('POST', '/topics', [], [], [], $this->basic($user), 'x')->assertRedirect(); // the web route wins
        $this->call('PUT', '/settings', [], [], [], $this->basic($user), 'x')->assertStatus(400);
        $this->assertFalse(Topic::where('name', 'settings')->exists());
    }

    public function test_polling_returns_ndjson_messages(): void
    {
        $topic = Topic::factory()->public()->create(['name' => 'news']);
        $first = Message::factory()->for($topic)->create(['body' => 'one']);
        Message::factory()->for($topic)->create(['body' => 'two']);
        Message::factory()->for($topic)->create(['body' => 'later', 'dispatched_at' => null, 'scheduled_at' => now()->addHour()]);

        $lines = array_filter(explode("\n", $this->get('/news/json?poll=1')->assertOk()->getContent()));
        $this->assertCount(2, $lines);
        $this->assertSame('one', json_decode($lines[0], true)['message']);

        $since = array_filter(explode("\n", $this->get("/news/json?poll=1&since={$first->id}")->getContent()));
        $this->assertCount(1, $since);

        $this->get('/news/raw?poll=1')->assertOk()->assertSee("one\ntwo", false);
        $this->get('/news/sse?poll=1')->assertOk()->assertSee('event: message', false);
        $this->get('/news/auth')->assertOk()->assertJson(['success' => true]);
        $this->get('/v1/health')->assertOk()->assertJson(['healthy' => true]);
    }

    public function test_file_attachments_are_stored_and_served_with_a_signed_url(): void
    {
        Storage::fake('local');
        $topic = Topic::factory()->create(['name' => 'files']);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        $response = $this->call('PUT', '/files', [], [], [], [...$this->basic($topic->owner), 'HTTP_FILENAME' => 'graph.png', 'CONTENT_TYPE' => 'image/png'], $png)
            ->assertOk()
            ->assertJsonPath('attachment.name', 'graph.png')
            ->assertJsonPath('attachment.type', 'image/png')
            ->assertJsonPath('attachment.size', strlen($png));

        $url = $response->json('attachment.url');
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(strtok($url, '?'))->assertForbidden();

        $message = Message::sole();
        $message->delete();
        Storage::disk('local')->assertMissing($message->attachment_path);
    }

    public function test_delayed_messages_are_delivered_later(): void
    {
        $topic = Topic::factory()->create(['name' => 'later']);

        $this->call('POST', '/later', [], [], [], [...$this->basic($topic->owner), 'HTTP_X_DELAY' => '30m'], 'Reminder')->assertOk();

        $message = Message::sole();
        $this->assertNull($message->dispatched_at);
        $this->assertEqualsWithDelta(now()->addMinutes(30)->getTimestamp(), $message->scheduled_at->getTimestamp(), 5);
        Queue::assertNothingPushed();
        $this->get('/api/v1/topics/later/messages', $this->basic($topic->owner))->assertJsonCount(0, 'data');

        $this->travel(31)->minutes();
        $this->artisan('tocsin:dispatch-scheduled')->assertSuccessful();

        $this->assertNotNull($message->fresh()->dispatched_at);
        Queue::assertPushed(SendMessagePush::class);
    }

    public function test_invalid_delays_are_refused(): void
    {
        $topic = Topic::factory()->create(['name' => 'bad']);

        $this->call('POST', '/bad', [], [], [], [...$this->basic($topic->owner), 'HTTP_X_DELAY' => '90d'], 'x')
            ->assertStatus(400)->assertJsonPath('code', 40000);
    }

    public function test_action_parser(): void
    {
        $actions = ActionParser::parse('view, Open portal, https://example.com, clear=true; http, Close door, https://api.example.com/door, method=PUT, headers.Authorization=Bearer x, body={"open":false}; broken');

        $this->assertCount(2, $actions);
        $this->assertSame(['action' => 'view', 'label' => 'Open portal', 'url' => 'https://example.com', 'clear' => true], $actions[0]);
        $this->assertSame('PUT', $actions[1]['method']);
        $this->assertSame(['Authorization' => 'Bearer x'], $actions[1]['headers']);
        $this->assertSame([], ActionParser::parse('view, Evil, javascript:alert(1)'));
    }

    public function test_delay_parser(): void
    {
        $this->assertEqualsWithDelta(now()->addMinutes(90)->getTimestamp(), DelayParser::parse('1h30m')->getTimestamp(), 2);
        $this->assertEqualsWithDelta(now()->addDays(2)->getTimestamp(), DelayParser::parse('2d')->getTimestamp(), 2);
        $this->assertSame(1893456000, DelayParser::parse('1893456000')->getTimestamp());
        $this->assertNull(DelayParser::parse('not a date at all ##'));
    }

    public function test_html_attachments_are_never_rendered_inline(): void
    {
        Storage::fake('local');
        $topic = Topic::factory()->create(['name' => 'xss']);

        $url = $this->call('PUT', '/xss', [], [], [], [...$this->basic($topic->owner), 'HTTP_FILENAME' => 'evil.html', 'CONTENT_TYPE' => 'text/html'], str_repeat('<script>alert(1)</script>', 400))
            ->assertOk()->json('attachment.url');

        $response = $this->get($url)->assertOk();
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }
}

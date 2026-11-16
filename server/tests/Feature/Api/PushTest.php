<?php

namespace Tests\Feature\Api;

use App\Jobs\SendMessagePush;
use App\Models\Device;
use App\Models\Message;
use App\Models\Topic;
use App\Models\User;
use App\Push\ApnsClient;
use App\Push\PushManager;
use App\Push\PushPayload;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PushTest extends TestCase
{
    use RefreshDatabase;

    protected string $publicKey;

    protected function configureApns(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        $this->publicKey = openssl_pkey_get_details($key)['key'];

        app(Settings::class)->set([
            'app.url' => 'https://notify.example.com',
            'apns.key_id' => 'ABC123DEFG',
            'apns.team_id' => 'TEAM123456',
            'apns.bundle_id' => 'com.example.tocsin',
            'apns.private_key' => $pem,
            'push.mode' => 'direct',
        ]);
    }

    protected function register(array $topics, ?string $token = null, array $extra = []): TestResponse
    {
        $request = $token ? $this->withToken($token) : $this;

        return $request->putJson('/api/v1/devices', [
            'token' => str_repeat('ab', 32),
            'platform' => 'ios',
            'environment' => 'sandbox',
            'name' => 'iPhone',
            'topics' => $topics,
            ...$extra,
        ]);
    }

    public function test_guests_can_register_for_public_topics_only(): void
    {
        $public = Topic::factory()->public()->create(['name' => 'news']);
        Topic::factory()->create(['name' => 'secret']);

        $this->register(['news', 'secret', 'missing'])
            ->assertOk()
            ->assertJsonPath('data.topics', ['news'])
            ->assertJsonPath('data.rejected.0', ['topic' => 'secret', 'reason' => 'unauthenticated'])
            ->assertJsonPath('data.rejected.1', ['topic' => 'missing', 'reason' => 'not_found']);

        $device = Device::sole();
        $this->assertNull($device->user_id);
        $this->assertTrue($device->topics->first()->is($public));
    }

    public function test_members_can_register_for_private_topics(): void
    {
        $topic = Topic::factory()->create(['name' => 'secret']);
        $member = User::factory()->create();
        $topic->members()->attach($member, ['can_read' => true]);

        $this->register(['secret'], $member->createToken('app')->plainTextToken)
            ->assertOk()
            ->assertJsonPath('data.topics', ['secret']);

        $this->assertTrue(Device::sole()->user->is($member));
    }

    public function test_registration_is_idempotent_and_syncs_topics(): void
    {
        Topic::factory()->public()->create(['name' => 'a']);
        Topic::factory()->public()->create(['name' => 'b']);

        $this->register(['a', 'b'])->assertOk();
        $this->register(['b'])->assertOk();

        $this->assertSame(1, Device::count());
        $this->assertSame(['b'], Device::sole()->topics->pluck('name')->all());

        $this->deleteJson('/api/v1/devices', ['token' => str_repeat('ab', 32)])->assertNoContent();
        $this->assertSame(0, Device::count());
    }

    public function test_registration_validates_the_token(): void
    {
        $this->register([], null, ['token' => 'not-hex!'])->assertJsonValidationErrors('token');
        $this->register([], null, ['environment' => 'staging'])->assertJsonValidationErrors('environment');
    }

    public function test_publishing_queues_a_push(): void
    {
        Queue::fake();
        $topic = Topic::factory()->anonymousPublish()->create();

        $this->postJson("/api/v1/topics/{$topic->name}/messages", ['message' => 'hi'])->assertCreated();

        Queue::assertPushed(SendMessagePush::class);
    }

    public function test_the_job_sends_notifications_through_apns(): void
    {
        $this->configureApns();
        Http::fake(['*' => Http::response('', 200)]);

        $topic = Topic::factory()->public()->create(['name' => 'alerts', 'display_name' => 'Alerts']);
        $device = Device::create(['token' => str_repeat('ab', 32), 'environment' => 'sandbox', 'platform' => 'ios']);
        $device->topics()->attach($topic);

        $message = Message::factory()->for($topic)->create(['title' => 'Disk', 'body' => 'Almost full', 'priority' => 5, 'click_url' => 'https://example.com']);

        (new SendMessagePush($message))->handle(app(PushManager::class), app(Settings::class));

        Http::assertSent(function (Request $request) {
            $payload = $request->data();

            return $request->url() === 'https://api.sandbox.push.apple.com/3/device/'.str_repeat('ab', 32)
                && $request->header('apns-topic')[0] === 'com.example.tocsin'
                && $request->header('apns-push-type')[0] === 'alert'
                && str_starts_with($request->header('authorization')[0], 'bearer ')
                && $payload['aps']['alert'] === ['title' => 'Disk', 'subtitle' => 'Alerts', 'body' => 'Almost full']
                && $payload['aps']['interruption-level'] === 'time-sensitive'
                && $payload['aps']['thread-id'] === 'alerts'
                && $payload['tocsin']['server'] === 'https://notify.example.com'
                && $payload['tocsin']['click'] === 'https://example.com';
        });

        $this->assertSame(1, $message->fresh()->push_count);
        $this->assertSame('sent', $message->fresh()->push_status);
        $this->assertNotNull($device->fresh()->last_push_at);
    }

    public function test_the_acknowledgement_notice_skips_the_excluded_devices(): void
    {
        $this->configureApns();
        Http::fake(['*' => Http::response('', 200)]);

        $topic = Topic::factory()->public()->create(['name' => 'alerts']);
        $mine = Device::create(['token' => str_repeat('ab', 32), 'environment' => 'sandbox', 'platform' => 'ios']);
        $theirs = Device::create(['token' => str_repeat('cd', 32), 'environment' => 'sandbox', 'platform' => 'ios']);
        $topic->devices()->attach([$mine->id, $theirs->id]);

        $message = Message::factory()->for($topic)->create(['body' => 'Server down', 'requires_ack' => true, 'acknowledged_at' => now(), 'acknowledged_name' => 'Awa', 'push_status' => 'sent']);

        (new SendMessagePush($message, SendMessagePush::ACKNOWLEDGED, [$mine->id]))->handle(app(PushManager::class), app(Settings::class));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), str_repeat('cd', 32))
            && $request->data()['aps']['alert']['body'] === "✓ Acknowledged by Awa\nServer down"
            && $request->data()['tocsin']['notice'] === SendMessagePush::ACKNOWLEDGED);
        $this->assertSame('sent', $message->fresh()->push_status);
    }

    public function test_invalid_tokens_are_forgotten(): void
    {
        $this->configureApns();
        Http::fake(['*' => Http::response(['reason' => 'Unregistered'], 410)]);

        $topic = Topic::factory()->public()->create();
        $device = Device::create(['token' => str_repeat('cd', 32), 'environment' => 'production', 'platform' => 'ios']);
        $device->topics()->attach($topic);

        (new SendMessagePush(Message::factory()->for($topic)->create()))->handle(app(PushManager::class), app(Settings::class));

        $this->assertModelMissing($device);
    }

    public function test_devices_that_lost_access_receive_nothing(): void
    {
        $this->configureApns();
        Http::fake();

        $topic = Topic::factory()->create();
        $former = User::factory()->create();
        $device = Device::create(['token' => str_repeat('ef', 32), 'environment' => 'production', 'platform' => 'ios', 'user_id' => $former->id]);
        $device->topics()->attach($topic);

        (new SendMessagePush(Message::factory()->for($topic)->create()))->handle(app(PushManager::class), app(Settings::class));

        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_when_apns_is_not_configured(): void
    {
        Http::fake();
        $topic = Topic::factory()->public()->create();
        Device::create(['token' => str_repeat('ab', 32), 'platform' => 'ios'])->topics()->attach($topic);

        $message = Message::factory()->for($topic)->create();
        (new SendMessagePush($message))->handle(app(PushManager::class), app(Settings::class));

        Http::assertNothingSent();
        $this->assertSame('disabled', $message->fresh()->push_status);
    }

    public function test_the_provider_token_is_a_valid_es256_jwt(): void
    {
        $this->configureApns();

        [$header, $claims, $signature] = explode('.', app(ApnsClient::class)->token());
        $decode = fn (string $value) => base64_decode(strtr($value, '-_', '+/'));

        $this->assertSame(['alg' => 'ES256', 'kid' => 'ABC123DEFG'], json_decode($decode($header), true));
        $this->assertSame('TEAM123456', json_decode($decode($claims), true)['iss']);

        $raw = $decode($signature);
        $this->assertSame(64, strlen($raw));

        $this->assertSame(1, openssl_verify("{$header}.{$claims}", $this->rawToDer($raw), $this->publicKey, OPENSSL_ALGO_SHA256));
    }

    public function test_info_endpoint_announces_push_support(): void
    {
        $this->getJson('/api/v1/info')->assertJsonPath('push.apns', false);

        $this->configureApns();
        app(Settings::class)->refresh();

        $this->getJson('/api/v1/info')->assertJsonPath('push.apns', true)->assertJsonPath('push.bundle_id', 'com.example.tocsin');
    }

    private function rawToDer(string $raw): string
    {
        $encode = function (string $int) {
            $int = ltrim($int, "\x00");
            if (ord($int[0]) > 0x7F) {
                $int = "\x00".$int;
            }

            return "\x02".chr(strlen($int)).$int;
        };

        $sequence = $encode(substr($raw, 0, 32)).$encode(substr($raw, 32));

        return "\x30".chr(strlen($sequence)).$sequence;
    }

    public function test_rich_and_confidential_payloads(): void
    {
        $topic = Topic::factory()->public()->create(['name' => 'rich']);
        $message = Message::factory()->for($topic)->create([
            'title' => 'Graph',
            'body' => 'CPU is high',
            'actions' => [['action' => 'view', 'label' => 'Open', 'url' => 'https://example.com', 'clear' => false]],
            'attachment_url' => 'https://example.com/graph.png',
            'attachment_name' => 'graph.png',
        ]);

        $payload = PushPayload::forMessage($message, 'https://n.test');
        $this->assertSame(1, $payload['aps']['mutable-content']);
        $this->assertSame('https://example.com/graph.png', $payload['tocsin']['image']);
        $this->assertSame('Open', $payload['tocsin']['actions'][0]['label']);

        app(Settings::class)->set(['push.confidential' => true]);
        $payload = PushPayload::forMessage($message, 'https://n.test');
        $this->assertStringNotContainsString('CPU', json_encode($payload));
        $this->assertSame(1, $payload['tocsin']['fetch']);
        $this->assertSame($message->id, $payload['tocsin']['id']);
    }
}

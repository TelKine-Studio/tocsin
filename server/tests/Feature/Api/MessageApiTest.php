<?php

namespace Tests\Feature\Api;

use App\Events\MessagePublished;
use App\Models\Message;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MessageApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_info_endpoint_describes_the_instance(): void
    {
        $this->getJson('/api/v1/info')
            ->assertOk()
            ->assertJsonStructure(['name', 'version', 'registration_enabled', 'realtime' => ['protocol', 'key', 'host', 'port', 'scheme']]);
    }

    public function test_mobile_login_returns_a_token(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);

        $token = $this->postJson('/api/v1/auth/token', [
            'email' => 'jane@example.com',
            'password' => 'password',
            'device_name' => 'Pixel 9',
        ])->assertCreated()->json('token');

        $this->assertSame('Pixel 9', $user->tokens()->sole()->name);

        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', 'jane@example.com');
        $this->withToken($token)->deleteJson('/api/v1/auth/token')->assertNoContent();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_mobile_login_rejects_bad_credentials_and_disabled_users(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);
        User::factory()->disabled()->create(['email' => 'off@example.com']);

        $this->postJson('/api/v1/auth/token', ['email' => 'jane@example.com', 'password' => 'nope'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/token', ['email' => 'off@example.com', 'password' => 'password'])->assertUnprocessable();
    }

    public function test_guests_can_read_public_topics_only(): void
    {
        $public = Topic::factory()->public()->create();
        $private = Topic::factory()->create();
        Message::factory()->for($public)->create(['body' => 'hello world']);

        $this->getJson('/api/v1/topics')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', $public->name);
        $this->getJson("/api/v1/topics/{$public->name}/messages")->assertOk()->assertJsonPath('data.0.message', 'hello world');
        $this->getJson("/api/v1/topics/{$private->name}/messages")->assertUnauthorized();
        $this->getJson("/api/v1/topics/{$public->name}")->assertJsonPath('data.channel', "topic.{$public->name}");
    }

    public function test_private_topics_need_access(): void
    {
        $topic = Topic::factory()->create();
        $member = User::factory()->create();
        $stranger = User::factory()->create();
        $topic->members()->attach($member, ['can_read' => true]);

        $this->withToken($stranger->createToken('t')->plainTextToken)
            ->getJson("/api/v1/topics/{$topic->name}/messages")->assertForbidden();

        $this->app['auth']->forgetGuards();

        $this->withToken($member->createToken('t')->plainTextToken)
            ->getJson("/api/v1/topics/{$topic->name}")
            ->assertOk()
            ->assertJsonPath('data.channel', "private-topic.{$topic->name}")
            ->assertJsonPath('data.permissions.read', true)
            ->assertJsonPath('data.permissions.write', false);
    }

    public function test_publishing_with_a_token_and_ntfy_style_headers(): void
    {
        Event::fake([MessagePublished::class]);
        $topic = Topic::factory()->create();
        $token = $topic->owner->createToken('script')->plainTextToken;

        $this->call('POST', "/api/v1/topics/{$topic->name}/messages", [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
            'HTTP_AUTHORIZATION' => "Bearer {$token}",
            'HTTP_X_TITLE' => 'Disk',
            'HTTP_X_PRIORITY' => 'urgent',
            'HTTP_X_TAGS' => 'disk,prod',
            'HTTP_X_CLICK' => 'https://example.com',
        ], 'Disk almost full')
            ->assertCreated()
            ->assertJsonPath('data.message', 'Disk almost full')
            ->assertJsonPath('data.title', 'Disk')
            ->assertJsonPath('data.priority', 5)
            ->assertJsonPath('data.tags', ['disk', 'prod'])
            ->assertJsonPath('data.click', 'https://example.com');

        Event::assertDispatched(MessagePublished::class);
    }

    public function test_publishing_with_json_and_basic_auth(): void
    {
        $topic = Topic::factory()->create();

        $this->withHeader('Authorization', 'Basic '.base64_encode($topic->owner->email.':password'))
            ->postJson("/api/v1/topics/{$topic->name}/messages", ['message' => 'From JSON', 'priority' => 2])
            ->assertCreated()
            ->assertJsonPath('data.priority', 2);
    }

    public function test_publishing_with_a_token_as_basic_password(): void
    {
        $topic = Topic::factory()->create();
        $token = $topic->owner->createToken('script')->plainTextToken;

        $this->withHeader('Authorization', 'Basic '.base64_encode(':'.$token))
            ->postJson("/api/v1/topics/{$topic->name}/messages", ['message' => 'Token in basic auth'])
            ->assertCreated();
    }

    public function test_curl_style_form_body_is_used_as_the_message(): void
    {
        $topic = Topic::factory()->anonymousPublish()->create();

        $this->call('POST', "/api/v1/topics/{$topic->name}/messages", [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'Backup done')
            ->assertCreated()
            ->assertJsonPath('data.message', 'Backup done');
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $topic = Topic::factory()->public()->create();

        $this->withToken('invalid')->getJson("/api/v1/topics/{$topic->name}/messages")->assertUnauthorized();
        $this->withHeader('Authorization', 'Basic '.base64_encode('nobody@example.com:x'))
            ->getJson("/api/v1/topics/{$topic->name}/messages")->assertUnauthorized();
    }

    public function test_anonymous_publishing_requires_the_topic_option(): void
    {
        $topic = Topic::factory()->public()->create();
        $open = Topic::factory()->anonymousPublish()->create();

        $this->postJson("/api/v1/topics/{$topic->name}/messages", ['message' => 'hi'])->assertUnauthorized();
        $this->postJson("/api/v1/topics/{$open->name}/messages", ['message' => 'hi'])->assertCreated();
    }

    public function test_members_without_write_permission_cannot_publish(): void
    {
        $topic = Topic::factory()->create();
        $reader = User::factory()->create();
        $topic->members()->attach($reader, ['can_read' => true, 'can_write' => false]);

        $this->withToken($reader->createToken('t')->plainTextToken)
            ->postJson("/api/v1/topics/{$topic->name}/messages", ['message' => 'hi'])
            ->assertForbidden();
    }

    public function test_message_validation(): void
    {
        $topic = Topic::factory()->create();
        $this->actingAs($topic->owner, 'sanctum');
        $token = $topic->owner->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson("/api/v1/topics/{$topic->name}/messages", ['message' => ''])->assertJsonValidationErrors('body');
        $this->withToken($token)->postJson("/api/v1/topics/{$topic->name}/messages", ['message' => 'x', 'priority' => 9])->assertJsonValidationErrors('priority');
        $this->withToken($token)->postJson("/api/v1/topics/{$topic->name}/messages", ['message' => str_repeat('x', 5000)])->assertJsonValidationErrors('body');
        $this->withToken($token)->postJson("/api/v1/topics/{$topic->name}/messages", ['message' => 'x', 'click' => 'javascript:alert(1)'])->assertJsonValidationErrors('click');
    }

    public function test_messages_can_be_fetched_since_an_id(): void
    {
        $topic = Topic::factory()->public()->create();
        $messages = Message::factory()->for($topic)->count(5)->create();

        $this->getJson("/api/v1/topics/{$topic->name}/messages?since={$messages[2]->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $messages[4]->id);
    }

    public function test_unknown_topics_return_404(): void
    {
        $this->getJson('/api/v1/topics/nope/messages')->assertNotFound();
    }

    public function test_private_channel_authorization(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'key', 'broadcasting.connections.reverb.secret' => 'secret', 'broadcasting.connections.reverb.app_id' => '1']);
        require base_path('routes/channels.php');

        $topic = Topic::factory()->create();
        $stranger = User::factory()->create();

        $this->withToken($topic->owner->createToken('t')->plainTextToken)
            ->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-topic.{$topic->name}"])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->app['auth']->forgetGuards();

        $this->withToken($stranger->createToken('t')->plainTextToken)
            ->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-topic.{$topic->name}"])
            ->assertForbidden();
    }
}

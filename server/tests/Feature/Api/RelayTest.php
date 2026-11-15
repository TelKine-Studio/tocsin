<?php

namespace Tests\Feature\Api;

use App\Jobs\SendMessagePush;
use App\Models\Device;
use App\Models\Message;
use App\Models\RelayClient;
use App\Models\RelayUsage;
use App\Models\Topic;
use App\Models\User;
use App\Push\PushManager;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RelayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // These tests play the instance operated by Telkine.
        config(['tocsin.relay_operator' => true]);
    }

    /** Configures this instance as a relay holding an APNs key. */
    protected function enableRelayServer(array $extra = []): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);

        app(Settings::class)->set([
            'push.mode' => 'direct',
            'apns.key_id' => 'ABC123DEFG',
            'apns.team_id' => 'TEAM123456',
            'apns.bundle_id' => 'ci.telkine.tocsin',
            'apns.private_key' => $pem,
            'relay_server.enabled' => true,
            ...$extra,
        ]);
    }

    protected function notification(string $ref = '1', array $payload = []): array
    {
        return [
            'ref' => $ref,
            'token' => str_repeat('ab', 32),
            'environment' => 'production',
            'payload' => ['aps' => ['alert' => ['title' => 'Hi', 'body' => 'Hello']], ...$payload],
            'headers' => ['apns-priority' => '10'],
        ];
    }

    public function test_the_relay_is_disabled_by_default(): void
    {
        $this->postJson('/api/relay/v1/instances', ['name' => 'X', 'url' => 'https://x.test', 'email' => 'a@x.test'])->assertNotFound();
    }

    public function test_instances_register_and_get_a_key(): void
    {
        $this->enableRelayServer();

        $key = $this->postJson('/api/relay/v1/instances', ['name' => 'Home', 'url' => 'https://home.test', 'email' => 'me@home.test'])
            ->assertCreated()
            ->assertJsonPath('data.plan', 'free')
            ->assertJsonPath('data.daily_limit', 1000)
            ->assertJsonPath('data.bundle_id', 'ci.telkine.tocsin')
            ->json('data.key');

        $this->assertStringStartsWith('tcr_', $key);
        $this->assertSame('Home', RelayClient::sole()->name);
        $this->assertNotSame($key, RelayClient::sole()->key_hash);

        $this->withToken($key)->getJson('/api/relay/v1/usage')->assertOk()->assertJsonPath('data.used_today', 0);
        $this->withToken('tcr_wrong')->getJson('/api/relay/v1/usage')->assertUnauthorized();
    }

    public function test_registration_can_be_closed(): void
    {
        $this->enableRelayServer(['relay_server.registration' => false]);

        $this->postJson('/api/relay/v1/instances', ['name' => 'Home', 'url' => 'https://home.test', 'email' => 'me@home.test'])->assertForbidden();
    }

    public function test_the_relay_forwards_notifications_to_apns_and_counts_them(): void
    {
        $this->enableRelayServer();
        Http::fake(['https://api.push.apple.com/*' => Http::response('', 200)]);
        [$client, $key] = RelayClient::register(['name' => 'Home', 'url' => 'https://home.test', 'email' => 'me@home.test']);

        $this->withToken($key)->postJson('/api/relay/v1/push', [
            'notifications' => [$this->notification('7', ['evil' => 'dropped'])],
        ])->assertOk()
            ->assertJsonPath('results.0', ['ref' => '7', 'status' => 200, 'reason' => null])
            ->assertJsonPath('usage.used_today', 1);

        Http::assertSent(fn (Request $request) => $request->header('apns-topic')[0] === 'ci.telkine.tocsin'
            && ! array_key_exists('evil', $request->data())
            && $request->data()['aps']['alert']['body'] === 'Hello');

        $this->assertSame(1, RelayUsage::sole()->sent);
        $this->assertNotNull($client->fresh()->last_used_at);
    }

    public function test_the_daily_quota_is_enforced(): void
    {
        $this->enableRelayServer(['relay_server.free_daily_limit' => 2]);
        Http::fake(['https://api.push.apple.com/*' => Http::response('', 200)]);
        [, $key] = RelayClient::register(['name' => 'Home', 'url' => 'https://home.test', 'email' => 'me@home.test']);

        $this->withToken($key)->postJson('/api/relay/v1/push', [
            'notifications' => [$this->notification('1'), $this->notification('2'), $this->notification('3')],
        ])->assertOk()
            ->assertJsonPath('results.2', ['ref' => '3', 'status' => 429, 'reason' => 'RelayQuotaExceeded']);

        $this->withToken($key)->postJson('/api/relay/v1/push', ['notifications' => [$this->notification()]])
            ->assertStatus(429)
            ->assertJsonPath('error', 'quota_exceeded');

        Http::assertSentCount(2);
        $this->assertSame(2, RelayUsage::sole()->rejected);
    }

    public function test_paid_plans_have_their_own_limit_and_disabled_clients_are_refused(): void
    {
        $this->enableRelayServer(['relay_server.free_daily_limit' => 0]);
        Http::fake(['https://api.push.apple.com/*' => Http::response('', 200)]);
        [$client, $key] = RelayClient::register(['name' => 'Corp', 'url' => 'https://corp.test', 'email' => 'it@corp.test']);
        $client->update(['plan' => 'pro', 'daily_limit' => 50000]);

        $this->withToken($key)->postJson('/api/relay/v1/push', ['notifications' => [$this->notification()]])->assertOk();

        $client->update(['is_active' => false]);
        $this->withToken($key)->postJson('/api/relay/v1/push', ['notifications' => [$this->notification()]])->assertForbidden();
    }

    public function test_instances_send_through_the_relay(): void
    {
        app(Settings::class)->set([
            'push.mode' => 'relay',
            'relay.url' => 'https://push.telkine.test',
            'relay.key' => 'tcr_secret',
            'app.url' => 'https://home.test',
        ]);

        Http::fake(['https://push.telkine.test/api/relay/v1/push' => function (Request $request) {
            return Http::response([
                'results' => collect($request->data()['notifications'])->map(fn ($n) => [
                    'ref' => $n['ref'],
                    'status' => $n['token'] === str_repeat('dd', 32) ? 410 : 200,
                    'reason' => $n['token'] === str_repeat('dd', 32) ? 'Unregistered' : null,
                ])->all(),
                'usage' => ['plan' => 'free', 'daily_limit' => 1000, 'used_today' => 2, 'bundle_id' => 'ci.telkine.tocsin'],
            ]);
        }]);

        $topic = Topic::factory()->public()->create();
        $ok = Device::create(['token' => str_repeat('ab', 32), 'platform' => 'ios']);
        $gone = Device::create(['token' => str_repeat('dd', 32), 'platform' => 'ios']);
        $topic->devices()->attach([$ok->id, $gone->id]);

        $message = Message::factory()->for($topic)->create();
        (new SendMessagePush($message))->handle(app(PushManager::class), app(Settings::class));

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer tcr_secret')
            && count($request->data()['notifications']) === 2
            && $request->data()['notifications'][0]['payload']['tocsin']['server'] === 'https://home.test');

        $this->assertSame(1, $message->fresh()->push_count);
        $this->assertSame('partial', $message->fresh()->push_status);
        $this->assertModelMissing($gone);

        $settings = app(Settings::class);
        $settings->refresh();
        $this->assertSame('ci.telkine.tocsin', $settings->pushBundleId());
    }

    public function test_admins_connect_their_instance_in_one_click(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'admin@home.test']);
        app(Settings::class)->set(['app.name' => 'Home', 'app.url' => 'https://home.test']);

        Http::fake(['https://push.telkine.test/api/relay/v1/instances' => Http::response(['data' => [
            'key' => 'tcr_new', 'plan' => 'free', 'daily_limit' => 1000, 'used_today' => 0, 'bundle_id' => 'ci.telkine.tocsin',
        ]], 201)]);

        $this->actingAs($admin)->post('/admin/settings/push/relay', ['relay_url' => 'https://push.telkine.test/'])
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => $request['email'] === 'admin@home.test' && $request['url'] === 'https://home.test');

        $settings = app(Settings::class);
        $settings->refresh();
        $this->assertTrue($settings->pushConfigured());
        $this->assertSame('tcr_new', $settings->get('relay.key'));
        $this->assertSame('https://push.telkine.test', $settings->relayUrl());

        $this->getJson('/api/v1/info')->assertJsonPath('push.apns', true)->assertJsonPath('push.mode', 'relay');
    }

    public function test_relay_operators_manage_instances(): void
    {
        $this->enableRelayServer();
        $admin = User::factory()->admin()->create();
        [$client] = RelayClient::register(['name' => 'Corp', 'url' => 'https://corp.test', 'email' => 'it@corp.test']);

        $this->actingAs($admin)->get('/admin/relay-clients')->assertOk()->assertSee('Corp');
        $this->actingAs($admin)->get('/admin/settings/relay')->assertOk();
        $this->actingAs($admin)->get('/admin/settings/push')->assertOk();

        $this->actingAs($admin)->put("/admin/relay-clients/{$client->id}", ['plan' => 'pro', 'daily_limit' => 50000, 'is_active' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(50000, $client->fresh()->effectiveDailyLimit());

        $this->actingAs($admin)->post("/admin/relay-clients/{$client->id}/key")->assertSessionHas('relay_key');
    }

    public function test_quota_and_missing_devices_are_reported(): void
    {
        app(Settings::class)->set(['push.mode' => 'relay', 'relay.url' => 'https://push.telkine.test', 'relay.key' => 'tcr_secret']);
        Http::fake(['https://push.telkine.test/*' => Http::response(['error' => 'quota_exceeded'], 429)]);

        $topic = Topic::factory()->public()->create();
        $empty = Message::factory()->for($topic)->create();
        (new SendMessagePush($empty))->handle(app(PushManager::class), app(Settings::class));
        $this->assertSame('no_devices', $empty->fresh()->push_status);

        $topic->devices()->attach(Device::create(['token' => str_repeat('ab', 32), 'platform' => 'ios']));
        $message = Message::factory()->for($topic)->create();
        (new SendMessagePush($message))->handle(app(PushManager::class), app(Settings::class));
        $this->assertSame('quota', $message->fresh()->push_status);

        $this->actingAs($topic->owner)->get("/topics/{$topic->name}")->assertSee(__('Relay quota reached'));
    }

    public function test_self_hosted_instances_do_not_sell_the_relay(): void
    {
        config(['tocsin.relay_operator' => false]);
        app(Settings::class)->set(['relay_server.enabled' => true]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/settings/push')->assertOk()->assertDontSee(__('Serve other instances'));
        $this->actingAs($admin)->get('/admin/settings/relay')->assertNotFound();
        $this->actingAs($admin)->get('/admin/relay-clients')->assertNotFound();
        $this->actingAs($admin)->put('/admin/settings/relay', ['enabled' => '1'])->assertNotFound();
        $this->postJson('/api/relay/v1/instances', ['url' => 'https://a.example.com'])->assertNotFound();
    }
}

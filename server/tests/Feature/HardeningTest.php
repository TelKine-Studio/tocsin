<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\RelayClient;
use App\Models\RelayToken;
use App\Models\Topic;
use App\Models\User;
use App\Push\WebPushEndpoint;
use App\Support\Installation;
use App\Support\Settings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** Fixes of the security audit of November 2026. */
class HardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function withTwoFactor(User $user): User
    {
        $user->forceFill([
            'two_factor_secret' => (new Google2FA)->generateSecretKey(32),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['aaaaa-bbbbb'],
        ])->save();

        return $user;
    }

    public function test_basic_auth_with_a_password_is_limited_and_refused_with_2fa(): void
    {
        $owner = User::factory()->create(['email' => 'jane@example.com']);
        Topic::factory()->for($owner, 'owner')->create(['name' => 'alerts']);

        $this->withBasicAuth('jane@example.com', 'password')->get('/alerts/auth')->assertOk();

        // Wrong passwords: refused, then blocked for the account.
        foreach (range(1, 5) as $i) {
            $this->withBasicAuth('jane@example.com', 'wrong'.$i)->getJson('/alerts/auth')->assertUnauthorized();
        }
        $this->withBasicAuth('jane@example.com', 'password')->getJson('/alerts/auth')->assertStatus(429);

        // 2FA accounts must use a token.
        $bob = $this->withTwoFactor(User::factory()->create(['email' => 'bob@example.com']));
        Topic::factory()->for($bob, 'owner')->create(['name' => 'ops']);
        $this->withBasicAuth('bob@example.com', 'password')->getJson('/ops/auth')->assertUnauthorized()->assertSee('access token');
        $token = $bob->createToken('cli')->plainTextToken;
        $this->withBasicAuth('', $token)->getJson('/ops/auth')->assertOk();
        $this->withToken($token)->getJson('/ops/auth')->assertOk();
    }

    public function test_only_administrators_see_the_directory_of_users(): void
    {
        $owner = User::factory()->create();
        User::factory()->create(['email' => 'secret.person@example.com']);
        $topic = Topic::factory()->for($owner, 'owner')->create();

        $this->actingAs($owner)->get(route('topics.show', $topic))->assertOk()->assertDontSee('secret.person@example.com');
        $this->actingAs(User::factory()->admin()->create())->get(route('topics.show', $topic))->assertSee('secret.person@example.com');

        // Owners still add people by typing their email.
        $this->actingAs($owner)->post(route('topics.members.store', $topic), ['email' => 'secret.person@example.com'])->assertSessionHasNoErrors();
    }

    public function test_web_push_only_accepts_browser_push_services(): void
    {
        $this->assertTrue(WebPushEndpoint::allowed('https://fcm.googleapis.com/fcm/send/abc'));
        $this->assertTrue(WebPushEndpoint::allowed('https://updates.push.services.mozilla.com/wpush/v2/abc'));
        $this->assertTrue(WebPushEndpoint::allowed('https://web.push.apple.com/abc'));
        $this->assertTrue(WebPushEndpoint::allowed('https://wns2-par02p.notify.windows.com/w/?token=abc'));
        foreach (['http://fcm.googleapis.com/x', 'https://127.0.0.1/x', 'https://169.254.169.254/latest', 'https://evil.com/fcm.googleapis.com', 'https://fcm.googleapis.com.evil.com/x', 'https://fcm.googleapis.com:8443/x', 'https://u@fcm.googleapis.com/x'] as $url) {
            $this->assertFalse(WebPushEndpoint::allowed($url), $url);
        }

        $user = User::factory()->create();
        $topic = Topic::factory()->for($user, 'owner')->create();
        $this->actingAs($user)->putJson(route('topics.web-push', $topic), [
            'subscription' => ['endpoint' => 'http://redis:6379/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']],
            'enabled' => true,
        ])->assertJsonValidationErrors('subscription.endpoint');
    }

    public function test_a_device_token_cannot_be_reached_by_unlimited_relay_clients(): void
    {
        $clients = collect(range(1, RelayToken::MAX_CLIENTS_PER_TOKEN + 1))->map(fn ($i) => RelayClient::register(['name' => 'I'.$i, 'url' => 'https://i'.$i.'.test', 'email' => 'i'.$i.'@x.test'])[0]);
        $hash = RelayToken::hash('ios', str_repeat('AB', 32));
        $this->assertSame(RelayToken::hash('ios', str_repeat('ab', 32)), $hash);

        foreach ($clients->take(RelayToken::MAX_CLIENTS_PER_TOKEN) as $client) {
            $this->assertCount(0, RelayToken::claim($client, [$hash]));
        }
        // Instances already bound keep pushing; a new one is refused.
        $this->assertCount(0, RelayToken::claim($clients->first(), [$hash]));
        $this->assertSame([$hash], RelayToken::claim($clients->last(), [$hash])->all());
    }

    public function test_an_open_session_alone_cannot_take_the_account_over(): void
    {
        $user = $this->withTwoFactor(User::factory()->create(['email' => 'jane@example.com']));
        $secret = $user->two_factor_secret;

        // 2FA cannot be reset without disabling it (password).
        $this->actingAs($user)->post('/profile/two-factor')->assertRedirect();
        $this->assertSame($secret, $user->fresh()->two_factor_secret);
        $this->assertTrue($user->fresh()->hasTwoFactor());

        $this->actingAs($user)->post(route('two-factor.recovery-codes'))->assertSessionHasErrors('recovery_password');
        $this->actingAs($user)->post(route('two-factor.recovery-codes'), ['recovery_password' => 'password'])->assertSessionHas('recovery_codes');

        // The email needs the password.
        $this->actingAs($user)->put(route('profile.update'), ['name' => 'Jane', 'email' => 'evil@example.com'])->assertSessionHasErrors('email_password');
        $this->assertSame('jane@example.com', $user->fresh()->email);
        $this->actingAs($user)->put(route('profile.update'), ['name' => 'Jane B', 'email' => 'jane@example.com'])->assertSessionHasNoErrors();
        $this->actingAs($user)->put(route('profile.update'), ['name' => 'Jane', 'email' => 'jane2@example.com', 'email_password' => 'password'])->assertSessionHasNoErrors();
        $this->assertSame('jane2@example.com', $user->fresh()->email);
    }

    public function test_changing_the_password_closes_the_other_sessions(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'other-browser', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($user)->put(route('profile.password'), ['current_password' => 'password', 'password' => 'N3w-Long-Password!', 'password_confirmation' => 'N3w-Long-Password!'])->assertSessionHasNoErrors();

        $this->assertFalse(DB::table('sessions')->where('id', 'other-browser')->exists());
    }

    public function test_sso_needs_a_verified_email_for_existing_accounts_and_keeps_2fa(): void
    {
        User::factory()->admin()->create();
        $jane = $this->withTwoFactor(User::factory()->create(['email' => 'jane@telkine.net']));
        User::factory()->create(['email' => 'boss@telkine.net']);
        app(Settings::class)->set(['sso.enabled' => true, 'sso.issuer' => 'https://id.example.com', 'sso.client_id' => 'tocsin', 'sso.client_secret' => 'secret']);
        Http::fake([
            'https://id.example.com/.well-known/openid-configuration' => Http::response(['authorization_endpoint' => 'https://id.example.com/authorize', 'token_endpoint' => 'https://id.example.com/token', 'userinfo_endpoint' => 'https://id.example.com/userinfo']),
            'https://id.example.com/token' => Http::response(['access_token' => 'at']),
            'https://id.example.com/userinfo' => Http::sequence()
                ->push(['email' => 'boss@telkine.net']) // claim missing
                ->push(['email' => 'jane@telkine.net', 'email_verified' => true]),
        ]);

        $this->get('/auth/sso');
        $this->get('/auth/sso/callback?code=abc&state='.session('sso.state'))->assertRedirect('/login');
        $this->assertGuest();

        $this->get('/auth/sso');
        $this->get('/auth/sso/callback?code=abc&state='.session('sso.state'))->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->post('/two-factor-challenge', ['code' => (new Google2FA)->getCurrentOtp($jane->two_factor_secret)])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($jane);
    }

    public function test_reset_links_use_the_configured_address_whatever_the_host(): void
    {
        Notification::fake();
        app(Settings::class)->set(['app.url' => 'https://push.example.com']);
        $user = User::factory()->create(['email' => 'jane@example.com']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeaders(['Host' => 'evil.test', 'X-Forwarded-Host' => 'evil.test', 'X-Forwarded-For' => '1.2.3.4'])
            ->post('/forgot-password', ['email' => 'jane@example.com']);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;
            $this->assertStringStartsWith('https://push.example.com/reset-password/', $url);

            return true;
        });

        // A forwarded address is only believed from a private proxy.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->withHeaders(['X-Forwarded-For' => '1.2.3.4'])
            ->get('/up');
        $this->assertSame('203.0.113.9', request()->ip());
    }

    public function test_private_topics_answer_like_missing_ones_and_tokens_list_their_topics(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        Topic::factory()->for($owner, 'owner')->create(['name' => 'secret-ops']);
        Topic::factory()->for($owner, 'owner')->create(['name' => 'billing']);

        $missing = $this->actingAs($stranger)->getJson('/does-not-exist/json');
        $private = $this->actingAs($stranger)->getJson('/secret-ops/json');
        $this->assertSame($missing->status(), $private->status());
        $this->assertSame($missing->json(), $private->json());

        $token = $owner->createToken('one', ['topic:billing'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/topics')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'billing');
    }

    public function test_https_headers_and_redirects_follow_the_instance_url(): void
    {
        User::factory()->create();
        Installation::flush();
        app(Settings::class)->set(['app.url' => 'https://push.example.com']);

        $this->get('http://push.example.com/dashboard')->assertRedirect('https://push.example.com/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000')
            ->assertHeader('Content-Security-Policy');
        $this->get('http://10.0.0.9:8080/dashboard')->assertRedirect()->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_the_ntfy_api_behind_a_plain_http_proxy_builds_and_accepts_https_links(): void
    {
        User::factory()->create();
        Installation::flush();
        app(Settings::class)->set(['app.url' => 'https://push.example.com']);

        $this->get('http://push.example.com/zzqtest')->assertRedirect('https://push.example.com/topics/zzqtest');

        // A signed acknowledgement link, built on https, reaches Tocsin over http.
        $message = Message::factory()->for(Topic::factory())->create(['requires_ack' => true, 'dispatched_at' => now()]);
        URL::forceRootUrl('https://push.example.com');
        $url = $message->acknowledgeUrl();
        URL::forceRootUrl(null);
        $this->assertStringStartsWith('https://', $url);

        $this->postJson(str_replace('https://', 'http://', $url))->assertOk()->assertJsonPath('acknowledged', true);
        $this->assertNotNull($message->fresh()->acknowledged_at);
    }
}

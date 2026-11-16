<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Topic;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithTwoFactor(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->forceFill([
            'two_factor_secret' => (new Google2FA)->generateSecretKey(32),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['aaaaa-bbbbb'],
        ])->save();

        return $user;
    }

    protected function otp(User $user): string
    {
        return (new Google2FA)->getCurrentOtp($user->two_factor_secret);
    }

    public function test_two_factor_can_be_enabled_from_the_profile(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->post('/profile/two-factor')->assertRedirect();
        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertFalse($user->hasTwoFactor());
        $this->actingAs($user)->get('/profile')->assertSee('<svg', false);

        $this->actingAs($user)->post('/profile/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->actingAs($user)->post('/profile/two-factor/confirm', ['code' => $this->otp($user)])->assertSessionHas('recovery_codes');

        $this->assertTrue($user->fresh()->hasTwoFactor());
        $this->assertCount(8, $user->fresh()->two_factor_recovery_codes);
        $this->assertTrue(AuditLog::where('action', 'auth.2fa_enabled')->exists());
    }

    public function test_login_asks_for_the_code(): void
    {
        $user = $this->userWithTwoFactor(['email' => 'jane@example.com']);

        $this->post('/login', ['email' => 'jane@example.com', 'password' => 'password'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['code' => $this->otp($user)])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_recovery_codes_work_once(): void
    {
        $user = $this->userWithTwoFactor(['email' => 'jane@example.com']);

        $this->post('/login', ['email' => 'jane@example.com', 'password' => 'password']);
        $this->post('/two-factor-challenge', ['recovery_code' => 'aaaaa-bbbbb'])->assertRedirect(route('dashboard'));
        $this->assertSame([], $user->fresh()->two_factor_recovery_codes);
    }

    public function test_the_mobile_login_requires_the_code(): void
    {
        $user = $this->userWithTwoFactor(['email' => 'jane@example.com']);

        $this->postJson('/api/v1/auth/token', ['email' => 'jane@example.com', 'password' => 'password'])->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/auth/token', ['email' => 'jane@example.com', 'password' => 'password', 'code' => '000000'])->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/auth/token', ['email' => 'jane@example.com', 'password' => 'password', 'code' => $this->otp($user)])->assertCreated();
    }

    public function test_tokens_can_be_limited_to_topics_and_expire(): void
    {
        $user = User::factory()->create();
        $allowed = Topic::factory()->for($user, 'owner')->create(['name' => 'allowed']);
        Topic::factory()->for($user, 'owner')->create(['name' => 'other']);

        $this->actingAs($user)->post('/profile/tokens', ['name' => 'Script', 'topics' => ['allowed'], 'expires' => '30'])->assertSessionHas('plain_token');
        $plain = session('plain_token');
        $token = $user->tokens()->sole();
        $this->assertSame(['topic:allowed'], $token->abilities);
        $this->assertTrue($token->expires_at->isFuture());

        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->postJson('/api/v1/topics/allowed/messages', ['message' => 'ok'])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->postJson('/api/v1/topics/other/messages', ['message' => 'no'])->assertForbidden();

        $token->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->postJson('/api/v1/topics/allowed/messages', ['message' => 'expired'])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Basic '.base64_encode(':'.$plain))->postJson('/api/v1/topics/allowed/messages', ['message' => 'expired'])->assertUnauthorized();
        $this->assertSame(1, $allowed->messages()->count());
    }

    public function test_actions_are_audited_and_visible_to_admins(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'admin@example.com']);

        $this->post('/login', ['email' => 'admin@example.com', 'password' => 'wrong']);
        $this->post('/login', ['email' => 'admin@example.com', 'password' => 'password']);
        $this->actingAs($admin)->post('/topics', ['name' => 'audited', 'visibility' => 'private']);

        $this->assertEqualsCanonicalizing(['auth.login_failed', 'auth.login', 'topic.created'], AuditLog::pluck('action')->all());

        $this->actingAs($admin)->get('/admin/audit')->assertOk()
            ->assertSee('Failed sign-in for', false)
            ->assertSee('created the topic', false)
            ->assertSee('audited')
            ->assertSee('Private');
        $this->actingAs($admin)->get('/admin/audit?action=topic')->assertOk()->assertDontSee('Failed sign-in for', false);
        $this->actingAs(User::factory()->create())->get('/admin/audit')->assertForbidden();
    }

    public function test_the_visitor_address_is_read_from_cloudflare_only_when_it_comes_from_cloudflare(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        // Through Cloudflare: the edge server's address is replaced by the visitor's.
        $this->withServerVariables(['REMOTE_ADDR' => '172.71.126.61'])
            ->withHeader('CF-Connecting-IP', '41.202.10.20')
            ->post('/login', ['email' => 'ada@example.com', 'password' => 'password']);
        $this->assertSame('41.202.10.20', AuditLog::where('action', 'auth.login')->value('ip_address'));

        // Straight to the server: the header is forged and ignored.
        auth()->logout();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('CF-Connecting-IP', '1.2.3.4')
            ->post('/login', ['email' => 'ada@example.com', 'password' => 'wrong']);
        $this->assertSame('203.0.113.7', AuditLog::where('action', 'auth.login_failed')->value('ip_address'));
    }

    public function test_two_factor_can_be_required_for_admins(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put('/admin/settings/security', ['require_2fa_admins' => '1'])->assertSessionHasErrors('require_2fa_admins');

        $secured = $this->userWithTwoFactor(['role' => 'admin']);
        $this->actingAs($secured)->put('/admin/settings/security', ['require_2fa_admins' => '1'])->assertSessionHasNoErrors();

        $this->actingAs($admin)->get('/admin/users')->assertRedirect(route('profile.edit').'#security');
        $this->actingAs($secured)->get('/admin/users')->assertOk();
    }

    public function test_single_sign_on_with_openid_connect(): void
    {
        User::factory()->admin()->create();
        app(Settings::class)->set([
            'sso.enabled' => true,
            'sso.label' => 'Telkine SSO',
            'sso.issuer' => 'https://id.example.com',
            'sso.client_id' => 'tocsin',
            'sso.client_secret' => 'secret',
            'sso.auto_create' => true,
            'sso.allowed_domains' => 'telkine.net',
        ]);

        Http::fake([
            'https://id.example.com/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => 'https://id.example.com/authorize',
                'token_endpoint' => 'https://id.example.com/token',
                'userinfo_endpoint' => 'https://id.example.com/userinfo',
            ]),
            'https://id.example.com/token' => Http::response(['access_token' => 'at']),
            'https://id.example.com/userinfo' => Http::sequence()
                ->push(['email' => 'ama@telkine.net', 'email_verified' => true, 'name' => 'Ama Koné'])
                ->push(['email' => 'eve@evil.test', 'email_verified' => true]),
        ]);

        $this->get('/login')->assertSee('Continue with Telkine SSO');

        $redirect = $this->get('/auth/sso')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://id.example.com/authorize?', $redirect);
        parse_str(parse_url($redirect, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);

        $this->get('/auth/sso/callback?code=abc&state=wrong')->assertRedirect('/login');
        $this->assertGuest();

        $this->get('/auth/sso');
        $state = session('sso.state');
        $this->get('/auth/sso/callback?code=abc&state='.$state)->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertSame('Ama Koné', User::where('email', 'ama@telkine.net')->sole()->name);

        auth()->logout();
        $this->get('/auth/sso');
        $this->get('/auth/sso/callback?code=abc&state='.session('sso.state'))->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertFalse(User::where('email', 'eve@evil.test')->exists());
    }
}

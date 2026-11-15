<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_users_cannot_access_administration(): void
    {
        User::factory()->admin()->create();
        $user = User::factory()->create();

        foreach (['/admin/users', '/admin/settings'] as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    }

    public function test_admin_pages_render(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create();

        $this->actingAs($admin);

        foreach (['/admin/users', "/admin/users/{$other->id}/edit", '/admin/settings', '/admin/settings/mail', '/admin/settings/realtime'] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get('/admin/invitations')->assertRedirect('/admin/users');

        $this->get('/admin/settings/unknown')->assertNotFound();
    }

    public function test_adding_a_user_sends_an_activation_link(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/invitations', [
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
            'role' => 'admin',
            'send_email' => '1',
        ])->assertRedirect('/admin/users')->assertSessionHas('invitation_url');

        $invitation = Invitation::sole();
        $this->assertSame('Grace Hopper', $invitation->name);
        $this->assertSame(Role::Admin, $invitation->role);
        Notification::assertSentOnDemand(InvitationNotification::class);

        // The pending person is listed with the users.
        $this->actingAs($admin)->get('/admin/users')->assertSee('Grace Hopper')->assertSee('grace@example.com');
    }

    public function test_the_email_is_optional(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/invitations', ['name' => 'Ada', 'email' => 'ada@example.com', 'role' => 'user'])
            ->assertSessionHas('invitation_url');

        Notification::assertNothingSent();
    }

    public function test_resending_replaces_the_link(): void
    {
        $admin = User::factory()->admin()->create();
        [$invitation, $token] = Invitation::issue('ada@example.com', Role::User, $admin, 'Ada');

        $this->actingAs($admin)->post("/admin/invitations/{$invitation->id}/resend")->assertSessionHas('invitation_url');

        $this->assertNull(Invitation::findPendingByToken($token));
        $this->assertSame('Ada', Invitation::sole()->name);
    }

    public function test_the_activation_page_is_prefilled(): void
    {
        User::factory()->admin()->create();
        [, $token] = Invitation::issue('ada@example.com', Role::User, null, 'Ada Lovelace');

        $this->get(route('invitations.show', $token))->assertOk()->assertSee('Ada Lovelace')->assertSee('Welcome, Ada');
    }

    public function test_cannot_invite_an_existing_user(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/invitations', ['name' => 'X', 'email' => $admin->email, 'role' => 'user'])
            ->assertSessionHasErrors('email');
    }

    public function test_admins_can_disable_users_and_revoke_their_tokens(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $user->createToken('phone');

        $this->actingAs($admin)->put("/admin/users/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'role' => 'user',
        ])->assertRedirect('/admin/users');

        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_admins_cannot_demote_or_delete_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put("/admin/users/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => 'user',
            'is_active' => '1',
        ])->assertSessionHasErrors('role');

        $this->actingAs($admin)->delete("/admin/users/{$admin->id}")->assertStatus(422);
        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_settings_are_saved_from_the_interface(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/admin/settings/general', [
            'app_name' => 'Pager',
            'app_url' => 'https://pager.example.com',
            'app_locale' => 'fr',
            'retention_days' => 14,
            'max_length' => 2000,
            'registration_enabled' => '1',
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->put('/admin/settings/mail', [
            'mailer' => 'smtp',
            'host' => 'smtp.example.com',
            'port' => 465,
            'scheme' => 'smtps',
            'username' => 'pager',
            'password' => 's3cret',
            'from_address' => 'pager@example.com',
        ])->assertSessionHasNoErrors();

        $settings = app(Settings::class);
        $settings->refresh();
        $this->assertSame('Pager', $settings->string('app.name'));
        $this->assertTrue($settings->boolean('registration.enabled'));
        $this->assertFalse($settings->boolean('topics.users_can_create'));
        $this->assertSame(14, $settings->integer('messages.retention_days'));
        $this->assertSame('s3cret', $settings->get('mail.password'));
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));

        // The SMTP password is encrypted at rest.
        $this->assertStringNotContainsString('s3cret', Setting::find('mail.password')->value);
    }

    public function test_resend_can_be_used_to_send_emails(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        // The API key is required the first time and must look like a Resend key.
        $this->put('/admin/settings/mail', ['mailer' => 'resend', 'from_address' => 'pager@example.com'])
            ->assertSessionHasErrors('resend_key');
        $this->put('/admin/settings/mail', ['mailer' => 'resend', 'resend_key' => 'nope', 'from_address' => 'pager@example.com'])
            ->assertSessionHasErrors('resend_key');
        $this->put('/admin/settings/mail', ['mailer' => 'resend', 'resend_key' => 're_test_123'])
            ->assertSessionHasErrors('from_address');

        $this->put('/admin/settings/mail', [
            'mailer' => 'resend',
            'resend_key' => 're_test_123',
            'from_address' => 'pager@example.com',
        ])->assertSessionHasNoErrors();

        // Saving again without a key keeps the stored one.
        $this->put('/admin/settings/mail', ['mailer' => 'resend', 'from_address' => 'alerts@example.com'])
            ->assertSessionHasNoErrors();

        $settings = app(Settings::class);
        $settings->refresh();
        $settings->applyToConfig();

        $this->assertSame('re_test_123', $settings->get('mail.resend_key'));
        $this->assertStringNotContainsString('re_test_123', Setting::find('mail.resend_key')->value);
        $this->assertSame('resend', config('mail.default'));
        $this->assertSame('re_test_123', config('services.resend.key'));
        $this->assertSame('alerts@example.com', config('mail.from.address'));
        $this->assertInstanceOf(
            ResendTransport::class,
            app('mail.manager')->mailer('resend')->getSymfonyTransport(),
        );

        $this->get('/admin/users')->assertViewHas('mailConfigured', true);
    }

    public function test_apns_credentials_are_configured_from_the_interface(): void
    {
        $admin = User::factory()->admin()->create();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);

        $this->actingAs($admin)->put('/admin/settings/push', [
            'team_id' => 'TEAM123456',
            'key_id' => 'KEY1234567',
            'bundle_id' => 'com.example.tocsin',
            'private_key' => 'not a key',
        ])->assertSessionHasErrors('private_key');

        $this->actingAs($admin)->put('/admin/settings/push', [
            'team_id' => 'team123456',
            'key_id' => 'KEY1234567',
            'bundle_id' => 'com.example.tocsin',
            'private_key_file' => UploadedFile::fake()->createWithContent('AuthKey_KEY1234567.p8', $pem),
        ])->assertSessionHasNoErrors();

        $settings = app(Settings::class);
        $settings->refresh();
        $this->assertTrue($settings->apnsConfigured());
        $this->assertSame('TEAM123456', $settings->get('apns.team_id'));
        $this->assertStringNotContainsString('PRIVATE KEY', Setting::find('apns.private_key')->value);

        // Saving again without a key keeps the stored one.
        $this->actingAs($admin)->put('/admin/settings/push', [
            'team_id' => 'TEAM123456',
            'key_id' => 'KEY7654321',
            'bundle_id' => 'com.example.tocsin',
        ])->assertSessionHasNoErrors();
        $settings->refresh();
        $this->assertSame(trim($pem), $settings->get('apns.private_key'));
    }

    public function test_test_push_requires_a_registered_device(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/settings/push/test')->assertSessionHasErrors('alert');
    }
}

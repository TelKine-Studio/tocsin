<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_log_in_and_out(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);

        $this->get('/login')->assertOk();

        $this->post('/login', ['email' => 'Jane@Example.com', 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $this->post('/login', ['email' => 'jane@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_disabled_users_cannot_log_in(): void
    {
        User::factory()->disabled()->create(['email' => 'jane@example.com']);

        $this->post('/login', ['email' => 'jane@example.com', 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_registration_is_disabled_by_default(): void
    {
        User::factory()->admin()->create();

        $this->get('/register')->assertNotFound();
    }

    public function test_registration_can_be_enabled(): void
    {
        User::factory()->admin()->create();
        app(Settings::class)->set(['registration.enabled' => true]);

        $this->post('/register', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertRedirect(route('dashboard'));

        $this->assertSame(Role::User, User::where('email', 'new@example.com')->sole()->role);
    }

    public function test_an_invitation_creates_an_account(): void
    {
        $admin = User::factory()->admin()->create();
        [$invitation, $token] = Invitation::issue('guest@example.com', Role::User, $admin);

        $this->get(route('invitations.show', $token))->assertOk()->assertSee('guest@example.com');

        $this->post(route('invitations.accept', $token), [
            'name' => 'Guest',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertNotNull($invitation->fresh()->accepted_at);

        // The link can only be used once.
        auth()->logout();
        $this->get(route('invitations.show', $token))->assertSee('no longer valid');
    }

    public function test_expired_invitations_are_rejected(): void
    {
        User::factory()->admin()->create();
        [$invitation, $token] = Invitation::issue('guest@example.com', Role::User, null);
        $invitation->update(['expires_at' => now()->subMinute()]);

        $this->post(route('invitations.accept', $token), [
            'name' => 'Guest',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertNotFound();
    }
}

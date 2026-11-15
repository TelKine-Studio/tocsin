<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_redirects_to_setup_until_installed(): void
    {
        $this->get('/dashboard')->assertRedirect(route('setup.show'));
        $this->get('/login')->assertRedirect(route('setup.show'));
        $this->get('/setup')->assertOk()->assertSee('Welcome to Tocsin');
    }

    public function test_setup_creates_the_first_administrator(): void
    {
        $response = $this->post('/setup', [
            'site_name' => 'My Alerts',
            'app_url' => 'https://alerts.example.com/',
            'locale' => 'fr',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertRedirect(route('dashboard'));

        $user = User::sole();
        $this->assertSame(Role::Admin, $user->role);
        $this->assertAuthenticatedAs($user);

        $settings = app(Settings::class);
        $settings->refresh();
        $this->assertSame('My Alerts', $settings->string('app.name'));
        $this->assertSame('https://alerts.example.com', $settings->get('app.url'));
        $this->assertNotEmpty($settings->get('realtime.key'));
    }

    public function test_setup_is_closed_once_installed(): void
    {
        User::factory()->admin()->create();

        $this->get('/setup')->assertRedirect(route('login'));
        $this->post('/setup', [])->assertRedirect(route('login'));
    }
}

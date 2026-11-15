<?php

namespace Tests\Feature;

use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_topic_url_opened_in_a_browser_goes_to_the_topic_page(): void
    {
        $user = User::factory()->create();
        Topic::factory()->for($user, 'owner')->create(['name' => 'backups']);

        $this->get('/backups')->assertRedirect('/topics/backups');
        $this->actingAs($user)->get('/topics/backups')->assertOk();

        // Health check and app pages keep their own routes.
        $this->get('/up')->assertOk();
        $this->get('/login')->assertRedirect();
    }

    public function test_error_pages_follow_the_language_of_the_visitor(): void
    {
        // A signed-in user: their language.
        $user = User::factory()->create(['locale' => 'fr']);
        $this->actingAs($user)->get('/topics/missing')->assertNotFound()->assertSee('Page introuvable');
        auth()->logout();

        // Without session, the language of the browser.
        $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')->get('/topics/a/b/c/d')->assertSee('Page introuvable');
        $this->withHeader('Accept-Language', 'en-US,en;q=0.9')->get('/topics/a/b/c/d')->assertSee('Page not found');
    }

    public function test_errors_use_the_tocsin_pages(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/topics/does-not-exist')
            ->assertNotFound()
            ->assertSee(__('Page not found'))
            ->assertSee(__('Back to home'));

        $this->get('/m/backups/extra/segments')->assertNotFound()->assertSee(__('Error :code', ['code' => '404']));

        // The API keeps answering in JSON.
        $this->get('/api/v1/auth/pair')->assertStatus(405)->assertJsonStructure(['message']);
        $this->get('/topics/backups/pairing')->assertStatus(405)->assertSee(__('This address does not open in a browser'));
    }
}

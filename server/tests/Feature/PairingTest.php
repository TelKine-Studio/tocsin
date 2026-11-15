<?php

namespace Tests\Feature;

use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Add on mobile" QR code: subscribe and sign the app in without typing. */
class PairingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_private_topic_qr_code_signs_the_app_in_once(): void
    {
        $user = User::factory()->create();
        $topic = Topic::factory()->for($user, 'owner')->create(['name' => 'backups', 'visibility' => 'private']);

        $response = $this->actingAs($user)->postJson("/topics/{$topic->name}/pairing")
            ->assertOk()
            ->assertJsonPath('signs_in', true);
        $this->assertStringContainsString('<svg', $response->json('svg'));

        $url = $response->json('url');
        $this->assertStringStartsWith(url('/m/backups?code='), $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        // The landing page offers to open the app.
        auth()->logout();
        $this->get(parse_url($url, PHP_URL_PATH).'?code='.$query['code'])
            ->assertOk()
            ->assertSee('tocsin://pair?url=', false);

        $this->postJson('/api/v1/auth/pair', ['code' => $query['code'], 'device_name' => 'Tocsin · iPhone'])
            ->assertCreated()
            ->assertJsonPath('topic', 'backups')
            ->assertJsonPath('user.email', $user->email);
        $this->assertSame('Tocsin · iPhone', $user->tokens()->sole()->name);

        // A code works once.
        $this->postJson('/api/v1/auth/pair', ['code' => $query['code']])->assertUnprocessable();
    }

    public function test_codes_expire_and_public_topics_need_none(): void
    {
        $user = User::factory()->create();
        $private = Topic::factory()->for($user, 'owner')->create(['name' => 'ops', 'visibility' => 'private']);
        $public = Topic::factory()->for($user, 'owner')->create(['name' => 'status', 'visibility' => 'public']);

        $url = $this->actingAs($user)->postJson("/topics/{$private->name}/pairing")->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/auth/pair', ['code' => $query['code']])->assertUnprocessable();

        $this->actingAs($user)->postJson("/topics/{$public->name}/pairing")
            ->assertOk()
            ->assertJsonPath('signs_in', false)
            ->assertJsonPath('url', url('/m/status'));
    }

    public function test_only_people_who_see_the_topic_get_a_code(): void
    {
        $owner = User::factory()->create();
        $topic = Topic::factory()->for($owner, 'owner')->create(['name' => 'secret', 'visibility' => 'private']);

        $this->actingAs(User::factory()->create())->postJson("/topics/{$topic->name}/pairing")->assertForbidden();
    }
}

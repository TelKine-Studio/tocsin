<?php

namespace Tests\Feature;

use App\Enums\TopicVisibility;
use App\Models\Message;
use App\Models\Topic;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TopicManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_render(): void
    {
        $admin = User::factory()->admin()->create();
        $topic = Topic::factory()->for($admin, 'owner')->create();
        Message::factory()->for($topic)->count(3)->create();

        $this->actingAs($admin);

        foreach (['/dashboard', '/topics', '/topics/create', "/topics/{$topic->name}", "/topics/{$topic->name}/edit", '/profile'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_a_user_can_create_a_topic(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/topics', [
            'name' => 'backups',
            'display_name' => 'Backups',
            'visibility' => 'private',
        ])->assertRedirect('/topics/backups');

        $topic = Topic::where('name', 'backups')->sole();
        $this->assertTrue($topic->owner->is($user));
        $this->assertSame(TopicVisibility::Private, $topic->visibility);
    }

    public function test_topic_names_are_validated(): void
    {
        $user = User::factory()->create();
        Topic::factory()->create(['name' => 'taken']);

        foreach (['Upper', 'with space', 'taken', 'create', '-start', str_repeat('a', 65)] as $name) {
            $this->actingAs($user)->post('/topics', ['name' => $name, 'visibility' => 'public'])
                ->assertSessionHasErrors('name');
        }
    }

    public function test_topic_creation_can_be_restricted_to_admins(): void
    {
        app(Settings::class)->set(['topics.users_can_create' => false]);

        $this->actingAs(User::factory()->create())->get('/topics/create')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/topics/create')->assertOk();
    }

    public function test_private_topics_are_hidden_from_other_users(): void
    {
        $topic = Topic::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get("/topics/{$topic->name}")->assertForbidden();
        $this->actingAs($stranger)->get('/topics')->assertDontSee($topic->name);
    }

    public function test_owners_can_grant_access_to_members(): void
    {
        $topic = Topic::factory()->create();
        $member = User::factory()->create();

        $this->actingAs($topic->owner)
            ->post("/topics/{$topic->name}/members", ['email' => $member->email, 'can_write' => '1'])
            ->assertSessionHasNoErrors();

        $topic->load('members');
        $this->assertTrue($topic->canRead($member));
        $this->assertTrue($topic->canWrite($member));

        $this->actingAs($topic->owner)->put("/topics/{$topic->name}/members/{$member->id}", ['can_write' => '0']);
        $this->assertFalse($topic->fresh()->canWrite($member));

        $this->actingAs($topic->owner)->delete("/topics/{$topic->name}/members/{$member->id}");
        $this->assertFalse($topic->fresh()->canRead($member));
    }

    public function test_members_cannot_manage_the_topic(): void
    {
        $topic = Topic::factory()->create();
        $member = User::factory()->create();
        $topic->members()->attach($member, ['can_read' => true, 'can_write' => true]);

        $this->actingAs($member)->get("/topics/{$topic->name}")->assertOk();
        $this->actingAs($member)->get("/topics/{$topic->name}/edit")->assertForbidden();
        $this->actingAs($member)->delete("/topics/{$topic->name}")->assertForbidden();
    }

    public function test_publishing_from_the_web_interface(): void
    {
        $topic = Topic::factory()->create();

        $this->actingAs($topic->owner)->post("/topics/{$topic->name}/messages", [
            'title' => 'Hello',
            'body' => 'World',
            'priority' => '5',
            'tags' => 'a, b',
        ])->assertRedirect("/topics/{$topic->name}");

        $message = $topic->messages()->sole();
        $this->assertSame('World', $message->body);
        $this->assertSame(['a', 'b'], $message->tags);
        $this->assertSame(5, $message->priority->value);
        $this->assertNotNull($topic->fresh()->last_message_at);
    }

    public function test_deleting_a_topic_deletes_its_messages(): void
    {
        $topic = Topic::factory()->create();
        Message::factory()->for($topic)->count(2)->create();

        $this->actingAs($topic->owner)->delete("/topics/{$topic->name}")->assertRedirect('/topics');

        $this->assertSame(0, Message::count());
    }

    public function test_old_messages_are_pruned(): void
    {
        app(Settings::class)->set(['messages.retention_days' => 7]);
        $topic = Topic::factory()->create();
        Message::factory()->for($topic)->create(['created_at' => now()->subDays(8)]);
        Message::factory()->for($topic)->create(['created_at' => now()->subDays(6)]);

        $this->artisan('model:prune', ['--model' => [Message::class]])->assertSuccessful();

        $this->assertSame(1, Message::count());
    }

    public function test_publishing_a_file_and_scheduling_from_the_web_interface(): void
    {
        Storage::fake('local');
        $topic = Topic::factory()->create();

        $this->actingAs($topic->owner)->post("/topics/{$topic->name}/messages", [
            'body' => 'Graph attached',
            'attachment' => UploadedFile::fake()->image('graph.png'),
        ])->assertSessionHasNoErrors();

        $message = $topic->messages()->sole();
        $this->assertSame('graph.png', $message->attachment_name);
        $this->assertNotNull($message->attachmentUrl());

        $this->actingAs($topic->owner)->post("/topics/{$topic->name}/messages", [
            'body' => 'Later',
            'send_at' => now()->addHours(2)->format('Y-m-d\\TH:i'),
            'timezone' => 'UTC',
        ])->assertSessionHasNoErrors();

        $scheduled = $topic->messages()->whereNull('dispatched_at')->sole();
        $this->assertTrue($scheduled->scheduled_at->isFuture());
        $this->actingAs($topic->owner)->get("/topics/{$topic->name}")->assertSee('Later')->assertSee('Graph attached');
    }
}

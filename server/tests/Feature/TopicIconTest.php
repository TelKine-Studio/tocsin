<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Topic;
use App\Push\PushPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TopicIconTest extends TestCase
{
    use RefreshDatabase;

    protected function upload(string $fixture, string $name = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, file_get_contents(base_path('tests/fixtures/'.$fixture)));
    }

    public function test_owners_set_serve_and_remove_a_logo(): void
    {
        Storage::fake('local');
        $topic = Topic::factory()->public()->create(['name' => 'alerts']);

        $this->actingAs($topic->owner)->put('/topics/alerts', [
            'visibility' => 'public',
            'icon' => $this->upload('logo.png'),
        ])->assertSessionHasNoErrors();

        $topic->refresh();
        $this->assertNotNull($topic->icon_path);
        Storage::disk('local')->assertExists($topic->icon_path);

        // Public: the notification extensions download it without an account.
        auth()->logout();
        $this->get($topic->iconUrl())->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->getJson('/api/v1/topics/alerts')->assertJsonPath('data.icon', $topic->iconUrl());

        // A new logo replaces the previous file.
        $old = $topic->icon_path;
        $this->actingAs($topic->owner)->put('/topics/alerts', ['visibility' => 'public', 'icon' => $this->upload('logo.png')]);
        Storage::disk('local')->assertMissing($old);

        $this->actingAs($topic->owner)->put('/topics/alerts', ['visibility' => 'public', 'remove_icon' => '1']);
        $this->assertNull($topic->fresh()->icon_path);
        $this->get('/v1/topics/alerts/icon')->assertNotFound();
    }

    public function test_invalid_logos_are_rejected(): void
    {
        Storage::fake('local');
        $topic = Topic::factory()->create(['name' => 'alerts']);

        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');
        $this->actingAs($topic->owner)->put('/topics/alerts', ['visibility' => 'private', 'icon' => $svg])->assertSessionHasErrors('icon');
        $this->actingAs($topic->owner)->put('/topics/alerts', ['visibility' => 'private', 'icon' => $this->upload('tiny.png')])->assertSessionHasErrors('icon');

        $this->assertNull($topic->fresh()->icon_path);
    }

    public function test_notifications_carry_the_logo(): void
    {
        $topic = Topic::factory()->create(['name' => 'alerts', 'icon_path' => 'topic-icons/1-abc.png']);
        $message = Message::factory()->for($topic)->create();

        $payload = PushPayload::forMessage($message->load('topic'), 'https://notify.example.com');
        $this->assertSame($topic->iconUrl(), $payload['tocsin']['icon']);
        $this->assertSame(1, $payload['aps']['mutable-content']);
        $this->assertSame($topic->iconUrl(), PushPayload::forFcm($payload)['icon']);

        // X-Icon of a message wins over the topic's logo.
        $custom = Message::factory()->for($topic)->create(['icon_url' => 'https://cdn.example.com/db.png']);
        $this->assertSame('https://cdn.example.com/db.png', PushPayload::forMessage($custom->load('topic'), 'https://n.test')['tocsin']['icon']);
    }
}

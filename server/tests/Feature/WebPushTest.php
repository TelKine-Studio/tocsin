<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Topic;
use App\Models\User;
use App\Push\WebPushSender;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebPushTest extends TestCase
{
    use RefreshDatabase;

    protected function subscription(): array
    {
        return [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.str_repeat('a', 300),
            'keys' => ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg'],
        ];
    }

    public function test_vapid_keys_are_generated_once(): void
    {
        $sender = app(WebPushSender::class);
        $sender->ensureKeys();
        $key = $sender->publicKey();
        $sender->ensureKeys();

        $this->assertNotEmpty($key);
        $this->assertSame($key, app(Settings::class)->get('webpush.public_key'));
    }

    public function test_a_browser_subscribes_and_unsubscribes(): void
    {
        $topic = Topic::factory()->create(['name' => 'alerts']);

        $this->actingAs($topic->owner)->putJson('/topics/alerts/web-push', ['subscription' => $this->subscription(), 'enabled' => true])
            ->assertOk()->assertJson(['enabled' => true]);

        $device = Device::sole();
        $this->assertSame('web', $device->platform);
        $this->assertSame(64, strlen($device->token));

        $this->actingAs($topic->owner)->postJson('/web-push/status', ['endpoint' => $this->subscription()['endpoint']])->assertJson(['topics' => ['alerts']]);

        $this->actingAs($topic->owner)->putJson('/topics/alerts/web-push', ['subscription' => $this->subscription(), 'enabled' => false])
            ->assertJson(['enabled' => false]);

        $this->actingAs(User::factory()->create())->putJson('/topics/alerts/web-push', ['subscription' => $this->subscription(), 'enabled' => true])->assertForbidden();
    }
}

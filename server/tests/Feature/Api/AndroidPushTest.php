<?php

namespace Tests\Feature\Api;

use App\Jobs\SendMessagePush;
use App\Models\Device;
use App\Models\Message;
use App\Models\Setting;
use App\Models\Topic;
use App\Models\User;
use App\Push\PushManager;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AndroidPushTest extends TestCase
{
    use RefreshDatabase;

    protected function serviceAccount(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);

        return [
            'type' => 'service_account',
            'project_id' => 'tocsin-test',
            'client_email' => 'push@tocsin-test.iam.gserviceaccount.com',
            'private_key' => $pem,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ];
    }

    public function test_android_devices_register(): void
    {
        $topic = Topic::factory()->public()->create(['name' => 'news']);

        $this->putJson('/api/v1/devices', [
            'token' => 'fcm-token:APA91b-'.str_repeat('x', 100),
            'platform' => 'android',
            'environment' => 'production',
            'topics' => ['news'],
        ])->assertOk()->assertJsonPath('data.topics', ['news']);

        $this->assertSame('android', Device::sole()->platform);
    }

    public function test_messages_are_sent_through_fcm(): void
    {
        app(Settings::class)->set(['push.mode' => 'direct', 'fcm.service_account' => json_encode($this->serviceAccount())]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test']),
            'https://fcm.googleapis.com/*' => Http::sequence()
                ->push(['name' => 'projects/tocsin-test/messages/1'])
                ->push(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
        ]);

        $topic = Topic::factory()->public()->create(['name' => 'alerts']);
        $ok = Device::create(['token' => 'token-ok', 'platform' => 'android']);
        $gone = Device::create(['token' => 'token-gone', 'platform' => 'android']);
        $topic->devices()->attach([$ok->id, $gone->id]);

        $message = Message::factory()->for($topic)->create(['title' => 'Disk', 'body' => 'Full', 'priority' => 5]);
        (new SendMessagePush($message))->handle(app(PushManager::class), app(Settings::class));

        Http::assertSent(function (Request $request) {
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                [$header, $claims] = explode('.', $request['assertion']);

                return json_decode(base64_decode($header), true)['alg'] === 'RS256'
                    && json_decode(base64_decode(strtr($claims, '-_', '+/')), true)['scope'] === 'https://www.googleapis.com/auth/firebase.messaging';
            }

            return $request->url() === 'https://fcm.googleapis.com/v1/projects/tocsin-test/messages:send'
                && $request->hasHeader('Authorization', 'Bearer ya29.test')
                && $request['message']['data']['title'] === 'Disk'
                && $request['message']['data']['priority'] === '5'
                && $request['message']['android']['priority'] === 'HIGH';
        });

        $this->assertSame('partial', $message->fresh()->push_status);
        $this->assertModelMissing($gone);
    }

    public function test_the_service_account_is_uploaded_from_the_interface(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/admin/settings/push/fcm', [
            'service_account' => UploadedFile::fake()->createWithContent('bad.json', '{"hello":"world"}'),
        ])->assertSessionHasErrors('service_account');

        $this->actingAs($admin)->put('/admin/settings/push/fcm', [
            'service_account' => UploadedFile::fake()->createWithContent('sa.json', json_encode($this->serviceAccount())),
        ])->assertSessionHasNoErrors();

        $settings = app(Settings::class);
        $settings->refresh();
        $this->assertTrue($settings->fcmConfigured());
        $this->assertStringNotContainsString('PRIVATE KEY', Setting::find('fcm.service_account')->value);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\RelayPayment;
use App\Models\Topic;
use App\Models\User;
use App\Notifications\QuotaWarningNotification;
use App\Support\Settings;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Hosted mode: accounts, daily quotas and paid plans (TOCSIN_HOSTED). */
class HostedTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Topic $topic;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tocsin.hosted' => true]);
        Queue::fake();

        User::factory()->admin()->create();
        app(Settings::class)->set([
            'registration.enabled' => true,
            'relay_server.free_daily_limit' => 5,
            'relay_server.currency' => 'XOF',
            'relay_server.pro_price' => 3000,
            'relay_server.pro_daily_limit' => 50000,
            'hosted.free_max_topics' => 2,
            'payments.stripe_secret' => 'sk_test_x',
            'payments.stripe_webhook_secret' => 'whsec_test',
        ]);

        $this->user = User::factory()->create();
        $this->topic = Topic::factory()->for($this->user, 'owner')->create(['name' => 'alerts']);
        $this->token = $this->user->createToken('cli')->plainTextToken;
    }

    protected function publish(string $body = 'hello', array $headers = [])
    {
        return $this->withToken($this->token)->withHeaders($headers)->postJson('/api/v1/topics/alerts/messages', ['message' => $body]);
    }

    public function test_sign_up_requires_a_confirmed_email_to_publish(): void
    {
        Notification::fake();
        auth()->logout();

        $this->post('/register', ['name' => 'Awa', 'email' => 'awa@example.com', 'password' => 'Secret-123456', 'password_confirmation' => 'Secret-123456'])
            ->assertRedirect('/email/verify');

        $awa = User::where('email', 'awa@example.com')->sole();
        Notification::assertSentTo($awa, VerifyEmail::class);

        // Hosted accounts create their own topics, but cannot publish yet.
        $this->actingAs($awa)->post('/topics', ['name' => 'awa-backups', 'visibility' => 'private'])->assertSessionHasNoErrors();
        $this->withToken($awa->createToken('cli')->plainTextToken)
            ->postJson('/api/v1/topics/awa-backups/messages', ['message' => 'hi'])
            ->assertForbidden()
            ->assertJsonPath('error', 'email_not_verified');

        $awa->markEmailAsVerified();
        $this->withToken($awa->createToken('cli2')->plainTextToken)
            ->postJson('/api/v1/topics/awa-backups/messages', ['message' => 'hi'])
            ->assertCreated();
    }

    public function test_the_daily_quota_counts_new_messages_only(): void
    {
        foreach (range(1, 5) as $i) {
            $this->publish("message {$i}")->assertCreated();
        }
        // A grouped repeat sends nothing and is not counted.
        $this->publish('message 5')->assertCreated();
        $this->assertSame(5, $this->user->messagesToday());

        $this->publish('one more')
            ->assertStatus(429)
            ->assertJsonPath('error', 'quota_exceeded')
            ->assertJsonPath('upgrade_url', route('account.plan'));

        // Same answer as ntfy for ntfy clients.
        $this->withToken($this->token)->call('POST', '/alerts', [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'from curl')
            ->assertStatus(429)
            ->assertJsonPath('code', 42908);

        $this->assertSame(5, Message::count());
        $this->assertSame(2, $this->user->usages()->sole()->rejected);

        // The quota resets the next day.
        $this->travel(1)->days();
        $this->publish('new day')->assertCreated();
    }

    public function test_messages_count_against_the_topic_owner_and_admins_are_not_limited(): void
    {
        $this->topic->update(['visibility' => 'public', 'anonymous_publish' => true]);
        $this->postJson('/api/v1/topics/alerts/messages', ['message' => 'anonymous'])->assertCreated();
        $this->assertSame(1, $this->user->messagesToday());

        $admin = User::where('role', 'admin')->sole();
        $adminTopic = Topic::factory()->for($admin, 'owner')->create(['name' => 'ops']);
        $token = $admin->createToken('cli')->plainTextToken;
        foreach (range(1, 7) as $i) {
            $this->withToken($token)->postJson('/api/v1/topics/ops/messages', ['message' => "ops {$i}"])->assertCreated();
        }
        $this->assertSame(7, $adminTopic->messages()->count());
    }

    public function test_owners_are_warned_once_near_the_limit(): void
    {
        Notification::fake();

        foreach (range(1, 5) as $i) {
            $this->publish("message {$i}");
        }

        // 80 % of 5 = the 4th message, once a day.
        Notification::assertSentToTimes($this->user, QuotaWarningNotification::class, 1);
    }

    public function test_free_accounts_have_a_topic_limit(): void
    {
        $this->actingAs($this->user)->post('/topics', ['name' => 'second', 'visibility' => 'private'])->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post('/topics', ['name' => 'third', 'visibility' => 'private'])->assertForbidden();

        // ntfy-style auto creation follows the same rule.
        app(Settings::class)->set(['topics.auto_create' => true]);
        $this->withToken($this->token)->call('POST', '/fourth', [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'hi')->assertForbidden();
        $this->assertFalse(Topic::where('name', 'fourth')->exists());

        $this->user->forceFill(['plan' => 'pro'])->save();
        $this->actingAs($this->user)->post('/topics', ['name' => 'third', 'visibility' => 'private'])->assertSessionHasNoErrors();
    }

    public function test_accounts_buy_a_plan_and_it_expires(): void
    {
        Http::fake(['https://api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/pay/cs_1'])]);

        $this->actingAs($this->user)->get('/account/plan')->assertOk()->assertSee('3 000 XOF');
        $this->actingAs($this->user)->post('/account/plan/checkout', ['plan' => 'pro', 'provider' => 'stripe'])
            ->assertRedirect('https://checkout.stripe.com/pay/cs_1');

        $payment = RelayPayment::sole();
        $this->assertSame($this->user->id, $payment->user_id);
        $this->assertNull($payment->relay_client_id);
        Http::assertSent(fn ($request) => str_contains((string) ($request->data()['line_items[0][price_data][product_data][name]'] ?? ''), $this->user->email));

        // The payment webhook works without relay operator mode.
        $payload = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_1', 'payment_status' => 'paid', 'client_reference_id' => $payment->reference]]]);
        $signature = 't='.time().',v1='.hash_hmac('sha256', time().'.'.$payload, 'whsec_test');
        $this->call('POST', '/api/relay/v1/payments/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $payload)->assertNoContent();

        $user = $this->user->fresh();
        $this->assertSame('pro', $user->plan);
        $this->assertSame(50000, $user->effectiveDailyLimit());
        $this->assertTrue($user->plan_expires_at->between(now()->addDays(29), now()->addDays(31)));
        $this->actingAs($user)->get('/account/plan?payment='.$payment->reference)->assertSee(__('Payment received: your :plan plan is active.', ['plan' => 'Pro']));

        $this->travel(31)->days();
        $this->artisan('tocsin:expire-relay-plans')->assertSuccessful();
        $this->assertSame('free', $user->fresh()->plan);
        $this->assertSame(5, $user->fresh()->effectiveDailyLimit());
    }

    public function test_administrators_see_and_change_plans(): void
    {
        $admin = User::where('role', 'admin')->sole();
        $this->publish('one');

        $this->actingAs($admin)->get('/admin/users')->assertOk()->assertSee('1 / 5');
        $this->actingAs($admin)->get('/admin/settings/relay')->assertOk()->assertSee(__('Free plan: topics per account'));

        $this->actingAs($admin)->put("/admin/users/{$this->user->id}/plan", ['plan' => 'business', 'daily_limit' => 123, 'plan_expires_at' => now()->addMonth()->toDateString()])
            ->assertSessionHasNoErrors();

        $this->assertSame('business', $this->user->fresh()->plan);
        $this->assertSame(123, $this->user->fresh()->effectiveDailyLimit());
    }

    public function test_hosted_screens_do_not_exist_on_self_hosted_instances(): void
    {
        config(['tocsin.hosted' => false]);

        $this->actingAs($this->user)->get('/account/plan')->assertNotFound();
        foreach (range(1, 8) as $i) {
            $this->publish("free {$i}")->assertCreated();
        }
        $this->postJson('/api/relay/v1/payments/stripe')->assertNotFound();
    }
}

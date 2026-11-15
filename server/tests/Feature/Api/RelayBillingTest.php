<?php

namespace Tests\Feature\Api;

use App\Models\RelayClient;
use App\Models\RelayPayment;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RelayBillingTest extends TestCase
{
    use RefreshDatabase;

    protected RelayClient $client;

    protected string $key;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tocsin.relay_operator' => true]);

        app(Settings::class)->set([
            'relay_server.enabled' => true,
            'relay_server.currency' => 'XOF',
            'relay_server.pro_price' => 5000,
            'relay_server.pro_daily_limit' => 50000,
            'payments.cinetpay_site_id' => '123456',
            'payments.cinetpay_api_key' => 'cp_key',
            'payments.stripe_secret' => 'sk_test_x',
            'payments.stripe_webhook_secret' => 'whsec_test',
        ]);

        [$this->client, $this->key] = RelayClient::register(['name' => 'Home', 'url' => 'https://home.test', 'email' => 'me@home.test']);
    }

    public function test_usage_lists_the_paid_plans(): void
    {
        $this->withToken($this->key)->getJson('/api/relay/v1/usage')
            ->assertJsonPath('data.plans.pro.price', 5000)
            ->assertJsonPath('data.plans.pro.daily_limit', 50000)
            ->assertJsonMissingPath('data.plans.business')
            ->assertJsonPath('data.payment_providers', ['cinetpay', 'stripe']);
    }

    public function test_cinetpay_checkout_and_notification(): void
    {
        Http::fake([
            'https://api-checkout.cinetpay.com/v2/payment' => Http::response(['code' => '201', 'data' => ['payment_url' => 'https://checkout.cinetpay.com/pay/abc']]),
            'https://api-checkout.cinetpay.com/v2/payment/check' => Http::response(['code' => '00', 'data' => ['status' => 'ACCEPTED']]),
        ]);

        $this->withToken($this->key)->postJson('/api/relay/v1/checkout', ['plan' => 'pro', 'provider' => 'cinetpay', 'return_url' => 'https://home.test/admin/settings/push'])
            ->assertCreated()
            ->assertJsonPath('data.url', 'https://checkout.cinetpay.com/pay/abc');

        $payment = RelayPayment::sole();
        $this->assertSame(5000, $payment->amount);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api-checkout.cinetpay.com/v2/payment' && $r['transaction_id'] === $payment->reference && $r['site_id'] === '123456');

        $this->post('/api/relay/v1/payments/cinetpay', ['cpm_trans_id' => $payment->reference])->assertNoContent();
        $this->post('/api/relay/v1/payments/cinetpay', ['cpm_trans_id' => $payment->reference])->assertNoContent(); // idempotent

        $client = $this->client->fresh();
        $this->assertSame('pro', $client->plan);
        $this->assertSame(50000, $client->effectiveDailyLimit());
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $client->plan_expires_at->getTimestamp(), 5);
        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_unconfirmed_cinetpay_notifications_are_ignored(): void
    {
        Http::fake(['https://api-checkout.cinetpay.com/v2/payment/check' => Http::response(['data' => ['status' => 'REFUSED']])]);
        $payment = $this->client->payments()->create(['plan' => 'pro', 'amount' => 5000, 'currency' => 'XOF', 'provider' => 'cinetpay']);

        $this->post('/api/relay/v1/payments/cinetpay', ['cpm_trans_id' => $payment->reference]);

        $this->assertSame('free', $this->client->fresh()->plan);
    }

    public function test_stripe_webhook_requires_a_valid_signature(): void
    {
        $payment = $this->client->payments()->create(['plan' => 'pro', 'amount' => 5000, 'currency' => 'XOF', 'provider' => 'stripe']);
        $payload = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_1', 'payment_status' => 'paid', 'client_reference_id' => $payment->reference]]]);
        $sign = fn (string $secret) => 't='.time().',v1='.hash_hmac('sha256', time().'.'.$payload, $secret);

        $this->call('POST', '/api/relay/v1/payments/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sign('wrong')], $payload)->assertStatus(400);
        $this->assertSame('free', $this->client->fresh()->plan);

        $this->call('POST', '/api/relay/v1/payments/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sign('whsec_test')], $payload)->assertNoContent();
        $this->assertSame('pro', $this->client->fresh()->plan);
        $this->assertSame('cs_1', $payment->fresh()->provider_reference);
    }

    public function test_plans_expire(): void
    {
        $this->client->forceFill(['plan' => 'pro', 'daily_limit' => 50000, 'plan_expires_at' => now()->subMinute()])->save();

        $this->artisan('tocsin:expire-relay-plans')->assertSuccessful();

        $this->assertSame('free', $this->client->fresh()->plan);
        $this->assertNull($this->client->fresh()->daily_limit);
    }

    public function test_instances_buy_a_plan_from_their_settings(): void
    {
        $admin = User::factory()->admin()->create();
        app(Settings::class)->set(['push.mode' => 'relay', 'relay.url' => 'https://push.telkine.test', 'relay.key' => 'tcr_x']);
        Http::fake(['https://push.telkine.test/api/relay/v1/checkout' => Http::response(['data' => ['url' => 'https://checkout.cinetpay.com/pay/xyz']], 201)]);

        $this->actingAs($admin)->post('/admin/settings/push/relay/checkout', ['plan' => 'pro', 'provider' => 'cinetpay'])
            ->assertRedirect('https://checkout.cinetpay.com/pay/xyz');

        Http::assertSent(fn (Request $r) => str_contains($r['return_url'], 'payment=done'));
    }
}

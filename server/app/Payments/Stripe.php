<?php

namespace App\Payments;

use App\Models\RelayPayment;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Stripe Checkout: international cards.
 *
 * @see https://docs.stripe.com/api/checkout/sessions/create
 */
class Stripe
{
    /** Currencies without minor unit (amounts are not multiplied by 100). */
    protected const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    public function __construct(protected Settings $settings) {}

    public function checkoutUrl(RelayPayment $payment, string $returnUrl): string
    {
        $amount = in_array($payment->currency, self::ZERO_DECIMAL, true) ? $payment->amount : $payment->amount * 100;

        try {
            $response = Http::asForm()->withToken((string) $this->settings->get('payments.stripe_secret'))->timeout(20)
                ->post('https://api.stripe.com/v1/checkout/sessions', [
                    'mode' => 'payment',
                    'success_url' => $returnUrl,
                    'cancel_url' => $returnUrl,
                    'client_reference_id' => $payment->reference,
                    'metadata[reference]' => $payment->reference,
                    'line_items[0][quantity]' => 1,
                    'line_items[0][price_data][currency]' => strtolower($payment->currency),
                    'line_items[0][price_data][unit_amount]' => $amount,
                    'line_items[0][price_data][product_data][name]' => $payment->description().' (30 days)',
                ]);
        } catch (ConnectionException $e) {
            throw new PaymentException('Stripe cannot be reached: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful() || ! $response->json('url')) {
            throw new PaymentException('Stripe: '.($response->json('error.message') ?? 'unexpected answer'));
        }

        $payment->forceFill(['provider_reference' => $response->json('id')])->save();

        return $response->json('url');
    }

    /**
     * Verifies the Stripe-Signature header (HMAC SHA-256, 5 minutes tolerance).
     */
    public function verifySignature(string $payload, string $header): bool
    {
        $secret = (string) $this->settings->get('payments.stripe_webhook_secret');
        $parts = collect(explode(',', $header))->mapWithKeys(fn ($part) => [trim(strtok($part, '=')) => substr($part, strpos($part, '=') + 1)]);
        $timestamp = (int) $parts->get('t');

        if ($secret === '' || $timestamp === 0 || abs(time() - $timestamp) > 300) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$payload, $secret), (string) $parts->get('v1'));
    }
}

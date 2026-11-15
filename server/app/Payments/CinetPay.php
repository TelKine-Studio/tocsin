<?php

namespace App\Payments;

use App\Models\RelayPayment;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * CinetPay: mobile money (Orange, MTN, Moov, Wave…) and cards in
 * West and Central Africa.
 *
 * @see https://docs.cinetpay.com/api/1.0-fr/checkout/initialisation
 */
class CinetPay
{
    public const API = 'https://api-checkout.cinetpay.com/v2';

    public function __construct(protected Settings $settings) {}

    public function checkoutUrl(RelayPayment $payment, string $returnUrl): string
    {
        $response = $this->post('/payment', [
            'transaction_id' => $payment->reference,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'description' => $payment->description(),
            'notify_url' => route('relay.payments.cinetpay'),
            'return_url' => $returnUrl,
            'channels' => 'ALL',
            'lang' => app()->getLocale() === 'fr' ? 'fr' : 'en',
        ]);

        $url = $response['data']['payment_url'] ?? null;

        if (($response['code'] ?? null) !== '201' || ! $url) {
            throw new PaymentException('CinetPay: '.($response['message'] ?? 'unexpected answer').' '.($response['description'] ?? ''));
        }

        return $url;
    }

    /** Asks CinetPay whether the transaction was accepted (never trust the notification alone). */
    public function isPaid(string $reference): bool
    {
        $response = $this->post('/payment/check', ['transaction_id' => $reference]);

        return ($response['data']['status'] ?? null) === 'ACCEPTED';
    }

    /** @return array<string, mixed> */
    protected function post(string $path, array $data): array
    {
        try {
            return Http::acceptJson()->timeout(20)->post(self::API.$path, [
                'apikey' => $this->settings->get('payments.cinetpay_api_key'),
                'site_id' => $this->settings->get('payments.cinetpay_site_id'),
                ...$data,
            ])->json() ?? [];
        } catch (ConnectionException $e) {
            throw new PaymentException('CinetPay cannot be reached: '.$e->getMessage(), previous: $e);
        }
    }
}

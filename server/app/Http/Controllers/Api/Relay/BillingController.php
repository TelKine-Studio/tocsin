<?php

namespace App\Http\Controllers\Api\Relay;

use App\Http\Controllers\Controller;
use App\Models\RelayClient;
use App\Models\RelayPayment;
use App\Payments\CinetPay;
use App\Payments\PaymentException;
use App\Payments\Stripe;
use App\Support\RelayPlans;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Paid relay plans: checkout sessions and payment notifications.
 */
class BillingController extends Controller
{
    public function __construct(protected Settings $settings, protected RelayPlans $plans) {}

    /** POST /api/relay/v1/checkout — called by an instance (relay key). */
    public function checkout(Request $request, CinetPay $cinetPay, Stripe $stripe): JsonResponse
    {
        /** @var RelayClient $client */
        $client = $request->attributes->get('relay_client');
        $plans = $this->plans->paid();

        $data = $request->validate([
            'plan' => ['required', Rule::in(array_keys($plans))],
            'provider' => ['required', Rule::in($this->plans->providers())],
            'return_url' => ['required', 'url:http,https', 'max:500'],
        ]);

        $payment = $client->payments()->create([
            'plan' => $data['plan'],
            'amount' => $plans[$data['plan']]['price'],
            'currency' => $plans[$data['plan']]['currency'],
            'provider' => $data['provider'],
        ]);

        try {
            $url = $data['provider'] === 'cinetpay'
                ? $cinetPay->checkoutUrl($payment, $data['return_url'])
                : $stripe->checkoutUrl($payment, $data['return_url']);
        } catch (PaymentException $e) {
            $payment->forceFill(['status' => 'failed'])->save();
            Log::error('Relay checkout failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'payment_unavailable', 'message' => $e->getMessage()], 502);
        }

        return response()->json(['data' => ['url' => $url, 'reference' => $payment->reference]], 201);
    }

    /** CinetPay notification (server to server). */
    public function cinetpay(Request $request, CinetPay $cinetPay): Response
    {
        $payment = RelayPayment::query()->where('reference', (string) $request->input('cpm_trans_id'))->where('provider', 'cinetpay')->first();

        if ($payment && $payment->status !== 'paid' && $cinetPay->isPaid($payment->reference)) {
            $payment->markPaid((string) $request->input('cpm_trans_id'));
        }

        return response()->noContent();
    }

    /** Stripe webhook (checkout.session.completed). */
    public function stripe(Request $request, Stripe $stripe): Response
    {
        if (! $stripe->verifySignature($request->getContent(), (string) $request->header('Stripe-Signature'))) {
            return response('Invalid signature', 400);
        }

        $session = $request->json('data.object', []);

        if ($request->json('type') === 'checkout.session.completed' && ($session['payment_status'] ?? null) === 'paid') {
            RelayPayment::query()
                ->where('reference', $session['client_reference_id'] ?? $session['metadata']['reference'] ?? '')
                ->where('provider', 'stripe')
                ->first()
                ?->markPaid($session['id'] ?? null);
        }

        return response()->noContent();
    }
}

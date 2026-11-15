<?php

namespace App\Http\Controllers;

use App\Models\AccountUsage;
use App\Payments\CinetPay;
use App\Payments\PaymentException;
use App\Payments\Stripe;
use App\Support\AccountQuota;
use App\Support\RelayPlans;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Hosted mode: plan, daily usage and checkout of the signed-in account.
 */
class AccountPlanController extends Controller
{
    public function __construct(protected RelayPlans $plans)
    {
        abort_unless(AccountQuota::enabled(), 404);
    }

    public function show(Request $request): View
    {
        $user = $request->user();

        return view('account.plan', [
            'user' => $user,
            'used' => $user->messagesToday(),
            'limit' => $user->effectiveDailyLimit(),
            'topics' => $user->ownedTopics()->count(),
            'maxTopics' => $user->maxTopics(),
            'freeLimit' => $this->plans->dailyLimit('free'),
            'freeMaxTopics' => $this->plans->maxTopics('free'),
            'paidPlans' => $this->plans->paid(),
            'providers' => $this->plans->providers(),
            'history' => AccountUsage::query()->where('user_id', $user->id)->orderByDesc('date')->limit(14)->get(),
            'payments' => $user->payments()->latest()->limit(10)->get(),
            'pending' => $request->filled('payment')
                ? $user->payments()->where('reference', $request->query('payment'))->first()
                : null,
        ]);
    }

    public function checkout(Request $request, CinetPay $cinetPay, Stripe $stripe): RedirectResponse
    {
        $plans = $this->plans->paid();

        $data = $request->validate([
            'plan' => ['required', Rule::in(array_keys($plans))],
            'provider' => ['required', Rule::in($this->plans->providers())],
        ]);

        $payment = $request->user()->payments()->create([
            'plan' => $data['plan'],
            'amount' => $plans[$data['plan']]['price'],
            'currency' => $plans[$data['plan']]['currency'],
            'provider' => $data['provider'],
        ]);

        $return = route('account.plan', ['payment' => $payment->reference]);

        try {
            $url = $data['provider'] === 'cinetpay'
                ? $cinetPay->checkoutUrl($payment, $return)
                : $stripe->checkoutUrl($payment, $return);
        } catch (PaymentException $e) {
            $payment->forceFill(['status' => 'failed'])->save();
            Log::error('Account checkout failed', ['user' => $request->user()->id, 'error' => $e->getMessage()]);

            return back()->withErrors(['alert' => __('The payment page could not be opened. Please try again in a moment.')]);
        }

        return redirect()->away($url);
    }
}

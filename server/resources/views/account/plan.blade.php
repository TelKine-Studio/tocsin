@php
    $fmt = fn (int $n) => number_format($n, 0, ',', ' ');
    $percent = $limit > 0 ? min(100, (int) round($used / $limit * 100)) : 0;
    $barColor = $percent >= 100 ? 'bg-red-500' : ($percent >= 80 ? 'bg-amber-500' : 'bg-brand-600');
    $current = $user->plan ?? 'free';
    $tiers = ['free' => ['name' => __('Free'), 'daily_limit' => $freeLimit, 'price' => 0, 'currency' => null]] + $paidPlans;
    $providerLabels = ['cinetpay' => __('Mobile money'), 'stripe' => __('Card')];
@endphp
<x-layouts.app :title="__('My plan')">
    <x-ui.page-header :title="__('My plan')" :description="__('Publish without running a server. Each message published to your topics counts toward your daily quota; repeated alerts grouped into one do not.')" />

    @if ($pending)
        <div @class(['mb-6 flex gap-3 rounded-2xl border p-4 text-sm', 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200' => $pending->status === 'paid', 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200' => $pending->status !== 'paid'])>
            <x-ui.icon :name="$pending->status === 'paid' ? 'check-circle' : 'clock'" class="mt-0.5 size-4 shrink-0" />
            {{ $pending->status === 'paid'
                ? __('Payment received: your :plan plan is active.', ['plan' => ucfirst($pending->plan)])
                : __('Your payment is being confirmed. This page will show your new plan as soon as the payment provider confirms it.') }}
        </div>
    @endif

    @unless ($user->hasVerifiedEmail())
        <div class="mb-6 flex flex-wrap items-center gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">
            <x-ui.icon name="mail" class="size-4 shrink-0" />
            <span class="flex-1">{{ __('Confirm your email address to start publishing: we sent you a link.') }}</span>
            <form method="POST" action="{{ route('verification.send') }}">@csrf<x-ui.button size="sm" variant="secondary">{{ __('Send the link again') }}</x-ui.button></form>
        </div>
    @endunless

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card class="lg:col-span-2" :title="__('Today')" icon="activity">
            <div class="flex items-end justify-between gap-4">
                <p class="text-3xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $fmt($used) }} <span class="text-base font-normal text-zinc-500">/ {{ $fmt($limit) }} {{ __('messages') }}</span></p>
                <span class="text-sm text-zinc-500">{{ __('Resets at midnight') }}</span>
            </div>
            <div class="mt-4 h-2.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10">
                <div class="h-full rounded-full {{ $barColor }} transition-all" style="width: {{ $percent }}%"></div>
            </div>
            <dl class="mt-6 grid gap-4 sm:grid-cols-3">
                <div>
                    <dt class="text-xs text-zinc-500">{{ __('Plan') }}</dt>
                    <dd class="mt-1 font-semibold text-zinc-900 dark:text-white">{{ $tiers[$current]['name'] ?? ucfirst($current) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-zinc-500">{{ __('Topics') }}</dt>
                    <dd class="mt-1 font-semibold text-zinc-900 tabular-nums dark:text-white">{{ $topics }}{{ $maxTopics ? ' / '.$maxTopics : '' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-zinc-500">{{ $user->plan_expires_at ? __('Paid until') : __('Renewal') }}</dt>
                    <dd class="mt-1 font-semibold text-zinc-900 dark:text-white">{{ $user->plan_expires_at?->isoFormat('LL') ?? '—' }}</dd>
                </div>
            </dl>
        </x-ui.card>

        <x-ui.card :title="__('Last 14 days')" icon="bar-chart">
            @php $max = max(1, $history->max('messages') ?? 1); @endphp
            @if ($history->isEmpty())
                <p class="text-sm text-zinc-500">{{ __('Nothing published yet.') }}</p>
            @else
                <div class="flex h-28 items-end gap-1">
                    @foreach ($history->reverse() as $day)
                        <div class="flex-1 rounded-t bg-brand-500/80" style="height: {{ max(4, (int) round($day->messages / $max * 100)) }}%" title="{{ \Illuminate\Support\Carbon::parse($day->date)->isoFormat('ll') }} · {{ $fmt($day->messages) }}{{ $day->rejected ? ' · '.__(':count refused', ['count' => $fmt($day->rejected)]) : '' }}"></div>
                    @endforeach
                </div>
                @if ($history->sum('rejected') > 0)
                    <p class="mt-3 text-xs text-red-600 dark:text-red-400">{{ __(':count messages refused over the limit recently.', ['count' => $fmt($history->sum('rejected'))]) }}</p>
                @endif
            @endif
        </x-ui.card>
    </div>

    <h2 class="mt-10 mb-4 text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Plans') }}</h2>
    @if ($paidPlans === [])
        <p class="rounded-2xl border border-dashed border-zinc-200 p-5 text-sm text-zinc-500 dark:border-zinc-800">{{ __('Paid plans are not available yet.') }}</p>
    @else
        <div class="grid gap-4 md:grid-cols-3">
            @foreach ($tiers as $key => $tier)
                <div @class(['flex flex-col rounded-2xl border p-5', 'border-brand-500 bg-brand-50/40 ring-4 ring-brand-500/10 dark:bg-brand-500/5' => $key === $current, 'border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900' => $key !== $current])>
                    <div class="flex items-center justify-between">
                        <p class="font-semibold text-zinc-900 dark:text-white">{{ $tier['name'] }}</p>
                        @if ($key === $current)<x-ui.badge color="brand">{{ __('Current plan') }}</x-ui.badge>@endif
                    </div>
                    <p class="mt-3 text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">
                        {{ $fmt($tier['price']) }} {{ $tier['currency'] ?? reset($paidPlans)['currency'] }}@if ($tier['price'] > 0)<span class="text-sm font-normal text-zinc-500"> / {{ __('30 days') }}</span>@endif
                    </p>
                    <ul class="mt-4 flex-1 space-y-2 text-sm text-zinc-600 dark:text-zinc-400">
                        <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 size-4 text-emerald-600" />{{ __(':count messages per day', ['count' => $fmt($tier['daily_limit'])]) }}</li>
                        <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 size-4 text-emerald-600" />{{ $key === 'free' && $freeMaxTopics !== null ? __(':count topics', ['count' => $freeMaxTopics]) : __('Unlimited topics') }}</li>
                        <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 size-4 text-emerald-600" />{{ __('iPhone, Android and browsers') }}</li>
                    </ul>
                    @if ($key !== 'free')
                        <div class="mt-5 space-y-2">
                            @forelse ($providers as $provider)
                                <form method="POST" action="{{ route('account.plan.checkout') }}">
                                    @csrf
                                    <input type="hidden" name="plan" value="{{ $key }}">
                                    <input type="hidden" name="provider" value="{{ $provider }}">
                                    <x-ui.button class="w-full" :variant="$loop->first ? 'primary' : 'secondary'">
                                        {{ $key === $current ? __('Extend by 30 days') : __('Choose :plan', ['plan' => $tier['name']]) }} · {{ $providerLabels[$provider] ?? $provider }}
                                    </x-ui.button>
                                </form>
                            @empty
                                <p class="text-xs text-zinc-500">{{ __('Payment is not available yet.') }}</p>
                            @endforelse
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
        <p class="mt-3 text-xs text-zinc-500">{{ __('Plans last 30 days and do not renew automatically: you are never charged without asking. When a plan ends, the account goes back to the free plan.') }}</p>
    @endif

    @if ($payments->isNotEmpty())
        <x-ui.card class="mt-10" :title="__('Payments')" icon="layers" :padding="false">
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($payments as $payment)
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-sm sm:px-6">
                        <span class="font-medium text-zinc-900 dark:text-white">{{ ucfirst($payment->plan) }}</span>
                        <span class="tabular-nums text-zinc-600 dark:text-zinc-400">{{ $fmt($payment->amount) }} {{ $payment->currency }}</span>
                        <span class="text-zinc-500">{{ $providerLabels[$payment->provider] ?? $payment->provider }}</span>
                        <span class="flex-1 text-right text-zinc-500">{{ ($payment->paid_at ?? $payment->created_at)->isoFormat('LL') }}</span>
                        <x-ui.badge :color="$payment->status === 'paid' ? 'green' : ($payment->status === 'failed' ? 'red' : 'zinc')">{{ match ($payment->status) { 'paid' => __('Paid'), 'failed' => __('Failed'), default => __('Pending') } }}</x-ui.badge>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</x-layouts.app>

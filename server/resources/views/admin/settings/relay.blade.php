<x-layouts.app :title="__('Settings')">
    @include('admin.settings.partials.nav')
    <x-settings.layout :section="$section">
        <x-ui.card :title="__('Push relay')" :description="__('Let other Tocsin instances deliver notifications to the app published with this server’s Apple Push key. This is how Telkine serves self-hosted instances.')" icon="layers">
            @if ($hostedStats)
                <div class="mb-6 grid grid-cols-3 divide-x divide-zinc-100 rounded-xl border border-zinc-100 dark:divide-zinc-800 dark:border-zinc-800">
                    @foreach ([[__('Accounts'), $hostedStats['accounts']], [__('Paid accounts'), $hostedStats['paid']], [__('Messages today'), $hostedStats['messages_today']]] as [$label, $value])
                        <div class="px-4 py-3">
                            <p class="text-xs text-zinc-500">{{ $label }}</p>
                            <p class="mt-0.5 text-lg font-semibold text-zinc-900 tabular-nums dark:text-white">{{ number_format($value, 0, ',', ' ') }}</p>
                        </div>
                    @endforeach
                </div>
            @endif

            @unless ($settings->apnsConfigured())
                <div class="mb-6 flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">
                    <x-ui.icon name="alert" class="mt-0.5 size-4 shrink-0" />
                    <span>{!! __('The relay needs this server’s own Apple Push key. Configure it first in :link.', ['link' => '<a href="'.route('admin.settings.edit', 'push').'" class="font-semibold underline">'.e(__('Push notifications')).'</a>']) !!}</span>
                </div>
            @endunless

            @if ($relayStats && $settings->boolean('relay_server.enabled'))
                <div class="mb-6 grid grid-cols-3 divide-x divide-zinc-100 rounded-xl border border-zinc-100 dark:divide-zinc-800 dark:border-zinc-800">
                    @foreach ([[__('Instances'), $relayStats['clients']], [__('Active today'), $relayStats['active_today']], [__('Sent today'), $relayStats['sent_today']]] as [$label, $value])
                        <div class="px-4 py-3">
                            <p class="text-xs text-zinc-500">{{ $label }}</p>
                            <p class="mt-0.5 text-lg font-semibold text-zinc-900 tabular-nums dark:text-white">{{ number_format($value, 0, ',', ' ') }}</p>
                        </div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('admin.settings.relay-server') }}" id="relay-form" class="space-y-6">
                @csrf
                @method('PUT')
                @if (config('tocsin.relay_operator'))
                    <x-ui.toggle name="enabled" :checked="$settings->boolean('relay_server.enabled')" :label="__('Act as a push relay')"
                        :description="__('Exposes the relay API (/api/relay/v1) to other instances.')" />
                    <div class="border-t border-zinc-100 dark:border-zinc-800"></div>
                    <x-ui.toggle name="registration" :checked="$settings->boolean('relay_server.registration')" :label="__('Self-service registration')"
                        :description="__('Instances can connect in one click on the free plan. When disabled, you create keys yourself.')" />
                @endif
                <div @class(['grid gap-5 sm:grid-cols-2', 'border-t border-zinc-100 pt-6 dark:border-zinc-800' => config('tocsin.relay_operator')])>
                    <x-ui.input name="free_daily_limit" type="number" min="0" :label="__('Free plan: per day')" :value="$settings->integer('relay_server.free_daily_limit')"
                        :hint="config('tocsin.hosted') ? __('Notifications per relay instance, messages per hosted account. The daily limits of Pro and Business below work the same way.') : __('Per instance. Paid plans get a custom limit on each instance.')" />
                    <x-ui.input name="burst_per_minute" type="number" min="1" :label="__('Burst limit per minute')" :value="$settings->integer('relay_server.burst_per_minute')"
                        :hint="__('Protects Apple Push from floods, whatever the plan.')" />
                    @if (config('tocsin.hosted'))
                        <x-ui.input name="hosted_free_max_topics" type="number" min="0" :label="__('Free plan: topics per account')" :value="$settings->integer('hosted.free_max_topics')"
                            :hint="__('0 = unlimited. Paid accounts have no topic limit.')" />
                    @endif
                </div>

                <div class="border-t border-zinc-100 pt-6 dark:border-zinc-800">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Paid plans (30 days)') }}</h3>
                    <p class="mt-1 text-sm text-zinc-500">{{ __('A price of 0 hides the plan. Instances buy them from their push settings.') }}</p>
                    <div class="mt-4 grid gap-5 sm:grid-cols-3">
                        <x-ui.input name="currency" :label="__('Currency')" :value="$settings->string('relay_server.currency')" maxlength="3" :hint="__('XOF, EUR, USD…')" />
                        <x-ui.input name="pro_price" type="number" min="0" :label="__('Pro price')" :value="$settings->integer('relay_server.pro_price')" />
                        <x-ui.input name="pro_daily_limit" type="number" min="1" :label="__('Pro: per day')" :value="$settings->integer('relay_server.pro_daily_limit')" />
                        <div class="hidden sm:block"></div>
                        <x-ui.input name="business_price" type="number" min="0" :label="__('Business price')" :value="$settings->integer('relay_server.business_price')" />
                        <x-ui.input name="business_daily_limit" type="number" min="1" :label="__('Business: per day')" :value="$settings->integer('relay_server.business_daily_limit')" />
                    </div>
                </div>

                <div class="border-t border-zinc-100 pt-6 dark:border-zinc-800">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Payment providers') }}</h3>
                    <div class="mt-4 grid gap-5 sm:grid-cols-2">
                        <x-ui.input name="cinetpay_site_id" :label="__('CinetPay site ID')" :value="$settings->get('payments.cinetpay_site_id')" autocomplete="off" />
                        <x-ui.input name="cinetpay_api_key" type="password" :label="__('CinetPay API key')" autocomplete="new-password" :placeholder="$settings->get('payments.cinetpay_api_key') ? '••••••••' : ''" />
                        <x-ui.input name="stripe_secret" type="password" :label="__('Stripe secret key')" autocomplete="new-password" :placeholder="$settings->get('payments.stripe_secret') ? '••••••••' : 'sk_live_…'" />
                        <x-ui.input name="stripe_webhook_secret" type="password" :label="__('Stripe webhook secret')" autocomplete="new-password" :placeholder="$settings->get('payments.stripe_webhook_secret') ? '••••••••' : 'whsec_…'" />
                    </div>
                    <div class="mt-4 space-y-1 rounded-xl bg-zinc-50 p-3 text-xs text-zinc-600 dark:bg-white/[.03] dark:text-zinc-400">
                        <p>{{ __('CinetPay notification URL:') }} <code class="font-mono">{{ route('relay.payments.cinetpay') }}</code></p>
                        <p>{{ __('Stripe webhook URL (event checkout.session.completed):') }} <code class="font-mono">{{ route('relay.payments.stripe') }}</code></p>
                        <p>{{ __('Secrets are stored encrypted. Leave a field empty to keep its value.') }}</p>
                    </div>
                </div>
            </form>
            <x-slot:footer>
                @if ($settings->boolean('relay_server.enabled'))
                    <x-ui.button variant="ghost" :href="route('admin.relay-clients.index')" icon="layers">{{ __('Manage instances') }}</x-ui.button>
                @endif
                <x-ui.button form="relay-form">{{ __('Save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </x-settings.layout>
</x-layouts.app>

@php
    $mode = $settings->pushMode();
    $configured = $settings->pushConfigured();
    $relayConnected = filled($settings->get('relay.key'));
    $limit = $relayUsage['daily_limit'] ?? $settings->get('relay.daily_limit');
    $used = $relayUsage['used_today'] ?? null;
    $plan = $relayUsage['plan'] ?? $settings->get('relay.plan');
@endphp
<x-layouts.app :title="__('Settings')">
    @include('admin.settings.partials.nav')
    <x-settings.layout :section="$section">
        {{-- Status --}}
        <section @class(['relative overflow-hidden rounded-2xl p-6 text-white', 'bg-linear-to-br from-emerald-500 to-teal-600' => $configured, 'bg-linear-to-br from-zinc-900 to-zinc-800 dark:from-zinc-800 dark:to-zinc-900' => ! $configured])>
            <div class="dot-grid absolute inset-0 opacity-60"></div>
            <div class="relative flex flex-wrap items-center gap-5">
                <span class="inline-flex size-12 items-center justify-center rounded-2xl bg-white/15 ring-1 ring-white/20"><x-ui.icon name="bell" class="size-6" /></span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-lg font-semibold">{{ $configured ? __('Push notifications are active') : __('Push notifications are not configured') }}</h2>
                    <p class="mt-1 text-sm text-white/75">
                        @if ($configured)
                            {{ $mode === 'relay' ? __('Delivered through the relay :url.', ['url' => parse_url($settings->relayUrl(), PHP_URL_HOST)]) : __('Delivered with your own Apple Push key.') }}
                            · {{ trans_choice(':count iPhone registered|:count iPhones registered', $devices->sum()) }}
                        @else
                            {{ __('Choose how this server delivers notifications to iPhones.') }}
                        @endif
                    </p>
                </div>
                @if ($configured)
                    <form method="POST" action="{{ route('admin.settings.push.test') }}">
                        @csrf
                        <button type="submit" class="inline-flex h-8 items-center gap-1.5 rounded-md border border-emerald-200 bg-white px-3 text-sm font-medium text-emerald-700 transition-colors hover:bg-emerald-50 dark:border-emerald-500/30 dark:bg-transparent dark:text-emerald-300 dark:hover:bg-emerald-500/10">
                            <x-ui.icon name="send" class="size-3.5" />{{ __('Send a test to my devices') }}
                        </button>
                    </form>
                @endif
            </div>
        </section>

        <form method="POST" action="{{ route('admin.settings.push.confidential') }}">
            @csrf
            @method('PUT')
            <x-ui.card :title="__('Confidential mode')" icon="lock">
                <x-ui.toggle name="confidential" :checked="$settings->boolean('push.confidential')" :label="__('Never send the content of messages through Apple or the relay')"
                    :description="__('Notifications only carry the topic and a message identifier; the iPhone downloads the content directly from this server before displaying it.')" onchange="this.form.submit()" />
            </x-ui.card>
        </form>

        {{-- Mode --}}
        <form method="POST" action="{{ route('admin.settings.push.mode') }}" class="grid gap-3 sm:grid-cols-2">
            @csrf
            @method('PUT')
            @foreach ([
                'relay' => ['icon' => 'layers', 'title' => __('Telkine relay'), 'badge' => __('Recommended'), 'text' => __('Works with the official Tocsin app from the App Store. One click, nothing to configure. Free up to :count notifications a day.', ['count' => number_format((int) ($settings->get('relay.daily_limit') ?: 1000), 0, ',', ' ')])],
                'direct' => ['icon' => 'key', 'title' => __('Your own Apple Push key'), 'badge' => __('Unlimited'), 'text' => __('For your own build of the app, signed with your Apple Developer account. No relay, no quota.')],
            ] as $value => $option)
                <button type="submit" name="mode" value="{{ $value }}" @class([
                    'flex flex-col gap-3 rounded-2xl border p-5 text-left transition',
                    'border-brand-500 bg-brand-50/50 ring-4 ring-brand-500/10 dark:bg-brand-500/5' => $mode === $value,
                    'border-zinc-200 bg-white hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700' => $mode !== $value,
                ])>
                    <span class="flex items-center justify-between">
                        <span @class(['inline-flex size-10 items-center justify-center rounded-xl', 'bg-brand-600 text-white' => $mode === $value, 'bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300' => $mode !== $value])><x-ui.icon :name="$option['icon']" class="size-5" /></span>
                        <x-ui.badge :color="$value === 'relay' ? 'brand' : 'zinc'">{{ $option['badge'] }}</x-ui.badge>
                    </span>
                    <span>
                        <span class="block font-semibold text-zinc-900 dark:text-white">{{ $option['title'] }}</span>
                        <span class="mt-1 block text-sm leading-relaxed text-zinc-500 dark:text-zinc-400">{{ $option['text'] }}</span>
                    </span>
                    @if ($mode === $value)
                        <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-700 dark:text-brand-300"><x-ui.icon name="check-circle" class="size-3.5" />{{ __('Selected') }}</span>
                    @endif
                </button>
            @endforeach
        </form>

        @if ($mode === 'relay')
            @if ($relayConnected)
                <x-ui.card :title="__('Relay connection')" :description="$settings->relayUrl()" icon="layers">
                    <x-slot:actions>
                        <x-ui.badge color="green" dot>{{ __('Connected') }}</x-ui.badge>
                    </x-slot:actions>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="rounded-xl bg-zinc-50 p-4 dark:bg-white/[.03]">
                            <p class="text-xs text-zinc-500">{{ __('Plan') }}</p>
                            <p class="mt-1 text-lg font-semibold capitalize text-zinc-900 dark:text-white">{{ $plan ?? '—' }}</p>
                        </div>
                        <div class="rounded-xl bg-zinc-50 p-4 sm:col-span-2 dark:bg-white/[.03]">
                            <div class="flex items-baseline justify-between">
                                <p class="text-xs text-zinc-500">{{ __('Notifications today') }}</p>
                                <p class="text-xs text-zinc-500">{{ $used === null ? __('Usage unavailable') : number_format($used, 0, ',', ' ').' / '.number_format((int) $limit, 0, ',', ' ') }}</p>
                            </div>
                            @if ($used !== null && $limit)
                                @php $percent = min(100, $used / max(1, $limit) * 100); @endphp
                                <div class="mt-3 h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800">
                                    <div @class(['h-full rounded-full', 'bg-emerald-500' => $percent < 75, 'bg-amber-500' => $percent >= 75 && $percent < 100, 'bg-red-500' => $percent >= 100]) style="width: {{ $percent }}%"></div>
                                </div>
                                <p class="mt-2 text-xs text-zinc-500">{{ __('The counter resets every day at midnight (UTC).') }}</p>
                            @endif
                        </div>
                    </div>
                    @if (request('payment') === 'done')
                        <div class="mt-5 flex gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200">
                            <x-ui.icon name="check-circle" class="mt-0.5 size-4 shrink-0" />{{ __('Thank you! Your plan is activated as soon as the payment is confirmed (usually within a minute).') }}
                        </div>
                    @endif
                    @if (! empty($relayUsage['plan_expires_at']))
                        <p class="mt-4 text-sm text-zinc-600 dark:text-zinc-400">{{ __('Your :plan plan is valid until :date.', ['plan' => ucfirst($plan), 'date' => \Illuminate\Support\Carbon::parse($relayUsage['plan_expires_at'])->isoFormat('LL')]) }}</p>
                    @endif
                    @if (! empty($relayUsage['plans']) && ! empty($relayUsage['payment_providers']))
                        <div class="mt-6 grid gap-3 sm:grid-cols-2">
                            @foreach ($relayUsage['plans'] as $key => $offer)
                                <div @class(['rounded-2xl border p-5', 'border-brand-500 ring-4 ring-brand-500/10' => $plan === $key, 'border-zinc-200 dark:border-zinc-800' => $plan !== $key])>
                                    <div class="flex items-baseline justify-between">
                                        <p class="font-semibold text-zinc-900 dark:text-white">{{ $offer['name'] }}</p>
                                        <p class="text-sm"><span class="text-xl font-semibold text-zinc-900 tabular-nums dark:text-white">{{ number_format($offer['price'], 0, ',', ' ') }}</span> {{ $offer['currency'] }} <span class="text-zinc-500">/ {{ __('30 days') }}</span></p>
                                    </div>
                                    <p class="mt-1 text-sm text-zinc-500">{{ __(':count notifications per day', ['count' => number_format($offer['daily_limit'], 0, ',', ' ')]) }}</p>
                                    <form method="POST" action="{{ route('admin.settings.relay.checkout') }}" class="mt-4 flex flex-wrap gap-2">
                                        @csrf
                                        <input type="hidden" name="plan" value="{{ $key }}">
                                        @foreach ($relayUsage['payment_providers'] as $provider)
                                            <x-ui.button name="provider" :value="$provider" :variant="$loop->first ? 'primary' : 'secondary'" size="sm">
                                                {{ $provider === 'cinetpay' ? __('Mobile money / card') : __('International card') }}
                                            </x-ui.button>
                                        @endforeach
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <p class="mt-5 text-sm text-zinc-500 dark:text-zinc-400">{{ __('The relay forwards notifications to Apple without storing their content. Need even more? Contact Telkine, or switch to your own Apple Push key (unlimited).') }}</p>
                    <x-slot:footer>
                        <form method="POST" action="{{ route('admin.settings.relay.disconnect') }}" data-confirm-title="{{ __('Disconnect from the relay?') }}" data-confirm="{{ __('Notifications will stop until you reconnect or configure your own key.') }}" data-confirm-action="{{ __('Disconnect') }}">
                            @csrf
                            @method('DELETE')
                            <x-ui.button variant="ghost-danger">{{ __('Disconnect') }}</x-ui.button>
                        </form>
                    </x-slot:footer>
                </x-ui.card>
            @else
                <form method="POST" action="{{ route('admin.settings.relay.connect') }}">
                    @csrf
                    <x-ui.card :title="__('Connect to the relay')" :description="__('Your instance is registered on the free plan. No account, no card.')" icon="layers">
                        <div class="space-y-4">
                            <x-ui.input name="relay_url" type="url" :label="__('Relay URL')" :value="$settings->relayUrl()" required icon="globe" />
                            <p class="text-xs leading-relaxed text-zinc-500">{{ __('The relay receives the name and URL of this instance and your email address, to contact you about your usage.') }}</p>
                        </div>
                        <x-slot:footer>
                            <x-ui.button icon="zap">{{ __('Connect') }}</x-ui.button>
                        </x-slot:footer>
                    </x-ui.card>
                </form>

                <form method="POST" action="{{ route('admin.settings.relay.key') }}" x-data="{ open: {{ $errors->has('relay_key') ? 'true' : 'false' }} }">
                    @csrf
                    @method('PUT')
                    <button type="button" @click="open = !open" class="inline-flex items-center gap-1 text-sm font-medium text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">
                        <x-ui.icon name="chevron-right" class="size-4 transition" ::class="open && 'rotate-90'" />{{ __('I already have a relay key') }}
                    </button>
                    <div x-show="open" x-collapse class="pt-4">
                        <x-ui.card>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-ui.input name="relay_url" type="url" :label="__('Relay URL')" :value="$settings->relayUrl()" required />
                                <x-ui.input name="relay_key" type="password" :label="__('Relay key')" required placeholder="tcr_…" />
                            </div>
                            <x-slot:footer><x-ui.button>{{ __('Save') }}</x-ui.button></x-slot:footer>
                        </x-ui.card>
                    </div>
                </form>
            @endif
        @else
            <form method="POST" action="{{ route('admin.settings.push') }}" enctype="multipart/form-data">
                @csrf
                @method('PUT')
                <x-settings.section :title="__('Apple Push Notification service')" :description="__('Token-based authentication with a .p8 key from your Apple Developer account.')" icon="key"
                    :empty="! $settings->get('apns.private_key')" :fields="['team_id', 'key_id', 'bundle_id', 'private_key', 'private_key_file']">
                    <x-slot:summary>
                        @if ($settings->get('apns.private_key'))
                            <x-settings.summary>
                                <x-settings.value :label="__('Team ID')" :value="$settings->get('apns.team_id')" mono />
                                <x-settings.value :label="__('Key ID')" :value="$settings->get('apns.key_id')" mono />
                                <x-settings.value :label="__('Bundle ID of the app')" :value="$settings->string('apns.bundle_id')" mono />
                                <x-settings.value :label="__('Private key (.p8)')" :value="__('Stored (encrypted)')" />
                            </x-settings.summary>
                        @else
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Add your Apple key to send notifications to iPhone and iPad.') }}</p>
                        @endif
                    </x-slot:summary>
                    <div class="space-y-5">
                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-ui.input name="team_id" :label="__('Team ID')" :value="$settings->get('apns.team_id')" required maxlength="10" placeholder="ABCDE12345" :hint="__('Apple Developer → Membership details.')" />
                            <x-ui.input name="key_id" :label="__('Key ID')" :value="$settings->get('apns.key_id')" required maxlength="10" placeholder="XYZ987WVUT" :hint="__('Shown next to your key in Certificates, IDs & Profiles → Keys.')" />
                        </div>
                        <x-ui.input name="bundle_id" :label="__('Bundle ID of the app')" :value="$settings->string('apns.bundle_id')" required :hint="__('Must match the bundle identifier of the Tocsin app you install on the iPhones.')" />

                        <div x-data="{ mode: 'file', fileName: '' }" class="space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ __('Private key (.p8)') }}</span>
                                @if ($settings->get('apns.private_key'))<x-ui.badge color="green" icon="check">{{ __('Key stored (encrypted)') }}</x-ui.badge>@endif
                            </div>
                            <div class="inline-flex rounded-lg bg-zinc-100 p-1 text-xs font-medium dark:bg-white/5">
                                <button type="button" @click="mode = 'file'" class="rounded-md px-3 py-1 transition" :class="mode === 'file' ? 'bg-white shadow-sm dark:bg-zinc-700' : 'text-zinc-500'">{{ __('Upload file') }}</button>
                                <button type="button" @click="mode = 'paste'" class="rounded-md px-3 py-1 transition" :class="mode === 'paste' ? 'bg-white shadow-sm dark:bg-zinc-700' : 'text-zinc-500'">{{ __('Paste') }}</button>
                            </div>
                            <label x-show="mode === 'file'" class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-zinc-200 px-6 py-8 text-center transition hover:border-brand-400 hover:bg-brand-50/40 dark:border-zinc-700 dark:hover:bg-brand-500/5">
                                <span class="inline-flex size-10 items-center justify-center rounded-xl bg-zinc-100 text-zinc-500 dark:bg-white/5"><x-ui.icon name="upload" class="size-5" /></span>
                                <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300" x-text="fileName || @js(__('Choose your AuthKey_XXXXXXXXXX.p8 file'))"></span>
                                <span class="text-xs text-zinc-500">{{ $settings->get('apns.private_key') ? __('Leave empty to keep the stored key.') : __('The key is stored encrypted.') }}</span>
                                <input type="file" name="private_key_file" accept=".p8,text/plain" class="sr-only" @change="fileName = $event.target.files[0]?.name || ''">
                            </label>
                            <div x-show="mode === 'paste'" x-cloak>
                                <x-ui.textarea name="private_key" rows="5" class="font-mono text-xs" placeholder="-----BEGIN PRIVATE KEY-----&#10;…&#10;-----END PRIVATE KEY-----" />
                            </div>
                            @error('private_key')<p class="flex items-center gap-1.5 text-sm text-red-600 dark:text-red-400"><x-ui.icon name="alert" class="size-3.5" />{{ $message }}</p>@enderror
                        </div>
                    </div>
                </x-settings.section>
            </form>

            <form method="POST" action="{{ route('admin.settings.push.fcm') }}" enctype="multipart/form-data">
                @csrf
                @method('PUT')
                @php($fcm = $settings->fcmConfigured() ? (json_decode($settings->get('fcm.service_account'), true) ?: []) : null)
                <x-settings.section :title="__('Android (Firebase Cloud Messaging)')" :description="__('Service account key of the Firebase project of your Android build (Project settings → Service accounts → Generate new private key).')" icon="smartphone"
                    :empty="! $fcm" :fields="['service_account']" :edit-label="$fcm ? __('Replace') : null">
                    <x-slot:summary>
                        @if ($fcm)
                            <x-settings.summary>
                                <x-settings.value :label="__('Firebase project')" :value="$fcm['project_id'] ?? null" mono />
                                <x-settings.value :label="__('Service account')" :value="$fcm['client_email'] ?? null" mono />
                            </x-settings.summary>
                        @else
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Add the key of your Firebase project to send notifications to Android phones.') }}</p>
                        @endif
                    </x-slot:summary>
                    <x-ui.file name="service_account" accept=".json,application/json" :label="__('Service account (JSON)')" :placeholder="__('Choose the JSON file')" required />
                </x-settings.section>
            </form>

            <x-ui.card :title="__('How to get a key')" icon="book">
                <ol class="space-y-4 text-sm text-zinc-600 dark:text-zinc-400">
                    @foreach ([
                        __('Sign in to developer.apple.com with an Apple Developer Program account.'),
                        __('In Certificates, IDs & Profiles → Keys, create a key with “Apple Push Notifications service (APNs)” enabled.'),
                        __('Download the .p8 file (it can only be downloaded once) and note its Key ID.'),
                        __('Upload it here with your Team ID and the bundle ID of your build of the app.'),
                    ] as $index => $step)
                        <li class="flex gap-3">
                            <span class="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs font-semibold text-zinc-600 dark:bg-white/5 dark:text-zinc-300">{{ $index + 1 }}</span>
                            <span class="pt-0.5">{{ $step }}</span>
                        </li>
                    @endforeach
                </ol>
                <p class="mt-5 rounded-xl bg-zinc-50 p-3 text-xs leading-relaxed text-zinc-500 dark:bg-white/[.03]">
                    {{ __('Development builds (Xcode) use the sandbox APNs environment and App Store / TestFlight builds use production. Each iPhone tells the server which one to use.') }}
                </p>
            </x-ui.card>
        @endif
    </x-settings.layout>
</x-layouts.app>

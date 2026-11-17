<x-layouts.app :title="__('Profile')">
    <div class="mb-8 flex flex-wrap items-center gap-5">
        <x-ui.avatar :user="$user" size="lg" class="size-16 text-xl" />
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 sm:text-[28px] dark:text-white">{{ $user->name }}</h1>
            <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-zinc-500">{{ $user->email }} <x-ui.badge :color="$user->isAdmin() ? 'brand' : 'zinc'">{{ $user->role->label() }}</x-ui.badge></p>
        </div>
    </div>

    <div class="grid gap-8 lg:grid-cols-[220px_minmax(0,1fr)]">
        <nav class="hidden lg:block">
            <ul class="sticky top-24 space-y-1 text-sm">
                @foreach (['account' => ['user', __('Account')], 'security' => ['lock', __('Security')], 'devices' => ['smartphone', __('Devices')], 'tokens' => ['key', __('Access tokens')]] as $anchor => [$icon, $label])
                    <li><a href="#{{ $anchor }}" class="nav-link"><x-ui.icon :name="$icon" class="size-4" />{{ $label }}</a></li>
                @endforeach
            </ul>
        </nav>

        <div class="max-w-3xl space-y-6">
            <form method="POST" action="{{ route('profile.update') }}" id="account" class="scroll-mt-24">
                @csrf
                @method('PUT')
                <x-settings.section :title="__('Account')" :description="__('Your name, email address and language.')" icon="user" :fields="['name', 'email', 'locale', 'email_password']">
                    <x-slot:summary>
                        <x-settings.summary>
                            <x-settings.value :label="__('Full name')" :value="$user->name" />
                            <x-settings.value :label="__('Email')" :value="$user->email" />
                            <x-settings.value :label="__('Language')" :value="$user->locale ? ($locales[$user->locale] ?? $user->locale) : __('Instance default')" />
                        </x-settings.summary>
                    </x-slot:summary>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-ui.input name="name" :label="__('Full name')" :value="$user->name" required />
                        <x-ui.input name="email" type="email" :label="__('Email')" :value="$user->email" required />
                        <x-ui.select name="locale" :label="__('Language')" :value="$user->locale" :options="['' => __('Instance default')] + $locales" />
                        <x-ui.input name="email_password" type="password" :label="__('Current password')" :hint="__('Only needed to change the email address.')" autocomplete="current-password" />
                    </div>
                </x-settings.section>
            </form>

            <form method="POST" action="{{ route('profile.password') }}" id="security" class="scroll-mt-24">
                @csrf
                @method('PUT')
                <x-settings.section :title="__('Password')" :description="__('Use a long password that you do not use elsewhere.')" icon="lock"
                    :fields="['current_password', 'password', 'password_confirmation']" :edit-label="__('Change')" :save-label="__('Update password')">
                    <x-slot:summary>
                        <p class="font-mono text-sm tracking-widest text-zinc-500">••••••••••</p>
                    </x-slot:summary>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-ui.input name="current_password" type="password" :label="__('Current password')" required autocomplete="current-password" class="sm:col-span-2" />
                        <x-ui.input name="password" type="password" :label="__('New password')" required autocomplete="new-password" />
                        <x-ui.input name="password_confirmation" type="password" :label="__('Confirm password')" required autocomplete="new-password" />
                    </div>
                </x-settings.section>
            </form>

            <x-ui.card :title="__('Two-factor authentication')" :description="__('Ask for a code from an authenticator app (Google Authenticator, 1Password, Bitwarden…) at each login.')" icon="shield">
                <x-slot:actions>
                    @if ($user->hasTwoFactor())
                        <x-ui.badge color="green" dot>{{ __('Enabled') }}</x-ui.badge>
                    @else
                        <x-ui.badge dot>{{ __('Disabled') }}</x-ui.badge>
                    @endif
                </x-slot:actions>

                @if (session('recovery_codes'))
                    <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/20 dark:bg-amber-500/10">
                        <p class="mb-3 text-sm font-semibold text-amber-900 dark:text-amber-200">{{ __('Save these recovery codes somewhere safe. Each one can be used once if you lose your phone.') }}</p>
                        <x-ui.code :label="__('Recovery codes')">{{ implode("\n", session('recovery_codes')) }}</x-ui.code>
                    </div>
                @endif

                @if ($user->hasTwoFactor())
                    <div class="flex flex-wrap gap-3">
                        <form method="POST" action="{{ route('two-factor.recovery-codes') }}" class="flex flex-wrap items-start gap-2" x-data="{ open: {{ $errors->has('recovery_password') ? 'true' : 'false' }} }">
                            @csrf
                            <x-ui.button type="button" variant="secondary" size="sm" icon="refresh" @click="open = true" x-show="!open">{{ __('New recovery codes') }}</x-ui.button>
                            <template x-if="open">
                                <div class="flex flex-wrap items-start gap-2">
                                    <x-ui.input name="recovery_password" type="password" :placeholder="__('Your password')" required autocomplete="current-password" />
                                    <x-ui.button variant="secondary" size="sm" icon="refresh" class="mt-1">{{ __('New recovery codes') }}</x-ui.button>
                                </div>
                            </template>
                        </form>
                        <form method="POST" action="{{ route('two-factor.disable') }}" class="flex flex-wrap items-start gap-2" x-data="{ open: {{ $errors->has('password') ? 'true' : 'false' }} }">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="button" variant="ghost-danger" size="sm" @click="open = true" x-show="!open">{{ __('Disable') }}</x-ui.button>
                            <template x-if="open">
                                <div class="flex flex-wrap items-start gap-2">
                                    <x-ui.input name="password" type="password" :placeholder="__('Your password')" required autocomplete="current-password" />
                                    <x-ui.button variant="danger" size="sm" class="mt-1">{{ __('Disable') }}</x-ui.button>
                                </div>
                            </template>
                        </form>
                    </div>
                @elseif ($twoFactorQr)
                    <div class="grid gap-6 sm:grid-cols-[auto_1fr] sm:items-center">
                        <div class="rounded-2xl bg-white p-3 ring-1 ring-zinc-200 [&_svg]:size-44">{!! $twoFactorQr !!}</div>
                        <form method="POST" action="{{ route('two-factor.confirm') }}" class="space-y-4">
                            @csrf
                            <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Scan this QR code with your authenticator app, then enter the 6-digit code it shows.') }}</p>
                            <div x-data="copy(@js($user->two_factor_secret))" class="flex items-center gap-2 text-xs text-zinc-500">
                                {{ __('Or enter this key:') }} <code class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono dark:bg-white/5">{{ $user->two_factor_secret }}</code>
                                <button type="button" @click="copy()"><x-ui.icon name="copy" class="size-3.5" /></button>
                            </div>
                            <div class="flex items-start gap-2">
                                <x-ui.input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456" required class="w-36" />
                                <x-ui.button class="mt-0">{{ __('Activate') }}</x-ui.button>
                            </div>
                        </form>
                    </div>
                @else
                    <form method="POST" action="{{ route('two-factor.enable') }}">
                        @csrf
                        <x-ui.button variant="secondary" icon="shield">{{ __('Set up two-factor authentication') }}</x-ui.button>
                    </form>
                @endif
            </x-ui.card>

            <x-ui.card id="devices" class="scroll-mt-24" :title="__('Devices')" :description="__('iPhones signed in with your account and receiving push notifications.')" icon="smartphone" :padding="false">
                @forelse ($devices as $device)
                    <div class="flex items-center gap-4 border-t border-zinc-100 px-5 py-4 first:border-0 sm:px-6 dark:border-zinc-800">
                        <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300"><x-ui.icon name="apple" class="size-5" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="flex items-center gap-2 truncate text-sm font-medium text-zinc-900 dark:text-white">
                                {{ $device->name ?: 'iPhone' }}
                                @if ($device->environment === 'sandbox')<x-ui.badge color="violet">{{ __('Development') }}</x-ui.badge>@endif
                            </p>
                            <p class="truncate text-xs text-zinc-500">
                                {{ trans_choice(':count topic|:count topics', $device->topics_count) }}
                                · {{ __('Last seen :date', ['date' => $device->last_seen_at?->diffForHumans() ?? '—']) }}
                                @if ($device->app_version) · v{{ $device->app_version }}@endif
                            </p>
                        </div>
                        <form method="POST" action="{{ route('devices.destroy', $device->id) }}" data-confirm-title="{{ __('Remove this device?') }}" data-confirm="{{ __('It will stop receiving notifications until the app registers again.') }}" data-confirm-action="{{ __('Remove') }}">
                            @csrf
                            @method('DELETE')
                            <x-ui.button variant="ghost-danger" size="sm">{{ __('Remove') }}</x-ui.button>
                        </form>
                    </div>
                @empty
                    <x-ui.empty icon="smartphone" :title="__('No device yet')" :description="__('Install the Tocsin app on your iPhone and sign in to this server to receive notifications.')" />
                @endforelse
            </x-ui.card>

            <x-ui.card id="tokens" class="scroll-mt-24" :title="__('Access tokens')" :description="__('Tokens let your scripts publish messages. Create one per script and revoke those you no longer use.')" icon="key" :padding="false">
                <x-slot:actions>
                    <x-ui.button type="button" size="sm" icon="plus" x-data x-on:click="$dispatch('open-modal', 'create-token')">{{ __('Create token') }}</x-ui.button>
                </x-slot:actions>
                @if (session('plain_token'))
                    <div class="border-y border-amber-200 bg-amber-50 px-5 py-5 sm:px-6 dark:border-amber-500/20 dark:bg-amber-500/10">
                        <p class="mb-3 flex items-center gap-2 text-sm font-semibold text-amber-900 dark:text-amber-200"><x-ui.icon name="alert" class="size-4" />{{ __('Copy your new token now, it will not be shown again.') }}</p>
                        <x-ui.code :label="__('Token')">{{ session('plain_token') }}</x-ui.code>
                    </div>
                @endif

                @forelse ($tokens as $token)
                    <div class="flex items-center gap-4 border-t border-zinc-100 px-5 py-4 first:border-0 sm:px-6 dark:border-zinc-800">
                        <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300">
                            <x-ui.icon :name="str_contains(strtolower($token->name), 'app') ? 'smartphone' : 'key'" class="size-[18px]" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $token->name }}</p>
                            <p class="truncate text-xs text-zinc-500">
                                {{ in_array('*', $token->abilities) ? __('All your topics') : collect($token->abilities)->map(fn ($a) => '#'.\Illuminate\Support\Str::after($a, 'topic:'))->implode(', ') }}
                                · {{ $token->expires_at ? ($token->expires_at->isPast() ? __('expired') : __('expires :date', ['date' => $token->expires_at->diffForHumans()])) : __('no expiry') }}
                                · {{ $token->last_used_at ? __('last used :date', ['date' => $token->last_used_at->diffForHumans()]) : __('never used') }}
                            </p>
                        </div>
                        <form method="POST" action="{{ route('tokens.destroy', $token->id) }}" data-confirm-title="{{ __('Revoke this token?') }}" data-confirm="{{ __('Scripts or apps using it will stop working immediately.') }}" data-confirm-action="{{ __('Revoke') }}">
                            @csrf
                            @method('DELETE')
                            <x-ui.button variant="ghost-danger" size="sm">{{ __('Revoke') }}</x-ui.button>
                        </form>
                    </div>
                @empty
                    @unless (session('plain_token'))
                        <x-ui.empty icon="key" :title="__('No tokens yet')" :description="__('Create a token to publish messages from your scripts.')" />
                    @endunless
                @endforelse

            </x-ui.card>
        </div>
    </div>
    <x-ui.modal name="create-token" max-width="max-w-xl" :title="__('Create token')" :description="__('Tokens let your scripts publish messages. Create one per script and revoke those you no longer use.')"
        :open="$errors->hasAny(['expires', 'topics']) || ($errors->has('name') && old('expires'))">
        <form method="POST" action="{{ route('tokens.store') }}" class="space-y-4" x-data="{ limited: {{ old('topics') ? 'true' : 'false' }} }">
            @csrf
            <div class="grid gap-4 sm:grid-cols-[1fr_200px]">
                <x-ui.input name="name" :label="__('Name')" :placeholder="__('Token name, e.g. Backup script')" required maxlength="100" />
                <x-ui.select name="expires" :label="__('Expiration')" value="never" :options="['never' => __('Never'), '30' => __('30 days'), '90' => __('90 days'), '365' => __('1 year')]" />
            </div>
            <label class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                <input type="checkbox" x-model="limited" class="size-4 rounded border-zinc-300 text-brand-600">
                {{ __('Limit to some topics (recommended for scripts)') }}
            </label>
            <div x-show="limited" x-collapse class="flex flex-wrap gap-2">
                @forelse ($writableTopics as $topic)
                    <label class="cursor-pointer">
                        <input type="checkbox" name="topics[]" value="{{ $topic->name }}" class="peer sr-only" @checked(in_array($topic->name, old('topics', [])))>
                        <span class="inline-flex items-center rounded-lg border border-zinc-200 px-2.5 py-1 font-mono text-xs text-zinc-600 transition peer-checked:border-brand-500 peer-checked:bg-brand-50 peer-checked:text-brand-700 dark:border-zinc-700 dark:text-zinc-300 dark:peer-checked:bg-brand-500/10 dark:peer-checked:text-brand-300">#{{ $topic->name }}</span>
                    </label>
                @empty
                    <p class="text-xs text-zinc-500">{{ __('No topic you can publish to.') }}</p>
                @endforelse
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <x-ui.button type="button" variant="ghost" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button icon="plus">{{ __('Create token') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</x-layouts.app>

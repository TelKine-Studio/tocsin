<x-layouts.app :title="$user->name">
    <nav class="mb-4 flex items-center gap-1.5 text-sm text-zinc-500">
        <a href="{{ route('admin.users.index') }}" class="hover:text-zinc-900 dark:hover:text-white">{{ __('Users') }}</a>
        <x-ui.icon name="chevron-right" class="size-3.5" /><span class="truncate">{{ $user->name }}</span>
    </nav>
    <div class="mb-8 flex flex-wrap items-center gap-5">
        <x-ui.avatar :user="$user" size="lg" class="size-16 text-xl" />
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $user->name }}</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ $user->email }} · {{ __('Member since :date', ['date' => $user->created_at->isoFormat('LL')]) }}</p>
        </div>
    </div>

    <div class="max-w-3xl space-y-6">
        <form method="POST" action="{{ route('admin.users.update', $user) }}">
            @csrf
            @method('PUT')
            <x-settings.section :title="__('Account')" icon="user" :fields="['name', 'email', 'role', 'is_active']" :save-label="__('Save changes')">
                <x-slot:summary>
                    <x-settings.summary>
                        <x-settings.value :label="__('Full name')" :value="$user->name" />
                        <x-settings.value :label="__('Email')" :value="$user->email" />
                        <x-settings.value :label="__('Role')" :value="$user->role->label()" />
                        <x-settings.value :label="__('Status')" :on="$user->is_active" :value="__('Active')" :empty="__('Suspended')" />
                    </x-settings.summary>
                </x-slot:summary>
                <div class="space-y-6">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-ui.input name="name" :label="__('Full name')" :value="$user->name" required />
                        <x-ui.input name="email" type="email" :label="__('Email')" :value="$user->email" required />
                    </div>
                    <fieldset>
                        <legend class="mb-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ __('Role') }}</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ([
                                'user' => ['icon' => 'user', 'text' => __('Creates and manages their own topics, subscribes to topics shared with them.')],
                                'admin' => ['icon' => 'shield', 'text' => __('Full access: users, settings and every topic.')],
                            ] as $value => $option)
                                <label class="flex cursor-pointer gap-3 rounded-2xl border border-zinc-200 p-4 transition has-checked:border-brand-500 has-checked:bg-brand-50/50 has-checked:ring-4 has-checked:ring-brand-500/10 dark:border-zinc-800 dark:has-checked:bg-brand-500/5">
                                    <input type="radio" name="role" value="{{ $value }}" class="mt-1 accent-brand-600" @checked(old('role', $user->role->value) === $value)>
                                    <span>
                                        <span class="block text-sm font-semibold text-zinc-900 dark:text-white">{{ \App\Enums\Role::from($value)->label() }}</span>
                                        <span class="mt-0.5 block text-sm text-zinc-500 dark:text-zinc-400">{{ $option['text'] }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('role')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                    </fieldset>
                    <div class="border-t border-zinc-100 pt-6 dark:border-zinc-800">
                        <x-ui.toggle name="is_active" :checked="$user->is_active" :label="__('Active account')" :description="__('Disabled users cannot log in, their tokens are revoked and their devices stop receiving private topics.')" />
                    </div>
                </div>
            </x-settings.section>
        </form>

        @if (\App\Support\AccountQuota::enabled() && ! $user->isAdmin())
            @php
                $payments = $user->payments()->latest()->limit(5)->get();
                $plans = app(\App\Support\RelayPlans::class);
            @endphp
            <form method="POST" action="{{ route('admin.users.plan', $user) }}">
                @csrf
                @method('PUT')
                <x-settings.section :title="__('Plan')" :description="__(':used of :limit messages today · :topics topics', ['used' => number_format($user->messagesToday(), 0, ',', ' '), 'limit' => number_format($user->effectiveDailyLimit(), 0, ',', ' '), 'topics' => $user->ownedTopics()->count()])" :fields="['plan', 'daily_limit', 'plan_expires_at']" :save-label="__('Save plan')" icon="sparkles">
                    <x-slot:summary>
                        <x-settings.summary>
                            <x-settings.value :label="__('Plan')" :value="['free' => __('Free'), 'pro' => 'Pro', 'business' => 'Business'][$user->plan ?? 'free'] ?? ucfirst((string) $user->plan)" />
                            <x-settings.value :label="__('Daily limit')" :value="number_format($user->effectiveDailyLimit(), 0, ',', ' ').($user->daily_limit !== null ? ' · '.__('custom') : '')" />
                            <x-settings.value :label="__('Paid until')" :value="$user->plan_expires_at?->isoFormat('LL')" :empty="($user->plan ?? 'free') === 'free' ? '—' : __('Until you change it')" />
                        </x-settings.summary>
                        @if ($payments->isNotEmpty())
                            <ul class="mt-6 space-y-1.5 border-t border-zinc-100 pt-5 text-sm dark:border-zinc-800">
                                @foreach ($payments as $payment)
                                    <li class="flex flex-wrap gap-x-3 text-zinc-600 dark:text-zinc-400">
                                        <span class="font-medium text-zinc-900 dark:text-white">{{ ucfirst($payment->plan) }}</span>
                                        <span class="tabular-nums">{{ number_format($payment->amount, 0, ',', ' ') }} {{ $payment->currency }}</span>
                                        <span>{{ $payment->provider }}</span>
                                        <span class="font-mono text-xs">{{ $payment->reference }}</span>
                                        <span class="flex-1 text-right">{{ $payment->status }} · {{ ($payment->paid_at ?? $payment->created_at)->isoFormat('LL') }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </x-slot:summary>
                    <div class="grid gap-5 sm:grid-cols-3">
                        <x-ui.select name="plan" :label="__('Plan')" :value="$user->plan ?? 'free'" :options="['free' => __('Free'), 'pro' => 'Pro', 'business' => 'Business']" />
                        <x-ui.input name="daily_limit" type="number" min="0" :label="__('Custom daily limit')" :value="$user->daily_limit" :placeholder="number_format($plans->dailyLimit($user->plan ?? 'free'), 0, ',', ' ')" :hint="__('Empty: the plan limit.')" />
                        <x-ui.input name="plan_expires_at" type="date" :label="__('Paid until')" :value="$user->plan_expires_at?->toDateString()" :hint="__('Empty: until you change it.')" />
                    </div>
                </x-settings.section>
            </form>
        @endif

        @unless ($user->is(auth()->user()))
            <section class="rounded-2xl border border-red-200 bg-red-50/40 p-5 sm:p-6 dark:border-red-500/20 dark:bg-red-500/5">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 class="font-semibold text-red-900 dark:text-red-200">{{ __('Delete this user') }}</h2>
                        <p class="mt-1 text-sm text-red-800/80 dark:text-red-200/70">{{ __('The account is deleted permanently. Topics it owns are kept, without owner.') }}</p>
                    </div>
                    <form method="POST" action="{{ route('admin.users.destroy', $user) }}" data-confirm-title="{{ __('Delete :name permanently?', ['name' => $user->name]) }}" data-confirm="{{ __('This cannot be undone.') }}" data-confirm-action="{{ __('Delete user') }}">
                        @csrf
                        @method('DELETE')
                        <x-ui.button variant="danger" icon="trash">{{ __('Delete user') }}</x-ui.button>
                    </form>
                </div>
            </section>
        @endunless
    </div>
</x-layouts.app>

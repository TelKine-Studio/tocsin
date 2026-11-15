{{-- Sidebar content, shared by the desktop sidebar and the mobile drawer. --}}
@php
    $user = auth()->user();
    $items = [
        ['route' => 'dashboard', 'match' => 'dashboard', 'icon' => 'home', 'label' => __('Dashboard')],
        ['route' => 'topics.index', 'match' => 'topics.*', 'icon' => 'hash', 'label' => __('Topics'), 'count' => $nav['topics']],
    ];
    if ($nav['quota']) {
        $items[] = ['route' => 'account.plan', 'match' => 'account.plan', 'icon' => 'sparkles', 'label' => __('My plan')];
    }
    $admin = [
        ['route' => 'admin.users.index', 'match' => 'admin.users.*', 'icon' => 'users', 'label' => __('Users'), 'count' => $nav['invitations'] ?: null],
        ['route' => 'admin.audit', 'match' => 'admin.audit', 'icon' => 'shield', 'label' => __('Audit log')],
        ['route' => 'admin.settings.edit', 'match' => 'admin.settings.*', 'icon' => 'settings', 'label' => __('Settings')],
    ];
    if ($nav['relay']) {
        array_splice($admin, 2, 0, [['route' => 'admin.relay-clients.index', 'match' => 'admin.relay-clients.*', 'icon' => 'layers', 'label' => __('Relay')]]);
    }
@endphp
<div class="flex h-full flex-col">
    <a href="{{ route('dashboard') }}" class="flex items-center gap-3 px-5 pt-6 pb-5">
        <x-ui.logo class="size-9" />
        <span class="min-w-0">
            <span class="block truncate text-[15px] font-semibold tracking-tight text-zinc-900 dark:text-white">{{ config('app.name') }}</span>
            <span class="block text-xs text-zinc-500">{{ __('Notification server') }}</span>
        </span>
    </a>

    <nav class="flex-1 space-y-7 overflow-y-auto px-3 pb-4">
        <div class="space-y-1">
            @foreach ($items as $item)
                <a href="{{ route($item['route']) }}" @class(['nav-link', 'nav-link-active' => request()->routeIs($item['match'])])>
                    <x-ui.icon :name="$item['icon']" class="size-[18px]" />
                    <span class="flex-1">{{ $item['label'] }}</span>
                    @if ($item['count'] ?? null)
                        <span class="rounded-md bg-zinc-100 px-1.5 py-0.5 text-[11px] font-semibold text-zinc-500 tabular-nums dark:bg-white/10 dark:text-zinc-400 [.nav-link-active_&]:bg-white/15 [.nav-link-active_&]:text-current">{{ $item['count'] }}</span>
                    @endif
                </a>
            @endforeach
        </div>

        @can('admin')
            <div class="space-y-1">
                <p class="eyebrow px-3 pb-1.5">{{ __('Administration') }}</p>
                @foreach ($admin as $item)
                    <a href="{{ route($item['route']) }}" @class(['nav-link', 'nav-link-active' => request()->routeIs($item['match'])])>
                        <x-ui.icon :name="$item['icon']" class="size-[18px]" />
                        <span class="flex-1">{{ $item['label'] }}</span>
                        @if ($item['count'] ?? null)
                            <span class="rounded-md bg-brand-100 px-1.5 py-0.5 text-[11px] font-semibold text-brand-700 tabular-nums dark:bg-brand-500/15 dark:text-brand-300">{{ $item['count'] }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endcan
    </nav>

    <div class="space-y-3 p-3">
        <p class="px-2 text-[11px] text-zinc-400 dark:text-zinc-600">
            Tocsin {{ config('tocsin.version') }} · {{ __('a project by') }} <a href="{{ config('tocsin.publisher_url') }}" class="font-medium hover:text-zinc-600 dark:hover:text-zinc-400">{{ config('tocsin.publisher') }}</a>
        </p>
        @if ($nav['quota'])
            @php
                ['used' => $used, 'limit' => $limit] = $nav['quota'];
                $percent = $limit > 0 ? min(100, (int) round($used / $limit * 100)) : 0;
            @endphp
            <a href="{{ route('account.plan') }}" class="block rounded-2xl border border-zinc-200 bg-white p-3.5 transition hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="flex items-center justify-between text-xs text-zinc-500"><span>{{ __('Today') }}</span><span class="tabular-nums">{{ number_format($used, 0, ',', ' ') }} / {{ number_format($limit, 0, ',', ' ') }}</span></p>
                <span class="mt-2 block h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><span @class(['block h-full rounded-full', 'bg-red-500' => $percent >= 100, 'bg-amber-500' => $percent >= 80 && $percent < 100, 'bg-brand-600' => $percent < 80]) style="width: {{ $percent }}%"></span></span>
                @if ($percent >= 80)<p class="mt-2 text-xs font-medium text-brand-700 dark:text-brand-300">{{ __('Upgrade') }} →</p>@endif
            </a>
        @endif
        @if (! $nav['push'] && ! $nav['quota'])
            <a href="{{ $user->isAdmin() ? route('admin.settings.edit', 'push') : '#' }}" class="block rounded-2xl border border-amber-200 bg-amber-50 p-3.5 transition hover:border-amber-300 dark:border-amber-500/20 dark:bg-amber-500/10">
                <p class="flex items-center gap-2 text-sm font-semibold text-amber-900 dark:text-amber-200"><x-ui.icon name="apple" class="size-4" />{{ __('Push not configured') }}</p>
                <p class="mt-1 text-xs leading-relaxed text-amber-800/80 dark:text-amber-200/70">
                    {{ $user->isAdmin() ? __('Connect the Telkine relay in one click to deliver notifications to iPhones.') : __('Ask an administrator to configure Apple Push notifications.') }}
                </p>
            </a>
        @endif

        <x-ui.dropdown align="top" width="w-full">
            <x-slot:trigger>
                <button type="button" class="flex w-full items-center gap-3 rounded-xl p-2 text-left transition hover:bg-zinc-100 dark:hover:bg-white/5">
                    <x-ui.avatar :user="$user" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $user->name }}</span>
                        <span class="block truncate text-xs text-zinc-500">{{ $user->email }}</span>
                    </span>
                    <x-ui.icon name="chevrons-up-down" class="size-4 text-zinc-400" />
                </button>
            </x-slot:trigger>

            <x-ui.dropdown-item :href="route('profile.edit')" icon="user">{{ __('Profile') }}</x-ui.dropdown-item>
            <x-ui.dropdown-item :href="route('profile.edit').'#devices'" icon="smartphone">{{ __('My devices') }}</x-ui.dropdown-item>
            <div class="my-1.5 h-px bg-zinc-100 dark:bg-zinc-800"></div>
            <div class="px-2.5 py-1.5">
                <p class="mb-2 text-xs font-medium text-zinc-500">{{ __('Theme') }}</p>
                <div class="grid grid-cols-3 gap-1 rounded-lg bg-zinc-100 p-1 dark:bg-white/5">
                    @foreach (['light' => 'sun', 'dark' => 'moon', 'system' => 'monitor'] as $theme => $icon)
                        <button type="button" @click="$store.theme.set('{{ $theme }}')" title="{{ __(ucfirst($theme)) }}"
                            class="inline-flex items-center justify-center rounded-md py-1.5 text-zinc-500 transition"
                            :class="$store.theme.value === '{{ $theme }}' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white' : 'hover:text-zinc-800 dark:hover:text-zinc-200'">
                            <x-ui.icon :name="$icon" class="size-4" />
                        </button>
                    @endforeach
                </div>
            </div>
            <div class="my-1.5 h-px bg-zinc-100 dark:bg-zinc-800"></div>
            <x-ui.dropdown-item :href="config('tocsin.repository').'/blob/main/docs/api.md'" icon="book" target="_blank" rel="noopener">{{ __('API documentation') }}</x-ui.dropdown-item>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <x-ui.dropdown-item icon="logout" danger>{{ __('Log out') }}</x-ui.dropdown-item>
            </form>
        </x-ui.dropdown>
    </div>
</div>

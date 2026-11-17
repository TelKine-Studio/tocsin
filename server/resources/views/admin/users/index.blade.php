<x-layouts.app :title="__('Users')">
    <x-ui.page-header :title="__('Users')" :description="__('Add people with their email: they receive a link to choose their own password.')">
        <x-ui.button type="button" icon="user-plus" x-data @click="$dispatch('open-modal', 'add-user')">{{ __('Add a user') }}</x-ui.button>
    </x-ui.page-header>

    <x-ui.modal name="add-user" :title="__('Add a user')" :description="__('No password to type: the person receives a link to choose it.')" :open="$errors->hasAny(['name', 'email', 'role'])">
        <form method="POST" action="{{ route('admin.invitations.store') }}" class="space-y-5">
            @csrf
            <x-ui.input name="name" :label="__('Full name')" required icon="user" autocomplete="off" />
            <x-ui.input name="email" type="email" :label="__('Email')" required icon="mail" placeholder="name@example.com" autocomplete="off" />
            <x-ui.select name="role" :label="__('Role')" value="user" :options="collect($roles)->mapWithKeys(fn ($r) => [$r->value => $r->label()])" />
            @if ($mailConfigured)
                <x-ui.toggle name="send_email" :checked="true" :label="__('Send the link by email')" :description="__('The link is also shown afterwards so you can share it another way.')" />
            @else
                <div class="flex gap-3 rounded-xl bg-zinc-50 p-3 text-xs leading-relaxed text-zinc-500 dark:bg-white/[.03]">
                    <x-ui.icon name="info" class="mt-0.5 size-4 shrink-0" />
                    <span>{!! __('Email is not configured: you will get a link to share yourself. :link', ['link' => '<a href="'.route('admin.settings.edit', 'mail').'" class="font-medium text-brand-600 hover:underline dark:text-brand-400">'.e(__('Configure email')).'</a>']) !!}</span>
                </div>
            @endif
            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button type="button" variant="secondary" @click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button icon="check">{{ __('Add the user') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    @if (session('invitation_url'))
        <section class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 sm:p-6 dark:border-emerald-500/20 dark:bg-emerald-500/10">
            <h2 class="flex items-center gap-2 font-semibold text-emerald-900 dark:text-emerald-200"><x-ui.icon name="check-circle" class="size-5" />{{ __('Activation link for :email', ['email' => session('invitation_email')]) }}</h2>
            <p class="mt-1 mb-4 text-sm text-emerald-800/80 dark:text-emerald-200/70">{{ __('Valid for :days days, shown only once. The person opens it to choose their password.', ['days' => App\Models\Invitation::VALID_DAYS]) }}</p>
            <x-ui.code :label="__('Link')">{{ session('invitation_url') }}</x-ui.code>
        </section>
    @endif

    <div class="card overflow-hidden" x-data="liveList('user-results')" data-live-also="user-count">
        <form method="GET" data-live class="flex items-center gap-3 border-b border-zinc-100 p-4 sm:px-6 dark:border-zinc-800">
            <div class="relative w-full max-w-sm">
                <x-ui.icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-zinc-400" />
                <x-ui.icon name="refresh" class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin text-zinc-400" x-show="loading" x-cloak />
                <input type="search" name="q" autocomplete="off" value="{{ $search }}" placeholder="{{ __('Search by name or email…') }}" class="form-control pl-10">
            </div>
            <span id="user-count" class="ml-auto hidden text-sm text-zinc-500 sm:block">
                {{ trans_choice(':count user|:count users', $users->total()) }}@if ($invitations->isNotEmpty()) · {{ trans_choice(':count pending|:count pending', $invitations->count()) }}@endif
            </span>
        </form>

        <div id="user-results" class="transition-opacity" :class="loading && 'opacity-60'">

        @php
            $quota = \App\Support\AccountQuota::enabled();
            $cols = $quota
                ? 'lg:grid-cols-[minmax(0,1.6fr)_120px_150px_110px_140px_110px_32px]'
                : 'lg:grid-cols-[minmax(0,1.6fr)_120px_110px_140px_110px_32px]';
        @endphp
        @if ($users->isNotEmpty() || $invitations->isNotEmpty())
            <div class="hidden gap-4 border-b border-zinc-200 bg-zinc-50 px-6 py-2.5 text-xs font-medium text-zinc-500 lg:grid {{ $cols }} dark:border-zinc-800 dark:bg-white/[.03]">
                <span>{{ __('User') }}</span><span>{{ __('Role') }}</span>@if ($quota)<span>{{ __('Plan') }}</span>@endif<span>{{ __('Devices') }}</span><span>{{ __('Last login') }}</span><span>{{ __('State') }}</span><span></span>
            </div>
        @endif

        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
            {{-- Pending activations --}}
            @foreach ($invitations as $invitation)
                <div class="relative grid items-center gap-x-4 gap-y-2 bg-amber-50/40 px-4 py-3 sm:px-6 lg:grid {{ $cols }} dark:bg-amber-500/[.03]">
                    <div class="flex min-w-0 items-center gap-3 pr-10 lg:pr-0">
                        <x-ui.avatar :name="$invitation->name ?? $invitation->email" size="sm" class="opacity-60" />
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $invitation->name ?? $invitation->email }}</p>
                            <p class="truncate text-xs text-zinc-500">{{ $invitation->email }}</p>
                        </div>
                    </div>
                    <span class="hidden lg:block"><x-ui.badge :color="$invitation->role === App\Enums\Role::Admin ? 'brand' : 'zinc'">{{ $invitation->role->label() }}</x-ui.badge></span>
                    @if ($quota)<span class="hidden text-xs text-zinc-400 lg:block">—</span>@endif
                    <span class="hidden text-xs text-zinc-400 lg:block">—</span>
                    <span class="hidden text-xs text-zinc-500 lg:block">{{ __('Link expires :date', ['date' => $invitation->expires_at->diffForHumans()]) }}</span>
                    <span class="pl-11 lg:pl-0"><x-ui.badge color="amber" dot>{{ __('Awaiting activation') }}</x-ui.badge></span>
                    <span class="absolute top-3 right-3 lg:static">
                        <x-ui.dropdown width="w-56">
                            <x-slot:trigger>
                                <x-ui.button type="button" variant="ghost" size="icon" icon="more"><span class="sr-only">{{ __('Actions') }}</span></x-ui.button>
                            </x-slot:trigger>
                            <form method="POST" action="{{ route('admin.invitations.resend', $invitation) }}">
                                @csrf
                                <input type="hidden" name="send_email" value="1">
                                <x-ui.dropdown-item icon="send">{{ $mailConfigured ? __('Resend the link') : __('New activation link') }}</x-ui.dropdown-item>
                            </form>
                            <form method="POST" action="{{ route('admin.invitations.destroy', $invitation) }}" data-confirm-title="{{ __('Cancel the addition of :name?', ['name' => $invitation->name ?? $invitation->email]) }}" data-confirm="{{ __('The activation link will stop working.') }}" data-confirm-action="{{ __('Cancel the addition') }}">
                                @csrf
                                @method('DELETE')
                                <x-ui.dropdown-item icon="x" danger>{{ __('Cancel the addition') }}</x-ui.dropdown-item>
                            </form>
                        </x-ui.dropdown>
                    </span>
                </div>
            @endforeach

            {{-- Active accounts --}}
            @foreach ($users as $user)
                <a href="{{ route('admin.users.edit', $user) }}" class="grid items-center gap-x-4 gap-y-2 px-4 py-3 transition hover:bg-zinc-50/80 sm:px-6 lg:grid {{ $cols }} dark:hover:bg-white/[.02]">
                    <div class="flex min-w-0 items-center gap-3">
                        <x-ui.avatar :user="$user" size="sm" />
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $user->name }} @if ($user->is(auth()->user()))<span class="text-xs font-normal text-zinc-400">({{ __('you') }})</span>@endif</p>
                            <p class="truncate text-xs text-zinc-500">{{ $user->email }}</p>
                        </div>
                    </div>
                    <span class="hidden lg:block"><x-ui.badge :color="$user->isAdmin() ? 'brand' : 'zinc'">{{ $user->role->label() }}</x-ui.badge></span>
                    @if ($quota)
                        <span class="hidden text-xs text-zinc-500 lg:block" title="{{ __('Messages today') }}">
                            @if ($user->isAdmin())
                                —
                            @else
                                <x-ui.badge :color="$user->isOnPaidPlan() ? 'violet' : 'zinc'">{{ ucfirst($user->plan ?? 'free') }}</x-ui.badge>
                                <span class="ml-1 tabular-nums">{{ number_format((int) $user->messages_today, 0, ',', ' ') }} / {{ number_format($user->effectiveDailyLimit(), 0, ',', ' ') }}</span>
                            @endif
                        </span>
                    @endif
                    <span class="hidden text-xs text-zinc-600 tabular-nums lg:block dark:text-zinc-400">{{ trans_choice(':count device|:count devices', $user->devices_count) }}</span>
                    <span class="hidden text-xs text-zinc-600 lg:block dark:text-zinc-400" title="{{ $user->last_login_at?->isoFormat('LLL') }}">{{ $user->last_login_at ? $user->last_login_at->diffForHumans() : __('Never logged in') }}</span>
                    <span class="flex flex-wrap items-center gap-2 pl-11 lg:pl-0">
                        <span class="lg:hidden"><x-ui.badge :color="$user->isAdmin() ? 'brand' : 'zinc'">{{ $user->role->label() }}</x-ui.badge></span>
                        <x-ui.badge :color="$user->is_active ? 'green' : 'red'" dot>{{ $user->is_active ? __('Active') : __('Disabled') }}</x-ui.badge>
                    </span>
                    <x-ui.icon name="chevron-right" class="hidden size-4 text-zinc-300 lg:block dark:text-zinc-600" />
                </a>
            @endforeach
        </div>

        @if ($users->isEmpty() && $invitations->isEmpty())
            <x-ui.empty icon="users" :title="__('No user matches your search')" />
        @endif
        @if ($users->hasPages())
            <div data-live-pages class="border-t border-zinc-100 px-6 py-4 dark:border-zinc-800">{{ $users->links() }}</div>
        @endif
        </div>
    </div>
</x-layouts.app>

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

    <div class="card overflow-hidden">
        <form method="GET" class="flex items-center gap-3 border-b border-zinc-100 p-4 sm:px-6 dark:border-zinc-800">
            <div class="relative w-full max-w-sm">
                <x-ui.icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-zinc-400" />
                <input type="search" name="q" value="{{ $search }}" placeholder="{{ __('Search by name or email…') }}" class="form-control pl-10">
            </div>
            <span class="ml-auto hidden text-sm text-zinc-500 sm:block">
                {{ trans_choice(':count user|:count users', $users->total()) }}@if ($invitations->isNotEmpty()) · {{ trans_choice(':count pending|:count pending', $invitations->count()) }}@endif
            </span>
        </form>

        <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
            {{-- Pending activations --}}
            @foreach ($invitations as $invitation)
                <li class="flex flex-wrap items-center gap-x-4 gap-y-2 bg-amber-50/40 px-4 py-3.5 sm:px-6 dark:bg-amber-500/[.03]">
                    <x-ui.avatar :name="$invitation->name ?? $invitation->email" class="opacity-60" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $invitation->name ?? $invitation->email }}</p>
                        <p class="truncate text-xs text-zinc-500">{{ $invitation->email }}</p>
                    </div>
                    <x-ui.badge :color="$invitation->role === App\Enums\Role::Admin ? 'brand' : 'zinc'" class="hidden sm:inline-flex">{{ $invitation->role->label() }}</x-ui.badge>
                    <x-ui.badge color="amber" dot title="{{ __('Link expires :date', ['date' => $invitation->expires_at->diffForHumans()]) }}">{{ __('Awaiting activation') }}</x-ui.badge>
                    <x-ui.dropdown width="w-56">
                        <x-slot:trigger>
                            <x-ui.button type="button" variant="ghost" size="icon" icon="more" class="size-8"><span class="sr-only">{{ __('Actions') }}</span></x-ui.button>
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
                </li>
            @endforeach

            {{-- Active accounts --}}
            @foreach ($users as $user)
                <li>
                    <a href="{{ route('admin.users.edit', $user) }}" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3.5 transition hover:bg-zinc-50/80 sm:px-6 dark:hover:bg-white/[.02]">
                        <x-ui.avatar :user="$user" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $user->name }} @if ($user->is(auth()->user()))<span class="text-xs font-normal text-zinc-400">({{ __('you') }})</span>@endif</p>
                            <p class="truncate text-xs text-zinc-500">{{ $user->email }}</p>
                        </div>
                        @if (\App\Support\AccountQuota::enabled() && ! $user->isAdmin())
                            <span class="hidden w-40 text-xs text-zinc-500 md:block" title="{{ __('Messages today') }}">
                                <x-ui.badge :color="$user->isOnPaidPlan() ? 'violet' : 'zinc'">{{ ucfirst($user->plan ?? 'free') }}</x-ui.badge>
                                <span class="ml-1 tabular-nums">{{ number_format((int) $user->messages_today, 0, ',', ' ') }} / {{ number_format($user->effectiveDailyLimit(), 0, ',', ' ') }}</span>
                            </span>
                        @endif
                        <span class="hidden w-28 text-xs text-zinc-500 md:block" title="{{ __('Devices') }}"><x-ui.icon name="smartphone" class="mr-1 inline size-3.5" />{{ trans_choice(':count device|:count devices', $user->devices_count) }}</span>
                        <span class="hidden w-36 text-xs text-zinc-500 lg:block">{{ $user->last_login_at ? __('Seen :date', ['date' => $user->last_login_at->diffForHumans()]) : __('Never logged in') }}</span>
                        <x-ui.badge :color="$user->isAdmin() ? 'brand' : 'zinc'" class="hidden sm:inline-flex">{{ $user->role->label() }}</x-ui.badge>
                        <x-ui.badge :color="$user->is_active ? 'green' : 'red'" dot>{{ $user->is_active ? __('Active') : __('Disabled') }}</x-ui.badge>
                        <x-ui.icon name="chevron-right" class="size-4 text-zinc-300" />
                    </a>
                </li>
            @endforeach
        </ul>

        @if ($users->isEmpty() && $invitations->isEmpty())
            <x-ui.empty icon="users" :title="__('No user matches your search')" />
        @endif
        @if ($users->hasPages())
            <div class="border-t border-zinc-100 px-6 py-4 dark:border-zinc-800">{{ $users->links() }}</div>
        @endif
    </div>
</x-layouts.app>

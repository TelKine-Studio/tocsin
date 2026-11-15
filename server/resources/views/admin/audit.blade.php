<x-layouts.app :title="__('Audit log')">
    <x-ui.page-header :title="__('Audit log')" :description="__('Security-relevant actions: logins, permissions, tokens and settings. Kept :days days.', ['days' => App\Models\AuditLog::RETENTION_DAYS])" />

    <div class="card overflow-hidden">
        <form method="GET" class="flex flex-col gap-3 border-b border-zinc-100 p-4 sm:flex-row sm:px-6 dark:border-zinc-800">
            <div class="relative w-full max-w-sm">
                <x-ui.icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-zinc-400" />
                <input type="search" name="q" value="{{ $search }}" placeholder="{{ __('Search by user, subject or IP…') }}" class="form-control pl-10">
            </div>
            <x-ui.select name="action" :options="$groups" :value="$action" onchange="this.form.submit()" class="sm:w-56" />
        </form>

        <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse ($logs as $log)
                @php
                    $danger = in_array($log->action, ['auth.login_failed', 'auth.2fa_failed', 'auth.2fa_disabled', 'user.deleted', 'topic.deleted', 'token.revoked'], true);
                @endphp
                <li class="flex flex-wrap items-start gap-x-4 gap-y-1 px-4 py-3 text-sm sm:px-6">
                    <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-red-500' => $danger, 'bg-zinc-300 dark:bg-zinc-600' => ! $danger])></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-zinc-900 dark:text-white">
                            <span class="font-medium">{{ App\Support\Audit::label($log->action) }}</span>
                            @if ($log->description)<span class="text-zinc-500"> · {{ $log->description }}</span>@endif
                        </p>
                        <p class="mt-0.5 truncate text-xs text-zinc-500">
                            {{ $log->user?->name ?? __('Unknown or guest') }}
                            @if ($log->ip_address) · {{ $log->ip_address }}@endif
                            @if ($log->metadata) · <span class="font-mono">{{ \Illuminate\Support\Str::limit(json_encode($log->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 120) }}</span>@endif
                        </p>
                    </div>
                    <time class="text-xs text-zinc-400" datetime="{{ $log->created_at->toIso8601String() }}" title="{{ $log->created_at->isoFormat('LLLL') }}">{{ $log->created_at->diffForHumans() }}</time>
                </li>
            @empty
                <x-ui.empty icon="shield" :title="__('No event')" />
            @endforelse
        </ul>
        @if ($logs->hasPages())
            <div class="border-t border-zinc-100 px-6 py-4 dark:border-zinc-800">{{ $logs->links() }}</div>
        @endif
    </div>
</x-layouts.app>

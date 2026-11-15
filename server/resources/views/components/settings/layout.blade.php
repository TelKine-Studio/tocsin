@props(['section'])
{{-- Shared two-column layout of the settings pages. --}}
<div class="grid gap-8 lg:grid-cols-[260px_minmax(0,1fr)]">
    <nav class="hidden lg:block">
        <ul class="sticky top-24 space-y-1">
            @foreach ([
                'general' => ['icon' => 'settings', 'label' => __('General'), 'text' => __('Name, URL, access')],
                'security' => ['icon' => 'shield', 'label' => __('Security'), 'text' => __('2FA, single sign-on')],
                'push' => ['icon' => 'apple', 'label' => __('Push notifications'), 'text' => __('Relay or APNs key')],
                'mail' => ['icon' => 'mail', 'label' => __('Email'), 'text' => __('Outgoing mail server')],
                'realtime' => ['icon' => 'radio', 'label' => __('Realtime'), 'text' => __('WebSocket server')],
                'relay' => ['icon' => 'layers', 'label' => __('Relay'), 'text' => __('Serve other instances')],
            ] as $key => $tab)
                {{-- Selling the relay is reserved to the instance operated by Telkine. --}}
                @continue($key === 'relay' && ! config('tocsin.relay_operator') && ! config('tocsin.hosted'))
                <li>
                    <a href="{{ route('admin.settings.edit', $key) }}" @class(['flex items-start gap-3 rounded-xl p-3 transition', 'bg-white shadow-(--shadow-soft) ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-zinc-800' => $section === $key, 'hover:bg-zinc-100 dark:hover:bg-white/5' => $section !== $key])>
                        <span @class(['inline-flex size-8 shrink-0 items-center justify-center rounded-lg', 'bg-brand-600 text-white' => $section === $key, 'bg-zinc-100 text-zinc-500 dark:bg-white/5 dark:text-zinc-400' => $section !== $key])><x-ui.icon :name="$tab['icon']" class="size-4" /></span>
                        <span>
                            <span class="block text-sm font-medium text-zinc-900 dark:text-white">{{ $tab['label'] }}</span>
                            <span class="block text-xs text-zinc-500">{{ $tab['text'] }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
    <div class="min-w-0 max-w-3xl space-y-6">
        {{ $slot }}
    </div>
</div>

@php
    $tabs = ['messages' => ['icon' => 'inbox', 'label' => __('Messages')], 'integrate' => ['icon' => 'code', 'label' => __('Integration')]];
    if ($canManage) {
        $tabs['access'] = ['icon' => 'users', 'label' => __('Access')];
    }
    $token = '<token>';
    $snippets = [
        'curl' => ['label' => 'cURL', 'code' => "curl -u :{$token} \\\n  -H \"X-Title: Backup completed\" \\\n  -H \"X-Priority: high\" \\\n  -H \"X-Tags: backup,prod\" \\\n  -d \"Nightly backup finished in 42s\" \\\n  {$apiUrl}"],
        'python' => ['label' => 'Python', 'code' => "import requests\n\nrequests.post(\n    \"{$apiUrl}\",\n    headers={\"Authorization\": \"Bearer {$token}\"},\n    json={\"title\": \"Backup completed\", \"message\": \"Nightly backup finished in 42s\", \"priority\": 4},\n)"],
        'js' => ['label' => 'JavaScript', 'code' => "await fetch(\"{$apiUrl}\", {\n  method: \"POST\",\n  headers: { Authorization: \"Bearer {$token}\", \"Content-Type\": \"application/json\" },\n  body: JSON.stringify({ title: \"Backup completed\", message: \"Nightly backup finished in 42s\", priority: 4 }),\n});"],
        'php' => ['label' => 'PHP', 'code' => "Http::withToken('{$token}')->post('{$apiUrl}', [\n    'title' => 'Backup completed',\n    'message' => 'Nightly backup finished in 42s',\n    'priority' => 4,\n]);"],
    ];
@endphp
<x-layouts.app :title="$topic->label()">
    <nav class="mb-4 flex items-center gap-1.5 text-sm text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('topics.index') }}" class="hover:text-zinc-900 dark:hover:text-white">{{ __('Topics') }}</a>
        <x-ui.icon name="chevron-right" class="size-3.5" />
        <span class="truncate">{{ $topic->label() }}</span>
    </nav>

    <section class="card mb-6 p-5 sm:p-6">
        <div class="flex flex-col gap-5 sm:flex-row sm:items-start">
            <x-ui.topic-avatar :topic="$topic" size="lg" />
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $topic->label() }}</h1>
                    <x-ui.badge :color="$topic->isPublic() ? 'blue' : 'zinc'" :icon="$topic->isPublic() ? 'globe' : 'lock'">{{ $topic->visibility->label() }}</x-ui.badge>
                </div>
                <div x-data="copy(@js($topic->name))" class="mt-1">
                    <button type="button" @click="copy()" class="group inline-flex items-center gap-1.5 font-mono text-sm text-zinc-500 hover:text-zinc-900 dark:hover:text-white">
                        #{{ $topic->name }}
                        <x-ui.icon name="copy" class="size-3.5 opacity-0 transition group-hover:opacity-100" x-show="!copied" />
                        <x-ui.icon name="check" class="size-3.5 text-emerald-500" x-show="copied" x-cloak />
                    </button>
                </div>
                @if ($topic->description)
                    <p class="mt-3 max-w-2xl text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">{{ $topic->description }}</p>
                @endif
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <div x-data="webPush(@js($topic->name), @js(route('topics.web-push', $topic)))" x-show="supported" x-cloak>
                    <button type="button" @click="toggle()" :disabled="busy || denied"
                        class="inline-flex items-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-semibold shadow-xs transition disabled:opacity-60"
                        :class="enabled ? 'border-brand-200 bg-brand-50 text-brand-700 dark:border-brand-500/30 dark:bg-brand-500/10 dark:text-brand-300' : 'border-zinc-200 bg-white text-zinc-700 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-200'"
                        :title="denied ? @js(__('Notifications are blocked in this browser.')) : ''">
                        <x-ui.icon name="bell" class="size-4" />
                        <span x-text="enabled ? @js(__('Browser notified')) : @js(__('Notify this browser'))">{{ __('Notify this browser') }}</span>
                    </button>
                </div>
                @if ($canManage)
                    <x-ui.button variant="secondary" :href="route('topics.edit', $topic)" icon="settings">{{ __('Settings') }}</x-ui.button>
                @endif
            </div>
        </div>
        <dl class="mt-6 grid grid-cols-3 divide-x divide-zinc-100 rounded-xl border border-zinc-100 bg-zinc-50/60 dark:divide-zinc-800 dark:border-zinc-800 dark:bg-white/[.02]">
            @foreach ([
                ['label' => __('Messages'), 'value' => number_format($topic->messages_count)],
                ['label' => __('Subscribed devices'), 'value' => number_format($topic->devices_count)],
                ['label' => __('Last message'), 'value' => $topic->last_message_at?->diffForHumans(short: true) ?? '—'],
            ] as $item)
                <div class="px-4 py-3">
                    <dt class="truncate text-xs text-zinc-500">{{ $item['label'] }}</dt>
                    <dd class="mt-0.5 truncate text-base font-semibold text-zinc-900 tabular-nums dark:text-white">{{ $item['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <div x-data="{ tab: ['messages','integrate','access'].includes(location.hash.slice(1)) ? location.hash.slice(1) : @js($errors->has('email') ? 'access' : 'messages') }" x-init="$watch('tab', value => history.replaceState(null, '', '#' + value))">
        <div class="mb-6 flex gap-1 overflow-x-auto border-b border-zinc-200 dark:border-zinc-800">
            @foreach ($tabs as $key => $item)
                <button type="button" @click="tab = '{{ $key }}'"
                    class="-mb-px inline-flex items-center gap-2 border-b-2 px-3.5 py-3 text-sm font-medium whitespace-nowrap transition"
                    :class="tab === '{{ $key }}' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200'">
                    <x-ui.icon :name="$item['icon']" class="size-4" />{{ $item['label'] }}
                </button>
            @endforeach
        </div>

        {{-- Messages --}}
        <div x-show="tab === 'messages'" class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
            <div x-data="liveTopic(@js($topic->channelName()), {{ $topic->isPublic() ? 'false' : 'true' }})"
                @foreach ($priorities as $p) data-priority{{ $p->value }}="{{ $p->label() }}" @endforeach
                data-message-url="{{ route('topics.messages.show', [$topic, '__ID__']) }}"
                class="card self-start overflow-hidden">
                <div class="flex items-center justify-between gap-3 border-b border-zinc-100 px-5 py-4 sm:px-6 dark:border-zinc-800">
                    <div class="flex items-center gap-3">
                        <h2 class="font-semibold text-zinc-900 dark:text-white">{{ __('History') }}</h2>
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="state === 'connected' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-zinc-100 text-zinc-500 dark:bg-white/5'">
                            <span class="relative flex size-2">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60" x-show="state === 'connected'"></span>
                                <span class="relative inline-flex size-2 rounded-full" :class="state === 'connected' ? 'bg-emerald-500' : 'bg-zinc-400'"></span>
                            </span>
                            <span x-text="state === 'connected' ? @js(__('Live')) : @js(__('Offline'))">{{ __('Offline') }}</span>
                        </span>
                    </div>
                    @if ($canManage && $messages->isNotEmpty())
                        <form method="POST" action="{{ route('topics.messages.clear', $topic) }}" data-confirm-title="{{ __('Delete all messages of this topic?') }}" data-confirm="{{ __('The history is cleared for everyone. Subscribers keep the notifications already received.') }}" data-confirm-action="{{ __('Clear history') }}">
                            @csrf
                            @method('DELETE')
                            <x-ui.button variant="ghost" size="sm" icon="trash">{{ __('Clear') }}</x-ui.button>
                        </form>
                    @endif
                </div>

                <div x-ref="list">
                    @foreach ($messages as $message)
                        @include('topics.partials.message', ['message' => $message])
                    @endforeach
                </div>

                @if ($messages->isEmpty())
                    <div x-ref="empty">
                        <x-ui.empty icon="bell" :title="__('No messages yet')" :description="__('Publish a first message with the form, the API or a curl command. It appears here in real time.')" />
                    </div>
                @endif

                @if ($scheduled->isNotEmpty())
                    <div class="border-t border-zinc-100 bg-violet-50/40 px-5 py-4 sm:px-6 dark:border-zinc-800 dark:bg-violet-500/[.04]">
                        <p class="eyebrow mb-3 flex items-center gap-2"><x-ui.icon name="clock" class="size-3.5" />{{ __('Scheduled') }}</p>
                        <ul class="space-y-2">
                            @foreach ($scheduled as $item)
                                <li class="flex items-center gap-3 text-sm">
                                    <span class="min-w-0 flex-1 truncate text-zinc-700 dark:text-zinc-300">{{ $item->title ?: $item->body }}</span>
                                    <x-ui.badge color="violet">{{ $item->scheduled_at->isoFormat('lll') }}</x-ui.badge>
                                    <form method="POST" action="{{ route('topics.messages.destroy', [$topic, $item]) }}" data-confirm-title="{{ __('Cancel this scheduled message?') }}" data-confirm="{{ __('It will not be sent.') }}" data-confirm-action="{{ __('Cancel it') }}">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button variant="ghost-danger" size="icon" icon="x" class="size-7"><span class="sr-only">{{ __('Cancel') }}</span></x-ui.button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($messages->hasPages())
                    <div class="border-t border-zinc-100 px-5 py-4 dark:border-zinc-800">{{ $messages->links() }}</div>
                @endif
            </div>

            <template id="message-template">
                @include('topics.partials.message', ['message' => null])
            </template>

            <div class="space-y-6 xl:sticky xl:top-24 xl:self-start">
                @if ($canPublish)
                    <form method="POST" action="{{ route('topics.messages.store', $topic) }}" enctype="multipart/form-data" x-data="composer(@js($topic->label()))" class="card overflow-hidden">
                        <input type="hidden" name="timezone" x-init="$el.value = Intl.DateTimeFormat().resolvedOptions().timeZone">
                        @csrf
                        <div class="border-b border-zinc-100 bg-linear-to-br from-zinc-100 to-zinc-50 p-5 dark:border-zinc-800 dark:from-zinc-800/60 dark:to-zinc-900">
                            <p class="eyebrow mb-3">{{ __('Preview') }}</p>
                            <x-ui.iphone-notification live :title="$topic->label()" :icon="$topic->iconUrl()" />
                        </div>
                        <div class="space-y-4 p-5">
                            <x-ui.input name="title" :label="__('Title')" maxlength="255" x-model="title" optional :placeholder="__('Backup completed')" />
                            <div class="space-y-1.5">
                                <x-ui.textarea name="body" :label="__('Message')" rows="3" required :maxlength="$maxLength" x-model="body" :placeholder="__('What happened?')" />
                                <p class="text-right text-xs text-zinc-400 tabular-nums"><span x-text="body.length">0</span> / {{ $maxLength }}</p>
                            </div>
                            <fieldset>
                                <legend class="mb-1.5 text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ __('Priority') }}</legend>
                                <div class="grid grid-cols-5 gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-white/5">
                                    @foreach ($priorities as $p)
                                        <label class="cursor-pointer">
                                            <input type="radio" name="priority" value="{{ $p->value }}" x-model="priority" class="peer sr-only" @checked($p->value === 3)>
                                            <span class="block rounded-lg py-1.5 text-center text-xs font-medium text-zinc-500 transition peer-checked:bg-white peer-checked:text-zinc-900 peer-checked:shadow-sm dark:peer-checked:bg-zinc-700 dark:peer-checked:text-white"
                                                title="{{ $p->label() }}">{{ $p->label() }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                            <div x-data="{ more: {{ $errors->hasAny(['tags', 'click', 'attachment', 'send_at']) ? 'true' : 'false' }} }">
                                <button type="button" @click="more = !more" class="inline-flex items-center gap-1 text-sm font-medium text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">
                                    <x-ui.icon name="chevron-right" class="size-4 transition" ::class="more && 'rotate-90'" />{{ __('More options') }}
                                </button>
                                <div x-show="more" x-collapse class="space-y-4 pt-4">
                                    <x-ui.input name="tags" :label="__('Tags')" placeholder="backup, prod" :hint="__('Comma separated')" optional />
                                    <x-ui.input name="click" type="url" :label="__('Link')" placeholder="https://…" :hint="__('Opened when the notification is tapped.')" optional />
                                    <x-ui.input name="attachment" type="file" :label="__('Attachment')" :hint="__('An image is shown in the notification.')" optional class="[&_input]:py-2" />
                                    <x-ui.input name="send_at" type="datetime-local" :label="__('Send later')" :hint="__('Leave empty to send now.')" optional />
                                </div>
                            </div>
                        </div>
                        <div class="border-t border-zinc-100 bg-zinc-50/70 px-5 py-3.5 dark:border-zinc-800 dark:bg-white/[.02]">
                            <x-ui.button class="w-full" icon="send">{{ __('Send notification') }}</x-ui.button>
                        </div>
                    </form>
                @else
                    <x-ui.card>
                        <x-ui.empty icon="lock" :title="__('Read only')" :description="__('You can read this topic but not publish to it.')" class="py-6" />
                    </x-ui.card>
                @endif
            </div>
        </div>

        {{-- Integration --}}
        <div x-show="tab === 'integrate'" x-cloak class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
            <x-ui.card :title="__('Publish from your code')" :description="__('Send a POST request to the topic. Use an access token from your profile.')" icon="code">
                <div x-data="{ lang: 'curl' }">
                    <div class="mb-3 flex flex-wrap gap-1">
                        @foreach ($snippets as $key => $snippet)
                            <button type="button" @click="lang = '{{ $key }}'" class="rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                                :class="lang === '{{ $key }}' ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'text-zinc-500 hover:bg-zinc-100 dark:hover:bg-white/5'">{{ $snippet['label'] }}</button>
                        @endforeach
                    </div>
                    @foreach ($snippets as $key => $snippet)
                        <div x-show="lang === '{{ $key }}'" @if ($key !== 'curl') x-cloak @endif>
                            <x-ui.code :label="$snippet['label']">{{ $snippet['code'] }}</x-ui.code>
                        </div>
                    @endforeach
                </div>
                <div class="mt-6 grid gap-3 sm:grid-cols-2">
                    @foreach ([
                        ['X-Title', __('Notification title')],
                        ['X-Priority', __('1 (min) to 5 (urgent), or min/low/default/high/urgent')],
                        ['X-Tags', __('Comma separated tags')],
                        ['X-Click', __('URL opened when the notification is tapped')],
                    ] as [$header, $text])
                        <div class="rounded-xl border border-zinc-100 p-3 dark:border-zinc-800">
                            <code class="text-xs font-semibold text-zinc-900 dark:text-white">{{ $header }}</code>
                            <p class="mt-1 text-xs text-zinc-500">{{ $text }}</p>
                        </div>
                    @endforeach
                </div>
                @if ($topic->isPublic() && $topic->anonymous_publish)
                    <div class="mt-6"><x-ui.code :label="__('Anonymous')">curl -d "Hello" {{ $apiUrl }}</x-ui.code></div>
                @endif
                <p class="mt-6 text-sm text-zinc-500 dark:text-zinc-400">
                    {!! __('Create access tokens in your :profile.', ['profile' => '<a href="'.route('profile.edit').'#tokens" class="font-medium text-brand-600 hover:underline dark:text-brand-400">'.e(__('profile')).'</a>']) !!}
                </p>
            </x-ui.card>

            <x-ui.card :title="__('Subscribe on iPhone')" icon="smartphone">
                <ol class="space-y-5">
                    @foreach ([
                        [__('Open the Tocsin app'), __('Tap “Subscribe”.')],
                        [__('Enter the server'), $serverUrl],
                        [__('Enter the topic'), $topic->name],
                        [$topic->isPublic() ? __('That’s it') : __('Sign in'), $topic->isPublic() ? __('No account needed: notifications arrive right away.') : __('The topic is private: sign in with your account when asked.')],
                    ] as $index => [$title, $value])
                        <li class="flex gap-3">
                            <span class="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">{{ $index + 1 }}</span>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ $title }}</p>
                                @if (in_array($index, [1, 2]))
                                    <div x-data="copy(@js($value))" class="mt-1 flex items-center gap-2">
                                        <code class="truncate rounded-lg bg-zinc-100 px-2 py-1 font-mono text-xs text-zinc-700 dark:bg-white/5 dark:text-zinc-300">{{ $value }}</code>
                                        <button type="button" @click="copy()" class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"><x-ui.icon name="copy" class="size-3.5" x-show="!copied" /><x-ui.icon name="check" class="size-3.5 text-emerald-500" x-show="copied" x-cloak /></button>
                                    </div>
                                @else
                                    <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $value }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </x-ui.card>
        
            @if ($canManage)
                <x-ui.card :title="__('Webhooks')" :description="__('Connect Grafana, Alertmanager, GitHub, GitLab, Uptime Kuma, or any tool that can notify Slack or Discord: paste the URL in its webhook settings.')" icon="link" :padding="false" class="xl:col-span-2">
                    @forelse ($webhooks as $webhook)
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-zinc-100 px-5 py-4 first:border-0 sm:px-6 dark:border-zinc-800">
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-zinc-900 dark:text-white">{{ $webhook->name }} <x-ui.badge>{{ App\Models\TopicWebhook::sourceLabel($webhook->source) }}</x-ui.badge></p>
                                <div x-data="copy(@js($webhook->url()))" class="mt-1.5 flex min-w-0 items-center gap-2">
                                    <code class="min-w-0 truncate rounded-lg bg-zinc-100 px-2 py-1 font-mono text-xs text-zinc-600 dark:bg-white/5 dark:text-zinc-300">{{ $webhook->url() }}</code>
                                    <button type="button" @click="copy()" class="shrink-0 text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"><x-ui.icon name="copy" class="size-3.5" x-show="!copied" /><x-ui.icon name="check" class="size-3.5 text-emerald-500" x-show="copied" x-cloak /></button>
                                </div>
                            </div>
                            <span class="text-xs text-zinc-500">{{ trans_choice(':count call|:count calls', $webhook->calls) }} · {{ $webhook->last_used_at?->diffForHumans() ?? __('never used') }}</span>
                            <form method="POST" action="{{ route('topics.webhooks.destroy', [$topic, $webhook]) }}" data-confirm-title="{{ __('Delete this webhook?') }}" data-confirm="{{ __('The tool using this URL will no longer be able to publish.') }}" data-confirm-action="{{ __('Delete') }}">
                                @csrf
                                @method('DELETE')
                                <x-ui.button variant="ghost-danger" size="icon" icon="trash" class="size-8"><span class="sr-only">{{ __('Delete') }}</span></x-ui.button>
                            </form>
                        </div>
                    @empty
                        <p class="px-6 pt-2 pb-5 text-sm text-zinc-500">{{ __('No webhook yet.') }}</p>
                    @endforelse
                    <x-slot:footer>
                        <form method="POST" action="{{ route('topics.webhooks.store', $topic) }}" class="flex w-full flex-col gap-3 sm:flex-row sm:items-end">
                            @csrf
                            <x-ui.input name="name" :label="__('Name')" :placeholder="__('e.g. Grafana production')" required class="flex-1" />
                            <x-ui.select name="source" :label="__('Source')" value="auto" :options="collect(App\Models\TopicWebhook::SOURCES)->mapWithKeys(fn ($s) => [$s => App\Models\TopicWebhook::sourceLabel($s)])" class="sm:w-64" />
                            <x-ui.button icon="plus">{{ __('Create webhook') }}</x-ui.button>
                        </form>
                    </x-slot:footer>
                </x-ui.card>
            @endif
        </div>

        {{-- Access --}}
        @if ($canManage)
            <div x-show="tab === 'access'" x-cloak class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
                <x-ui.card :title="__('Members')" :description="$topic->isPublic() ? __('Anyone can subscribe to this public topic. Members can also publish.') : __('Only these people can subscribe to this private topic.')" icon="users" :padding="false">
                    <ul>
                        <li class="flex items-center gap-3 border-t border-zinc-100 px-5 py-3.5 first:border-0 sm:px-6 dark:border-zinc-800">
                            @if ($topic->owner)<x-ui.avatar :user="$topic->owner" />@else<x-ui.avatar name="?" />@endif
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $topic->owner?->name ?? __('Deleted user') }}</span>
                                <span class="block truncate text-xs text-zinc-500">{{ $topic->owner?->email }}</span>
                            </span>
                            <x-ui.badge color="brand">{{ __('Owner') }}</x-ui.badge>
                        </li>
                        @foreach ($topic->members as $member)
                            <li class="flex flex-wrap items-center gap-3 border-t border-zinc-100 px-5 py-3.5 sm:px-6 dark:border-zinc-800">
                                <x-ui.avatar :user="$member" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $member->name }}</span>
                                    <span class="block truncate text-xs text-zinc-500">{{ $member->email }}</span>
                                </span>
                                <form method="POST" action="{{ route('topics.members.update', [$topic, $member]) }}">
                                    @csrf
                                    @method('PUT')
                                    <select name="can_write" onchange="this.form.submit()" class="form-control w-auto py-1.5 pr-8 text-xs">
                                        <option value="0" @selected(! $member->pivot->can_write)>{{ __('Read only') }}</option>
                                        <option value="1" @selected($member->pivot->can_write)>{{ __('Read & publish') }}</option>
                                    </select>
                                </form>
                                <form method="POST" action="{{ route('topics.members.destroy', [$topic, $member]) }}" data-confirm-title="{{ __('Remove :name from this topic?', ['name' => $member->name]) }}" data-confirm="{{ __('Their devices will stop receiving the notifications of this topic.') }}" data-confirm-action="{{ __('Remove') }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button variant="ghost-danger" size="icon" icon="x" class="size-8"><span class="sr-only">{{ __('Remove') }}</span></x-ui.button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>

                <form method="POST" action="{{ route('topics.members.store', $topic) }}">
                    @csrf
                    <x-ui.card :title="__('Add a member')" icon="user-plus">
                        <div class="space-y-4">
                            <x-ui.input name="email" type="email" :label="__('Email')" list="users-list" required :placeholder="__('user@example.com')" icon="mail" />
                            <datalist id="users-list">
                                @foreach ($users as $candidate)
                                    <option value="{{ $candidate->email }}">{{ $candidate->name }}</option>
                                @endforeach
                            </datalist>
                            <x-ui.toggle name="can_write" :label="__('Can publish')" :description="__('Allow this member to send messages to the topic.')" />
                        </div>
                        <x-slot:footer>
                            <x-ui.button icon="plus">{{ __('Add') }}</x-ui.button>
                        </x-slot:footer>
                    </x-ui.card>
                </form>
            </div>
        @endif
    </div>
</x-layouts.app>

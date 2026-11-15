@php
    $visibility = old('visibility', $topic->visibility?->value ?? 'private');
@endphp
<div x-data="{ name: @js(old('name', $topic->name ?? '')), display: @js(old('display_name', $topic->display_name ?? '')), visibility: @js($visibility) }" class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
    <div class="space-y-6">
        <x-ui.card :title="__('General')" icon="hash">
            <div class="space-y-5">
                @if ($topic->exists)
                    <x-ui.input name="name" :label="__('Identifier')" :value="$topic->name" disabled prefix="#" :hint="__('The identifier cannot be changed: it is what devices subscribe to.')" />
                @else
                    <x-ui.input name="name" :label="__('Identifier')" required autofocus maxlength="64" prefix="#" placeholder="backups" x-model="name"
                        @input="name = $event.target.value.toLowerCase().replace(/[^a-z0-9_-]/g, '-')"
                        :hint="__('Lowercase letters, digits, dashes and underscores. This is what devices subscribe to.')" />
                @endif
                <x-ui.input name="display_name" :label="__('Display name')" :value="$topic->display_name" maxlength="100" :placeholder="__('Server backups')" x-model="display" optional />
                <div x-data="{ preview: @js($topic->iconUrl()), removed: false }" class="space-y-1.5">
                    <span class="block text-sm font-medium text-zinc-900 dark:text-white">{{ __('Logo') }} <span class="text-xs font-normal text-zinc-400">{{ __('Optional') }}</span></span>
                    <div class="flex items-center gap-4">
                        <span class="inline-flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-zinc-100 text-zinc-400 ring-1 ring-zinc-200 dark:bg-white/5 dark:ring-zinc-800">
                            <template x-if="preview && !removed"><img :src="preview" alt="" class="size-full object-cover"></template>
                            <template x-if="!preview || removed"><x-ui.icon name="bell" class="size-6" /></template>
                        </span>
                        <div class="flex flex-wrap items-center gap-2">
                            <label class="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm font-medium text-zinc-700 shadow-xs transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-white/5 dark:text-zinc-200 dark:hover:bg-white/10">
                                <x-ui.icon name="upload" class="size-4" />{{ __('Choose an image') }}
                                <input type="file" name="icon" accept="image/png,image/jpeg,image/webp" class="sr-only"
                                    @change="const f = $event.target.files[0]; if (f) { preview = URL.createObjectURL(f); removed = false }">
                            </label>
                            @if ($topic->icon_path)
                                <label class="inline-flex cursor-pointer items-center gap-2 rounded-xl px-3 py-2 text-sm font-medium text-zinc-500 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10">
                                    <input type="checkbox" name="remove_icon" value="1" x-model="removed" class="accent-red-600">{{ __('Remove') }}
                                </label>
                            @endif
                        </div>
                    </div>
                    <p class="text-xs text-zinc-500">{{ __('Square PNG, JPEG or WebP, 1 MB max. Shown in the apps and in notifications.') }}</p>
                    @error('icon')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <x-ui.textarea name="description" :label="__('Description')" :value="$topic->description" rows="3" maxlength="1000" optional :placeholder="__('What is published to this topic?')" />
            </div>
        </x-ui.card>

        <x-ui.card :title="__('Access')" icon="shield">
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ([
                    'private' => ['icon' => 'lock', 'label' => __('Private'), 'text' => __('Only members you choose can subscribe. The app asks them to sign in.')],
                    'public' => ['icon' => 'globe', 'label' => __('Public'), 'text' => __('Anyone who knows the identifier can subscribe, without an account.')],
                ] as $value => $option)
                    <label class="relative flex cursor-pointer flex-col gap-3 rounded-2xl border border-zinc-200 p-4 transition hover:border-zinc-300 has-checked:border-brand-500 has-checked:bg-brand-50/50 has-checked:ring-4 has-checked:ring-brand-500/10 dark:border-zinc-800 dark:hover:border-zinc-700 dark:has-checked:bg-brand-500/5">
                        <input type="radio" name="visibility" value="{{ $value }}" class="peer sr-only" x-model="visibility" @checked($visibility === $value)>
                        <span class="flex items-center justify-between">
                            <span class="inline-flex size-9 items-center justify-center rounded-xl bg-zinc-100 text-zinc-600 peer-checked:bg-brand-100 dark:bg-white/5 dark:text-zinc-300"><x-ui.icon :name="$option['icon']" class="size-[18px]" /></span>
                            <span class="inline-flex size-5 items-center justify-center rounded-full border-2 border-zinc-300 transition dark:border-zinc-600" :class="visibility === '{{ $value }}' && '!border-brand-600 bg-brand-600'">
                                <span class="size-1.5 rounded-full bg-white" x-show="visibility === '{{ $value }}'"></span>
                            </span>
                        </span>
                        <span>
                            <span class="block text-sm font-semibold text-zinc-900 dark:text-white">{{ $option['label'] }}</span>
                            <span class="mt-1 block text-sm leading-relaxed text-zinc-500 dark:text-zinc-400">{{ $option['text'] }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('visibility')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror

            <div class="mt-6 border-t border-zinc-100 pt-6 dark:border-zinc-800" x-show="visibility === 'public'" x-collapse>
                <x-ui.toggle name="anonymous_publish" :checked="(bool) $topic->anonymous_publish" :label="__('Allow anonymous publishing')"
                    :description="__('Anyone can publish without a token. Leave disabled unless you really need it.')" />
            </div>
        </x-ui.card>

        @php
            $steps = collect(old('escalation', $topic->escalation ?? []))->map(fn ($step) => ['after' => (int) ($step['after'] ?? 5), 'users' => array_map('intval', $step['users'] ?? [])])->values();
        @endphp
        <div x-data="{ ack: @js((string) old('ack_priority', $topic->ack_priority ?? '')), steps: @js($steps), users: @js($escalationUsers->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()) }">
        <x-ui.card :title="__('Alerts')" :description="__('Group repeated alerts and make sure someone takes care of important ones.')" icon="bell">
            <div class="space-y-6">
                <x-ui.select name="group_window" :label="__('Group repeated messages')" :value="$topic->group_window ?? 10"
                    :hint="__('Identical messages, or messages with the same X-Collapse key, are counted on a single message (×12) and do not notify again. The notification is sent again if the priority rises.')"
                    :options="[
                        0 => __('Never'),
                        5 => __('Within 5 minutes'),
                        10 => __('Within 10 minutes'),
                        30 => __('Within 30 minutes'),
                        60 => __('Within 1 hour'),
                        240 => __('Within 4 hours'),
                        1440 => __('Within 24 hours'),
                    ]" />

                <div class="border-t border-zinc-100 pt-6 dark:border-zinc-800">
                    <x-ui.select name="ack_priority" :label="__('Require an acknowledgement')" :value="$topic->ack_priority" x-model="ack"
                        :hint="__('Notifications get an “Acknowledge” button. Until someone presses it, reminders are sent as configured below.')"
                        :options="[
                            '' => __('Never'),
                            5 => __('For urgent messages'),
                            4 => __('For high and urgent messages'),
                            3 => __('For messages of default priority and above'),
                            1 => __('For every message'),
                        ]" />
                </div>

                <div x-show="ack !== ''" x-collapse class="space-y-3">
                    <div>
                        <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ __('Escalation') }}</p>
                        <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Each step notifies every subscriber again and emails the people you choose.') }}</p>
                    </div>
                    <template x-for="(step, index) in steps" :key="index">
                        <div class="rounded-2xl border border-zinc-200 p-4 dark:border-zinc-800">
                            <div class="flex flex-wrap items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                                <span class="inline-flex size-6 items-center justify-center rounded-full bg-zinc-100 text-xs font-semibold text-zinc-700 dark:bg-white/10 dark:text-zinc-200" x-text="index + 1"></span>
                                <span>{{ __('After') }}</span>
                                <input type="number" min="1" max="1440" required class="form-control w-24 py-1.5" :name="`escalation[${index}][after]`" x-model.number="step.after">
                                <span>{{ __('minutes without acknowledgement') }}</span>
                                <button type="button" class="ml-auto inline-flex size-8 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10" @click="steps.splice(index, 1)">
                                    <x-ui.icon name="trash" class="size-4" /><span class="sr-only">{{ __('Remove') }}</span>
                                </button>
                            </div>
                            <p class="mt-3 text-xs font-medium text-zinc-500">{{ __('Also email') }}</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <template x-for="user in users" :key="user.id">
                                    <label class="cursor-pointer">
                                        <input type="checkbox" class="peer sr-only" :name="`escalation[${index}][users][]`" :value="user.id" x-model.number="step.users">
                                        <span class="inline-flex items-center gap-1.5 rounded-full border border-zinc-200 px-3 py-1 text-xs font-medium text-zinc-600 transition peer-checked:border-brand-500 peer-checked:bg-brand-50 peer-checked:text-brand-700 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/40 dark:border-zinc-700 dark:text-zinc-300 dark:peer-checked:bg-brand-500/10 dark:peer-checked:text-brand-300" x-text="user.name"></span>
                                    </label>
                                </template>
                                <span x-show="users.length === 0" class="text-xs text-zinc-400">{{ __('Add members to this topic to email them.') }}</span>
                            </div>
                        </div>
                    </template>
                    <p x-show="steps.length === 0" class="rounded-2xl border border-dashed border-zinc-200 px-4 py-3 text-sm text-zinc-500 dark:border-zinc-800">{{ __('No reminder: the message only waits for an acknowledgement.') }}</p>
                    <x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-show="steps.length < {{ \App\Http\Controllers\TopicController::MAX_ESCALATION_STEPS }}"
                        @click="steps.push({ after: steps.length ? steps[steps.length - 1].after * 2 : 5, users: [] })">{{ __('Add a reminder') }}</x-ui.button>
                    @foreach ($errors->get('escalation.*') as $messages)
                        <p class="text-sm text-red-600">{{ $messages[0] }}</p>
                    @endforeach
                </div>
            </div>
        </x-ui.card>
        </div>
    </div>

    <aside class="space-y-4 lg:sticky lg:top-24 lg:self-start">
        <p class="eyebrow">{{ __('Preview') }}</p>
        <div class="relative overflow-hidden rounded-3xl bg-linear-to-br from-sky-400 via-indigo-500 to-fuchsia-500 p-5 pt-10 shadow-(--shadow-lift)">
            <p class="text-center text-5xl font-light tracking-tight text-white/90 tabular-nums">{{ now()->format('H:i') }}</p>
            <p class="mb-8 text-center text-sm font-medium text-white/80">{{ ucfirst(now()->isoFormat('dddd D MMMM')) }}</p>
            <div class="rounded-[22px] border border-white/40 bg-white/75 p-3 shadow-xl shadow-black/10 backdrop-blur-xl">
                <div class="flex gap-3">
                    @if ($topic->iconUrl())
                        <img src="{{ $topic->iconUrl() }}" alt="" class="size-9 rounded-[10px] object-cover">
                    @else
                        <x-ui.logo class="size-9 rounded-[10px]" />
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline justify-between gap-2">
                            <p class="truncate text-[13px] font-semibold text-zinc-900" x-text="display || name || @js(__('My topic'))"></p>
                            <span class="shrink-0 text-[11px] text-zinc-500">{{ __('now') }}</span>
                        </div>
                        <p class="text-[13px] leading-snug text-zinc-700">{{ __('Nightly backup finished in 42 seconds.') }}</p>
                    </div>
                </div>
            </div>
        </div>
        <p class="text-xs leading-relaxed text-zinc-500">{{ __('This is how notifications of this topic look on an iPhone when a message has no title.') }}</p>
    </aside>
</div>

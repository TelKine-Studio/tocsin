<x-layouts.app :title="__('Edit :topic', ['topic' => $topic->name])">
    <x-ui.page-header :title="__('Topic settings')" :description="$topic->label()">
        <x-slot:breadcrumb>
            <a href="{{ route('topics.index') }}" class="hover:text-zinc-900 dark:hover:text-white">{{ __('Topics') }}</a>
            <x-ui.icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('topics.show', $topic) }}" class="hover:text-zinc-900 dark:hover:text-white">{{ $topic->label() }}</a>
        </x-slot:breadcrumb>
    </x-ui.page-header>

    <form method="POST" action="{{ route('topics.update', $topic) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')
        @include('topics.partials.form')
        <div class="mt-6 flex justify-end gap-3 lg:pr-[364px]">
            <x-ui.button variant="ghost" :href="route('topics.show', $topic)">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button icon="check">{{ __('Save changes') }}</x-ui.button>
        </div>
    </form>

    <div class="mt-10 lg:pr-[364px]">
        <section class="rounded-2xl border border-red-200 bg-red-50/40 p-5 sm:p-6 dark:border-red-500/20 dark:bg-red-500/5">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="font-semibold text-red-900 dark:text-red-200">{{ __('Delete this topic') }}</h2>
                    <p class="mt-1 text-sm text-red-800/80 dark:text-red-200/70">{{ __('All its messages are deleted and subscribed devices stop receiving notifications. This cannot be undone.') }}</p>
                </div>
                <form method="POST" action="{{ route('topics.destroy', $topic) }}" data-confirm="{{ __('All its messages are deleted and subscribed devices stop receiving notifications. This cannot be undone.') }}" data-confirm-title="{{ __('Delete the topic :topic?', ['topic' => $topic->name]) }}" data-confirm-action="{{ __('Delete topic') }}">
                    @csrf
                    @method('DELETE')
                    <x-ui.button variant="danger" icon="trash">{{ __('Delete topic') }}</x-ui.button>
                </form>
            </div>
        </section>
    </div>
</x-layouts.app>

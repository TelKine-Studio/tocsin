<x-layouts.app :title="__('New topic')">
    <x-ui.page-header :title="__('New topic')" :description="__('Create a channel your scripts and services will publish to.')">
        <x-slot:breadcrumb><a href="{{ route('topics.index') }}" class="hover:text-zinc-900 dark:hover:text-white">{{ __('Topics') }}</a><x-ui.icon name="chevron-right" class="size-3.5" /><span>{{ __('New') }}</span></x-slot:breadcrumb>
    </x-ui.page-header>

    <form method="POST" action="{{ route('topics.store') }}" enctype="multipart/form-data">
        @csrf
        @include('topics.partials.form')
        <div class="mt-6 flex justify-end gap-3 lg:pr-[364px]">
            <x-ui.button variant="ghost" :href="route('topics.index')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button icon="check">{{ __('Create topic') }}</x-ui.button>
        </div>
    </form>
</x-layouts.app>

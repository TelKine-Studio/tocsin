<x-layouts.guest :title="__('Invitation')">
    <x-ui.empty icon="mail" :title="__('This invitation is no longer valid')" :description="__('The link may have expired, been revoked or already used. Ask an administrator for a new invitation.')" class="px-0">
        <x-ui.button :href="route('login')" variant="secondary">{{ __('Back to login') }}</x-ui.button>
    </x-ui.empty>
</x-layouts.guest>

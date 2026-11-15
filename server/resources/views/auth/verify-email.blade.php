<x-layouts.guest :title="__('Confirm your email')" :heading="__('Confirm your email address')" :subheading="__('We sent a confirmation link to :email. Open it to start publishing.', ['email' => auth()->user()->email])">
    @if (session('status'))
        <div class="mb-6 flex gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200">
            <x-ui.icon name="check-circle" class="mt-0.5 size-4 shrink-0" />{{ session('status') }}
        </div>
    @endif
    <div class="space-y-4">
        <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('You can already create your topics and subscribe your phones. Messages are accepted once your address is confirmed.') }}</p>
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-ui.button variant="secondary" class="w-full" size="lg" icon="send">{{ __('Send the link again') }}</x-ui.button>
        </form>
        <x-ui.button class="w-full" size="lg" :href="route('dashboard')">{{ __('Continue to Tocsin') }}</x-ui.button>
    </div>
</x-layouts.guest>

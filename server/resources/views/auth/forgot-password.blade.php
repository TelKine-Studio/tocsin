<x-layouts.guest :title="__('Forgot password')" :heading="__('Forgot your password?')" :subheading="__('Enter your email address and we will send you a link to choose a new one.')">
    @if (session('status'))
        <div class="mb-6 flex gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200">
            <x-ui.icon name="check-circle" class="mt-0.5 size-4 shrink-0" />{{ session('status') }}
        </div>
    @endif
    <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
        @csrf
        <x-ui.input name="email" type="email" :label="__('Email')" required autofocus autocomplete="email" icon="mail" />
        <x-ui.button class="w-full" size="lg">{{ __('Send the reset link') }}</x-ui.button>
    </form>

    <x-slot:below>
        <a href="{{ route('login') }}" class="inline-flex items-center gap-1.5 font-semibold text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">← {{ __('Back to login') }}</a>
    </x-slot:below>
</x-layouts.guest>

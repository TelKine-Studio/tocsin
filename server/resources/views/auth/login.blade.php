<x-layouts.guest :title="__('Log in')" :heading="__('Welcome back')" :subheading="__('Sign in to manage your topics and notifications.')">
    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf
        <x-ui.input name="email" type="email" :label="__('Email')" required autofocus autocomplete="username" icon="mail" placeholder="you@example.com" />
        <div class="space-y-2">
            <x-ui.input name="password" type="password" :label="__('Password')" required autocomplete="current-password" icon="lock" />
            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                    <input type="checkbox" name="remember" value="1" class="size-4 rounded border-zinc-300 text-brand-600 focus:ring-brand-500 dark:border-zinc-700 dark:bg-zinc-900">
                    {{ __('Remember me') }}
                </label>
                <a href="{{ route('password.request') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">{{ __('Forgot password?') }}</a>
            </div>
        </div>
        <x-ui.button class="w-full" size="lg">{{ __('Log in') }}</x-ui.button>
    </form>

    @if ($sso)
        <div class="my-6 flex items-center gap-3 text-xs text-zinc-400"><span class="h-px flex-1 bg-zinc-200 dark:bg-zinc-800"></span>{{ __('or') }}<span class="h-px flex-1 bg-zinc-200 dark:bg-zinc-800"></span></div>
        <x-ui.button variant="secondary" :href="route('sso.redirect')" class="w-full" size="lg" icon="key">{{ __('Continue with :provider', ['provider' => $sso]) }}</x-ui.button>
    @endif

    @if ($registrationEnabled)
        <x-slot:below>
            {{ __('No account yet?') }}
            <a href="{{ route('register') }}" class="font-semibold text-brand-600 hover:text-brand-700 dark:text-brand-400">{{ __('Sign up') }}</a>
        </x-slot:below>
    @endif
</x-layouts.guest>

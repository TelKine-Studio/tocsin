<x-layouts.guest :title="__('Sign up')" :heading="__('Create your account')" :subheading="__('Subscribe to topics and receive notifications on your iPhone.')">
    <form method="POST" action="{{ route('register') }}" class="space-y-5">
        @csrf
        <x-ui.input name="name" :label="__('Full name')" required autofocus autocomplete="name" icon="user" />
        <x-ui.input name="email" type="email" :label="__('Email')" required autocomplete="email" icon="mail" />
        <x-ui.input name="password" type="password" :label="__('Password')" required autocomplete="new-password" :hint="__('At least 8 characters, with letters and numbers.')" />
        <x-ui.input name="password_confirmation" type="password" :label="__('Confirm password')" required autocomplete="new-password" />
        <x-ui.button class="w-full" size="lg">{{ __('Create my account') }}</x-ui.button>
    </form>

    <x-slot:below>
        {{ __('Already have an account?') }}
        <a href="{{ route('login') }}" class="font-semibold text-brand-600 hover:text-brand-700 dark:text-brand-400">{{ __('Log in') }}</a>
    </x-slot:below>
</x-layouts.guest>

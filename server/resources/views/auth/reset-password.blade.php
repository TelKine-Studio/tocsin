<x-layouts.guest :title="__('Reset password')" :heading="__('Choose a new password')">
    <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-ui.input name="email" type="email" :label="__('Email')" :value="$email" required autocomplete="username" icon="mail" />
        <x-ui.input name="password" type="password" :label="__('New password')" required autofocus autocomplete="new-password" />
        <x-ui.input name="password_confirmation" type="password" :label="__('Confirm password')" required autocomplete="new-password" />
        <x-ui.button class="w-full" size="lg">{{ __('Reset my password') }}</x-ui.button>
    </form>
</x-layouts.guest>

<x-layouts.guest :title="__('Two-factor authentication')" :heading="__('Verification code')" :subheading="__('Open your authenticator app and enter the 6-digit code for :app.', ['app' => config('app.name')])">
    <div x-data="{ recovery: {{ $errors->has('recovery_code') ? 'true' : 'false' }} }">
        <form method="POST" action="{{ route('two-factor.challenge') }}" class="space-y-5">
            @csrf
            <template x-if="!recovery">
                <x-ui.input name="code" :label="__('Code')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus placeholder="123456" icon="shield" />
            </template>
            <template x-if="recovery">
                <x-ui.input name="recovery_code" :label="__('Recovery code')" required autofocus placeholder="abcde-fghij" icon="key" />
            </template>
            <x-ui.button class="w-full" size="lg">{{ __('Verify') }}</x-ui.button>
        </form>
        <button type="button" @click="recovery = !recovery" class="mt-6 text-sm font-medium text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200"
            x-text="recovery ? @js(__('Use a code from the app')) : @js(__('Use a recovery code'))"></button>
    </div>
</x-layouts.guest>

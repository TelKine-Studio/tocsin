<x-layouts.guest :title="__('Invitation')" :heading="$invitation->name ? __('Welcome, :name', ['name' => \Illuminate\Support\Str::before($invitation->name, ' ')]) : __('Join :app', ['app' => config('app.name')])"
    :subheading="__('An account was created for you on :app as :role. Choose your password to activate it.', ['app' => config('app.name'), 'role' => mb_strtolower($invitation->role->label())])">
    @if ($existingAccount)
        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400">{{ __('An account already exists for this address.') }}</div>
        <x-ui.button :href="route('login')" class="mt-6 w-full" size="lg">{{ __('Log in') }}</x-ui.button>
    @else
        <form method="POST" action="{{ route('invitations.accept', $token) }}" class="space-y-5">
            @csrf
            <x-ui.input name="email" type="email" :label="__('Email')" :value="$invitation->email" disabled icon="mail" />
            <x-ui.input name="name" :label="__('Full name')" :value="$invitation->name" required autocomplete="name" icon="user" />
            <x-ui.input name="password" type="password" :label="__('Choose your password')" required autofocus autocomplete="new-password" :hint="__('At least 8 characters, with letters and numbers.')" />
            <x-ui.input name="password_confirmation" type="password" :label="__('Confirm password')" required autocomplete="new-password" />
            <x-ui.button class="w-full" size="lg">{{ __('Create my account') }}</x-ui.button>
        </form>
    @endif
</x-layouts.guest>

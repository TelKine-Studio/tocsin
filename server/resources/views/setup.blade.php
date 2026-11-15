@php
    $adminErrors = $errors->hasAny(['name', 'email', 'password']);
@endphp
<x-layouts.guest :title="__('Setup')" :heading="__('Welcome to Tocsin')" :subheading="__('Two quick steps and your notification server is ready. You will be its first administrator.')">
    <form method="POST" action="{{ route('setup.store') }}" x-data="{ step: {{ $adminErrors ? 2 : 1 }} }" class="space-y-8">
        @csrf

        <ol class="grid grid-cols-2 gap-3">
            @foreach ([1 => __('Instance'), 2 => __('Administrator')] as $number => $label)
                <li>
                    <button type="button" @click="step = {{ $number }}" class="w-full text-left">
                        <span class="block h-1 rounded-full transition-colors" :class="step >= {{ $number }} ? 'bg-brand-600' : 'bg-zinc-200 dark:bg-zinc-800'"></span>
                        <span class="mt-2 block text-xs font-medium transition-colors" :class="step >= {{ $number }} ? 'text-zinc-900 dark:text-white' : 'text-zinc-400'">{{ __('Step :n', ['n' => $number]) }} · {{ $label }}</span>
                    </button>
                </li>
            @endforeach
        </ol>

        <div x-show="step === 1" class="space-y-5">
            <x-ui.input name="site_name" :label="__('Instance name')" value="Tocsin" required maxlength="60" icon="sparkles" />
            <x-ui.input name="app_url" type="url" :label="__('Public URL')" :value="$suggestedUrl" required icon="globe"
                :hint="__('The address of this server, e.g. https://notify.example.com. The mobile app uses it to connect.')" />
            <x-ui.select name="locale" :label="__('Language')" :options="$locales" :value="app()->getLocale()" />
            <x-ui.button type="button" @click="step = 2" class="w-full" size="lg" iconRight="arrow-right">{{ __('Continue') }}</x-ui.button>
        </div>

        <div x-show="step === 2" x-cloak class="space-y-5">
            <x-ui.input name="name" :label="__('Full name')" required autocomplete="name" icon="user" />
            <x-ui.input name="email" type="email" :label="__('Email')" required autocomplete="email" icon="mail" />
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input name="password" type="password" :label="__('Password')" required autocomplete="new-password" />
                <x-ui.input name="password_confirmation" type="password" :label="__('Confirmation')" required autocomplete="new-password" />
            </div>
            <p class="-mt-2 text-xs text-zinc-500">{{ __('At least 8 characters, with letters and numbers.') }}</p>
            <div class="flex gap-3">
                <x-ui.button type="button" variant="secondary" @click="step = 1" size="lg">{{ __('Back') }}</x-ui.button>
                <x-ui.button class="flex-1" size="lg" icon="check">{{ __('Create my instance') }}</x-ui.button>
            </div>
        </div>
    </form>
</x-layouts.guest>

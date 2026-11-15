<x-layouts.app :title="__('Settings')">
    @include('admin.settings.partials.nav')
    <x-settings.layout :section="$section">
        <form method="POST" action="{{ route('admin.settings.mail') }}" x-data="{ mailer: @js(old('mailer', $settings->string('mail.mailer'))) }">
            @csrf
            @method('PUT')
            <x-ui.card :title="__('Outgoing email')" :description="__('Used for invitations and password resets.')" icon="mail">
                <div class="space-y-5">
                    <div class="grid gap-3 sm:grid-cols-3">
                        @foreach ([
                            'log' => [__('Disabled'), __('Emails are written to the log. Share invitation links manually.')],
                            'resend' => ['Resend', __('Send emails through the Resend API with a single key.')],
                            'smtp' => [__('SMTP server'), __('Send emails through your mail provider.')],
                        ] as $value => [$label, $text])
                            <label class="flex cursor-pointer gap-3 rounded-2xl border border-zinc-200 p-4 transition has-checked:border-brand-500 has-checked:bg-brand-50/50 has-checked:ring-4 has-checked:ring-brand-500/10 dark:border-zinc-800 dark:has-checked:bg-brand-500/5">
                                <input type="radio" name="mailer" value="{{ $value }}" x-model="mailer" class="mt-1 accent-brand-600">
                                <span><span class="block text-sm font-semibold text-zinc-900 dark:text-white">{{ $label }}</span><span class="mt-0.5 block text-sm text-zinc-500 dark:text-zinc-400">{{ $text }}</span></span>
                            </label>
                        @endforeach
                    </div>

                    <div x-show="mailer === 'resend'" x-collapse class="space-y-5">
                        <x-ui.input name="resend_key" type="password" :label="__('Resend API key')" autocomplete="new-password"
                            :placeholder="$settings->get('mail.resend_key') ? '••••••••' : 're_…'"
                            :hint="$settings->get('mail.resend_key') ? __('Leave empty to keep the current key.') : __('Create a key with “Sending access” at resend.com/api-keys. Stored encrypted.')" />
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('The sender address must belong to a domain verified in Resend.') }}</p>
                    </div>

                    <div x-show="mailer === 'smtp'" x-collapse class="space-y-5">
                        <div class="grid gap-5 sm:grid-cols-3">
                            <x-ui.input name="host" :label="__('Host')" :value="$settings->get('mail.host')" placeholder="smtp.example.com" class="sm:col-span-2" />
                            <x-ui.input name="port" type="number" :label="__('Port')" :value="$settings->get('mail.port')" placeholder="587" />
                        </div>
                        <x-ui.select name="scheme" :label="__('Encryption')" :value="$settings->get('mail.scheme')" :options="[
                            '' => __('Automatic (STARTTLS when available)'),
                            'smtps' => __('SSL/TLS (usually port 465)'),
                            'smtp' => __('None / STARTTLS'),
                        ]" />
                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-ui.input name="username" :label="__('Username')" :value="$settings->get('mail.username')" autocomplete="off" />
                            <x-ui.input name="password" type="password" :label="__('Password')" autocomplete="new-password"
                                :placeholder="$settings->get('mail.password') ? '••••••••' : ''"
                                :hint="$settings->get('mail.password') ? __('Leave empty to keep the current password.') : __('Stored encrypted.')" />
                        </div>
                    </div>

                    <div x-show="mailer !== 'log'" x-collapse>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-ui.input name="from_address" type="email" :label="__('Sender address')" :value="$settings->get('mail.from_address')" placeholder="tocsin@example.com" />
                            <x-ui.input name="from_name" :label="__('Sender name')" :value="$settings->get('mail.from_name')" :placeholder="$settings->string('app.name')" />
                        </div>
                    </div>
                </div>
                <x-slot:footer><x-ui.button>{{ __('Save') }}</x-ui.button></x-slot:footer>
            </x-ui.card>
        </form>

        <x-ui.card :title="__('Test')" icon="send">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Send a test email to :email with the saved settings.', ['email' => auth()->user()->email]) }}</p>
                <form method="POST" action="{{ route('admin.settings.mail.test') }}">
                    @csrf
                    <x-ui.button variant="secondary" icon="send">{{ __('Send a test email') }}</x-ui.button>
                </form>
            </div>
        </x-ui.card>
    </x-settings.layout>
</x-layouts.app>

<x-layouts.app :title="__('Settings')">
    @include('admin.settings.partials.nav')
    <x-settings.layout :section="$section">
        <form method="POST" action="{{ route('admin.settings.general') }}" class="space-y-6">
            @csrf
            @method('PUT')

            <x-ui.card :title="__('Instance')" :description="__('How your server presents itself.')" icon="sparkles">
                <div class="space-y-5">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-ui.input name="app_name" :label="__('Name')" :value="$settings->string('app.name')" required maxlength="60" />
                        <x-ui.select name="app_locale" :label="__('Default language')" :options="$locales" :value="$settings->string('app.locale')" />
                    </div>
                    <x-ui.input name="app_url" type="url" :label="__('Public URL')" :value="$settings->get('app.url') ?: url('/')" required icon="globe"
                        :hint="__('Used in emails, invitation links and by the iPhone app to reach this server.')" />
                </div>
            </x-ui.card>

            <x-ui.card :title="__('Access')" :description="__('Who can join and create topics.')" icon="shield">
                <div class="space-y-6">
                    <x-ui.toggle name="registration_enabled" :checked="$settings->boolean('registration.enabled')" :label="__('Open registration')"
                        :description="__('Anyone can create an account from the login page. When disabled, users join by invitation only.')" />
                    <div class="border-t border-zinc-100 dark:border-zinc-800"></div>
                    <x-ui.toggle name="users_can_create_topics" :checked="$settings->boolean('topics.users_can_create')" :label="__('Users can create topics')"
                        :description="__('When disabled, only administrators can create topics.')" />
                </div>
            </x-ui.card>

            <x-ui.card :title="__('Messages')" :description="__('History kept on the server.')" icon="inbox">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input name="retention_days" type="number" min="0" max="3650" :label="__('Retention (days)')" :value="$settings->integer('messages.retention_days')"
                        :hint="__('Older messages are deleted every night. 0 keeps them forever.')" />
                    <x-ui.input name="max_length" type="number" min="64" max="65535" :label="__('Maximum message length')" :value="$settings->integer('messages.max_length')"
                        :hint="__('In characters.')" />
                </div>
                <x-slot:footer><x-ui.button>{{ __('Save settings') }}</x-ui.button></x-slot:footer>
            </x-ui.card>
        </form>
    </x-settings.layout>
</x-layouts.app>

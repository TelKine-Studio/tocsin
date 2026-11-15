<x-layouts.app :title="__('Settings')">
    @include('admin.settings.partials.nav')
    <x-settings.layout :section="$section">
        <form method="POST" action="{{ route('admin.settings.security') }}" class="space-y-6" x-data="{ sso: {{ old('sso_enabled', $settings->boolean('sso.enabled')) ? 'true' : 'false' }} }">
            @csrf
            @method('PUT')
            <x-ui.card :title="__('Two-factor authentication')" icon="shield">
                <x-ui.toggle name="require_2fa_admins" :checked="$settings->boolean('security.require_2fa_admins')" :label="__('Required for administrators')"
                    :description="__('Administrators without two-factor authentication cannot open the administration until they enable it.')" />
                @error('require_2fa_admins')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
            </x-ui.card>

            <x-ui.card :title="__('Single sign-on (OpenID Connect)')" :description="__('Let people log in with Google Workspace, Microsoft Entra ID, Keycloak, Authentik, Okta…')" icon="key">
                <div class="space-y-5">
                    <x-ui.toggle name="sso_enabled" :checked="$settings->boolean('sso.enabled')" :label="__('Enable single sign-on')" x-model="sso" />
                    <div x-show="sso" x-collapse class="space-y-5">
                        <x-ui.input name="sso_label" :label="__('Button label')" :value="$settings->get('sso.label')" :placeholder="__('e.g. Google Workspace')" />
                        <x-ui.input name="sso_issuer" type="url" :label="__('Issuer URL')" :value="$settings->get('sso.issuer')" placeholder="https://accounts.google.com"
                            :hint="__('The address that serves /.well-known/openid-configuration.')" />
                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-ui.input name="sso_client_id" :label="__('Client ID')" :value="$settings->get('sso.client_id')" autocomplete="off" />
                            <x-ui.input name="sso_client_secret" type="password" :label="__('Client secret')" autocomplete="new-password"
                                :placeholder="$settings->get('sso.client_secret') ? '••••••••' : ''" :hint="$settings->get('sso.client_secret') ? __('Leave empty to keep the current secret.') : __('Stored encrypted.')" />
                        </div>
                        <div x-data="copy(@js(route('sso.callback')))" class="rounded-xl bg-zinc-50 p-3 text-xs text-zinc-600 dark:bg-white/[.03] dark:text-zinc-400">
                            {{ __('Redirect URI to declare at the provider:') }}
                            <span class="mt-1 flex items-center gap-2"><code class="font-mono text-zinc-800 dark:text-zinc-200">{{ route('sso.callback') }}</code><button type="button" @click="copy()"><x-ui.icon name="copy" class="size-3.5" /></button></span>
                        </div>
                        <div class="border-t border-zinc-100 pt-5 dark:border-zinc-800">
                            <x-ui.toggle name="sso_auto_create" :checked="$settings->boolean('sso.auto_create')" :label="__('Create accounts automatically')"
                                :description="__('People without an account get one (role User) at their first sign-in. Otherwise, add them first in Users.')" />
                        </div>
                        <x-ui.input name="sso_allowed_domains" :label="__('Allowed email domains')" :value="$settings->get('sso.allowed_domains')" placeholder="telkine.net, example.com"
                            :hint="__('Comma separated. Empty: any domain accepted by the provider.')" />
                    </div>
                </div>
                <x-slot:footer><x-ui.button>{{ __('Save') }}</x-ui.button></x-slot:footer>
            </x-ui.card>
        </form>
    </x-settings.layout>
</x-layouts.app>

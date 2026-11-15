<x-layouts.guest :title="__('Add to Tocsin')" :heading="__('Add #:topic to Tocsin', ['topic' => $topic])" :subheading="__('Open this link in the Tocsin app on your phone to subscribe to the topic.')">
    <div x-data x-init="setTimeout(() => window.location.href = @js($appLink), 300)" class="space-y-4">
        <x-ui.button :href="$appLink" icon="smartphone" class="w-full justify-center">{{ __('Open in Tocsin') }}</x-ui.button>

        @if ($signsIn)
            <p class="flex gap-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                <x-ui.icon name="lock" class="mt-0.5 size-4 shrink-0" />
                <span>{{ __('This link also signs the app in to the account that created it. It works once and expires after :minutes minutes.', ['minutes' => \App\Support\Pairing::TTL_MINUTES]) }}</span>
            </p>
        @endif

        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            {{ __('No app yet? Install Tocsin from the App Store or Google Play, then scan the QR code again from the app (Subscribe → Scan).') }}
        </p>
    </div>
</x-layouts.guest>

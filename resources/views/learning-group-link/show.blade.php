{{-- Web-Ziel des Beitrittslinks einer Lerngruppe (LearningGroupLinkController). --}}
<x-guest-layout>
    @push('head')
        <meta name="robots" content="noindex, nofollow">
        <meta name="referrer" content="no-referrer">
    @endpush

    @php($hasStore = config('mobile_api.store_url.ios') || config('mobile_api.store_url.android'))
    <div class="space-y-4 text-sm text-slate-700 dark:text-slate-300">
        <div class="text-center">
            <x-icon name="users" class="w-12 h-12 mx-auto mb-3 text-sky-700 dark:text-sky-400" aria-hidden="true" />
            <h2 class="text-xl font-bold text-slate-800 dark:text-white mb-2">Einladung in eine Lerngruppe</h2>
        </div>

        @if ($groupName)
            <p class="rounded-lg bg-slate-100 dark:bg-slate-800 px-4 py-3 text-center">
                Lerngruppe <strong class="text-slate-900 dark:text-white">„{{ $groupName }}“</strong> bei <strong class="text-slate-900 dark:text-white">{{ $schoolName }}</strong>
            </p>
        @endif

        <p>
            Du wurdest in eine Lerngruppe eingeladen. Öffne diesen Link auf deinem Smartphone mit der Lern-App,
            um der Gruppe beizutreten. Der Beitritt ist nur in der App möglich – dort meldest du dich bei deiner Bootsschule an.
        </p>

        @if ($hasStore)
            <div>
                <p class="mb-2">Du hast die App noch nicht? Hier kannst du sie herunterladen:</p>
                @include('partials.app-store-buttons')
            </div>
        @endif

        <div>
            <p>Falls sich die App mit dem Link nicht öffnet, gib in der App beim Einlösen eines Codes diesen Beitrittscode ein:</p>
            <p class="mt-2 font-mono text-xs break-all rounded-lg bg-slate-100 dark:bg-slate-800 px-3 py-2 select-all">{{ $code }}</p>
        </div>

        <p class="text-xs text-slate-600 dark:text-slate-400">
            Ist der Link abgelaufen oder funktioniert er nicht, frag bitte bei der Person nach, die dich eingeladen hat.
        </p>
    </div>
</x-guest-layout>

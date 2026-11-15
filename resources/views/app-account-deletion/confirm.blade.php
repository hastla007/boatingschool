{{-- Web-Bestätigung der Kontolöschung aus der App (AppAccountDeletionController::show). --}}
<x-guest-layout>
    @push('head')
        <meta name="robots" content="noindex, nofollow">
        <meta name="referrer" content="no-referrer">
    @endpush

    <h2 class="text-xl font-bold text-slate-800 dark:text-white mb-1">Konto löschen</h2>
    <p class="text-sm text-slate-600 dark:text-slate-400 mb-6">
        Du hast in der App die Löschung deines Kontos bei <strong class="font-semibold text-slate-800 dark:text-white">{{ $school }}</strong> beantragt.
    </p>

    <h3 class="text-sm font-semibold text-slate-800 dark:text-white mb-2">Was gelöscht wird</h3>
    <ul class="list-disc pl-5 space-y-1 text-sm text-slate-700 dark:text-slate-300 mb-4">
        <li>deine Mitgliedschaft bei {{ $school }}</li>
        <li>alle deine Lerndaten dort, z.&nbsp;B. Lernfortschritt, Prüfungen, Favoriten und Kurszugänge</li>
        @if ($deletesWholeAccount)
            <li>dein Konto selbst, weil du bei keiner anderen Bootsschule angemeldet bist &ndash; du wirst überall abgemeldet</li>
        @endif
    </ul>
    @unless ($deletesWholeAccount)
        <p class="text-sm text-slate-700 dark:text-slate-300 mb-4">
            Dein Konto bleibt bestehen, weil du noch bei einer anderen Bootsschule angemeldet bist. Dort ändert sich nichts.
        </p>
    @endunless

    <p class="text-sm text-slate-700 dark:text-slate-300 mb-4">
        Die Löschung erfolgt sofort und lässt sich nicht rückgängig machen. Dieser Link ist bis {{ $expiresAt }} Uhr gültig.
    </p>

    @if ($otherPersonSignedIn)
        <div role="note" class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-100">
            Du bist in diesem Browser mit einem anderen Konto angemeldet. Gelöscht wird nicht dieses Konto, sondern das Konto, für das die Löschung beantragt wurde.
        </div>
    @endif

    <form method="POST" action="{{ route('app-account-deletion.confirm') }}">
        @csrf
        <input type="hidden" name="request_id" value="{{ $requestId }}">
        <input type="hidden" name="confirm_key" value="{{ $confirmKey }}">

        <button type="submit" class="w-full px-4 py-2.5 rounded-lg font-semibold text-white bg-rose-700 hover:bg-rose-800 focus:outline-none focus:ring-2 focus:ring-rose-600 focus:ring-offset-2 dark:focus:ring-offset-slate-800 transition">
            Endgültig löschen
        </button>
    </form>

    <p class="mt-4 text-sm text-slate-600 dark:text-slate-400">
        Du möchtest dein Konto behalten? Dann schließe diese Seite einfach, es wird nichts gelöscht.
    </p>
</x-guest-layout>

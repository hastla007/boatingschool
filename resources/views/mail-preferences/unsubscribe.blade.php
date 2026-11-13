<x-guest-layout>
    {{-- Abmeldung aus einer Mail ohne Anmeldung (MailPreferenceController). --}}
    <h2 class="text-xl font-bold text-slate-800 dark:text-white mb-2">E-Mails abbestellen</h2>

    @if ($done)
        <div role="status" class="rounded-lg border border-emerald-200 bg-emerald-50 dark:bg-emerald-900/30 dark:border-emerald-800 px-4 py-3 text-sm text-emerald-900 dark:text-emerald-100">
            Du bist abgemeldet und bekommst keine E-Mails mehr zu „{{ $label }}“.
        </div>
    @elseif ($alreadyOff)
        <p class="text-sm text-slate-600 dark:text-slate-300">Du bist für „{{ $label }}“ bereits abgemeldet.</p>
    @else
        <p class="text-sm text-slate-600 dark:text-slate-300 mb-4">Möchtest du keine E-Mails mehr zu „{{ $label }}“ erhalten?</p>
        <form method="POST" action="{{ $action }}">
            @csrf
            <button type="submit" class="w-full px-4 py-2.5 rounded-lg font-semibold text-white transition hover:opacity-90" style="background-color: var(--brand-primary, #005FD7)">
                Abmelden
            </button>
        </form>
    @endif

    <p class="text-xs text-slate-500 dark:text-slate-400 mt-4">
        Wichtige Mails zu deinem Konto (z. B. Passwort, Anmeldung, Einladungen) bekommst du weiterhin.
        Alle E-Mail-Benachrichtigungen kannst du nach der Anmeldung in deinem Profil ein- und ausschalten.
    </p>
</x-guest-layout>

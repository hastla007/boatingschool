<x-guest-layout>
    <h2 class="text-xl font-bold text-slate-800 dark:text-white mb-2">E-Mail-Adresse bestätigen</h2>
    <p class="text-sm text-slate-400 mb-4">
        Danke für deine Registrierung! Bitte bestätige deine E-Mail-Adresse über den Link, den wir dir gesendet haben.
    </p>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 font-medium text-sm text-emerald-600 dark:text-emerald-400">
            Ein neuer Bestätigungslink wurde an deine E-Mail-Adresse gesendet.
        </div>
    @endif

    <div class="flex items-center justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="px-4 py-2 rounded-lg font-semibold text-sm text-white transition hover:opacity-90" style="background-color: var(--brand-primary, #005FD7)">
                Bestätigungslink erneut senden
            </button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
                Abmelden
            </button>
        </form>
    </div>
</x-guest-layout>

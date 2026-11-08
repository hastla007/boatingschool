<x-guest-layout>
    <h2 class="text-xl font-bold text-slate-800 dark:text-white mb-1">Anmelden</h2>
    <p class="text-sm text-slate-600 dark:text-slate-400 mb-6">{{ session('first_login') ? 'Willkommen! Melde dich mit deinem neuen Passwort an.' : 'Schön, dass du wieder da bist.' }}</p>

    <x-auth-session-status class="mb-4" :status="session('status')" />
    @if (str_contains((string) session('url.intended'), 'verify-email/'))
        {{-- Review-Runde 12: vom Bestätigungslink aus der Mail hierher geleitet. --}}
        <p class="mb-4 rounded-lg bg-sky-50 border border-sky-200 text-sky-900 dark:bg-sky-900/30 dark:border-sky-800 dark:text-sky-100 px-3 py-2 text-sm">Melde dich an, um deine E-Mail-Adresse zu bestätigen.</p>
    @endif

    @if (isset($currentTenant) && in_array($currentTenant->status, ['suspended', 'closed'], true))
        {{-- Gesperrte/geschlossene Schule: Anmelden nur noch für Datenauskunft und Kontolöschung. --}}
        <div class="mb-4 rounded-lg bg-amber-50 border border-amber-200 text-amber-900 dark:bg-amber-900/30 dark:border-amber-800 dark:text-amber-100 px-4 py-3 text-sm">
            Die Lernplattform von {{ $currentTenant->name }} ist {{ $currentTenant->status === 'closed' ? 'geschlossen' : 'vorübergehend gesperrt' }}.
            Nach der Anmeldung kannst du nur noch deine Daten herunterladen oder dein Konto löschen.
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <div>
            <x-input-label for="email" value="E-Mail-Adresse" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" placeholder="name@beispiel.de" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password" value="Passwort" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="current-password" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="flex items-center justify-between">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded border-slate-300 text-blue-600 shadow-sm focus:ring-blue-500" name="remember">
                <span class="ms-2 text-sm text-slate-500 dark:text-slate-400">Angemeldet bleiben</span>
            </label>

            @if (Route::has('password.request'))
                <a class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200" href="{{ route('password.request') }}">
                    Passwort vergessen?
                </a>
            @endif
        </div>

        <button type="submit" class="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg font-semibold text-white transition hover:opacity-90"
                style="background-color: var(--brand-primary, #005FD7)">
            Anmelden
        </button>

        @if (Route::has('register'))
            <p class="text-center text-sm text-slate-500 dark:text-slate-400 pt-2">
                Noch kein Konto?
                <a href="{{ route('register') }}" class="font-semibold" style="color: var(--brand-primary, #005FD7)">Jetzt registrieren</a>
            </p>
        @endif
    </form>
</x-guest-layout>

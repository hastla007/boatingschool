<x-guest-layout>
    <h2 class="text-xl font-bold text-slate-800 dark:text-white mb-1">Passwort vergessen</h2>
    <p class="text-sm text-slate-400 mb-6">
        Kein Problem. Gib deine E-Mail-Adresse ein, wir senden dir einen Link zum Zurücksetzen.
    </p>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <div>
            <x-input-label for="email" value="E-Mail-Adresse" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <button type="submit" class="w-full px-4 py-2.5 rounded-lg font-semibold text-white transition hover:opacity-90" style="background-color: var(--brand-primary, #005FD7)">
            Link zum Zurücksetzen senden
        </button>
    </form>
</x-guest-layout>

<x-guest-layout>
    <h2 class="text-xl font-bold text-slate-800 dark:text-white mb-2">Passwort bestätigen</h2>
    <p class="text-sm text-slate-400 mb-6">Dies ist ein geschützter Bereich. Bitte bestätige dein Passwort, um fortzufahren.</p>

    <form method="POST" action="{{ route('password.confirm') }}" class="space-y-4">
        @csrf

        <div>
            <x-input-label for="password" value="Passwort" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <button type="submit" class="w-full px-4 py-2.5 rounded-lg font-semibold text-white transition hover:opacity-90" style="background-color: var(--brand-primary, #005FD7)">
            Bestätigen
        </button>
    </form>
</x-guest-layout>

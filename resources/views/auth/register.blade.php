<x-guest-layout>
    <h2 class="text-xl font-bold text-slate-800 dark:text-white mb-1">Konto erstellen</h2>
    <p class="text-sm text-slate-400 mb-6">Registriere dich, um mit dem Lernen zu starten.</p>

    <form method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf

        <div>
            <x-input-label for="name" value="Name" />
            <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" placeholder="Max Mustermann" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="email" value="E-Mail-Adresse" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" placeholder="name@beispiel.de" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password" value="Passwort" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password_confirmation" value="Passwort bestätigen" />
            <x-text-input id="password_confirmation" class="block mt-1 w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <button type="submit" class="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg font-semibold text-white transition hover:opacity-90"
                style="background-color: var(--brand-primary, #005FD7)">
            Registrieren
        </button>

        <p class="text-center text-sm text-slate-500 dark:text-slate-400 pt-2">
            Schon registriert?
            <a href="{{ route('login') }}" class="font-semibold" style="color: var(--brand-primary, #005FD7)">Jetzt anmelden</a>
        </p>
    </form>
</x-guest-layout>

<x-guest-layout>
    <h2 class="text-xl font-bold text-slate-800 dark:text-white mb-1">Konto erstellen</h2>
    @unless ($registrationClosed ?? false)
        <p class="text-sm text-slate-600 dark:text-slate-400 mb-6">Registriere dich, um mit dem Lernen zu starten.</p>
    @endunless

    {{-- Bug-Review M3 (Folgefehler): der Status-Flash wurde bisher gesetzt,
    aber nirgends in dieser View gerendert -- seit die Registrierung nicht
    mehr sofort einloggt (siehe RegisteredUserController), ist das die
    einzige Rückmeldung, die ein Nutzer nach dem Absenden überhaupt sieht. --}}
    <x-auth-session-status class="mb-4" :status="session('status')" />

    {{-- Selbstregistrierung von der Bootsschule abgeschaltet (Branding-Schalter). --}}
    @if ($registrationClosed ?? false)
        <div role="status" class="rounded-lg border border-slate-200 bg-slate-50 dark:bg-slate-800 dark:border-slate-700 px-4 py-3 text-sm text-slate-700 dark:text-slate-200">
            Bei {{ $currentTenant->name ?? 'dieser Bootsschule' }} werden Konten nur auf Einladung der Bootsschule angelegt.
            Du bekommst dann eine E-Mail mit einem Link, über den du dein Passwort festlegst.
        </div>
        <p class="text-center text-sm text-slate-500 dark:text-slate-400 pt-4">
            Schon ein Konto?
            <a href="{{ route('login') }}" class="font-semibold" style="color: var(--brand-primary, #005FD7)">Jetzt anmelden</a>
        </p>
    @else
    <form method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf
        @include('auth.partials.bot-check')

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

        {{-- Optional: ein Code der Bootsschule wird direkt nach dem Anlegen eingelöst. --}}
        <div>
            <x-input-label for="coupon_code" value="Zugangscode (optional)" />
            <x-text-input id="coupon_code" class="block mt-1 w-full font-mono uppercase tracking-wider" type="text" name="coupon_code" :value="old('coupon_code')" autocomplete="off" placeholder="XXXX-XXXX-XXXX-XXXX" maxlength="64" />
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Hast du von deiner Bootsschule einen Code bekommen? Er wird nach der Registrierung eingelöst; das Ergebnis siehst du nach der Anmeldung.</p>
            <x-input-error :messages="$errors->get('coupon_code')" class="mt-2" />
        </div>

        @include('auth.partials.age-confirmation')

        {{-- Informationspflicht (Art. 13 DSGVO): Hinweis auf die Datenschutzerklärung. --}}
        <p class="text-xs text-slate-500 dark:text-slate-400">
            Informationen zur Verarbeitung deiner Daten findest du in der
            <a href="{{ route('legal.show', 'datenschutz') }}" target="_blank" rel="noopener" class="underline">Datenschutzerklärung</a>.
        </p>

        <button type="submit" class="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg font-semibold text-white transition hover:opacity-90"
                style="background-color: var(--brand-primary, #005FD7)">
            Registrieren
        </button>

        <p class="text-center text-sm text-slate-500 dark:text-slate-400 pt-2">
            Schon registriert?
            <a href="{{ route('login') }}" class="font-semibold" style="color: var(--brand-primary, #005FD7)">Jetzt anmelden</a>
        </p>
    </form>
    @endif
</x-guest-layout>

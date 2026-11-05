{{-- Zwei-Faktor-Anmeldung: Code aus der Authenticator-App nach dem Passwort (TwoFactorController). --}}
<x-guest-layout>
    <h2 class="text-xl font-bold text-slate-800 dark:text-white mb-1">Bestätigungscode</h2>
    <p class="text-sm text-slate-600 dark:text-slate-400 mb-6">Gib den 6-stelligen Code aus deiner Authenticator-App ein.</p>

    <form method="POST" action="{{ url('two-factor-challenge') }}" class="space-y-4" x-data="{ recovery: false }">
        @csrf
        <div x-show="! recovery">
            <x-input-label for="code" value="Code" />
            <x-text-input id="code" class="block mt-1 w-full font-mono tracking-widest" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus x-bind:disabled="recovery" />
        </div>
        <div x-show="recovery" style="display: none">
            <x-input-label for="recovery_code" value="Wiederherstellungscode" />
            <x-text-input id="recovery_code" class="block mt-1 w-full font-mono" type="text" name="recovery_code" autocomplete="off" x-bind:disabled="! recovery" />
        </div>
        <x-input-error :messages="$errors->get('code')" class="mt-2" />

        <button type="submit" class="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg font-semibold text-white transition hover:opacity-90"
                style="background-color: var(--brand-primary, #005FD7)">
            Anmelden
        </button>
        <button type="button" class="text-sm text-slate-500 dark:text-slate-400 underline" @click="recovery = ! recovery"
                x-text="recovery ? 'Code aus der App verwenden' : 'Handy nicht zur Hand? Wiederherstellungscode verwenden'"></button>
    </form>
</x-guest-layout>

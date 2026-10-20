<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Willkommensbox</h2>
    </x-slot>

    @if ($state === 'locked')
        <div class="max-w-2xl mx-auto bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-8 text-center">
            <x-icon name="lock-closed" class="w-10 h-10 mx-auto text-slate-300 mb-4" />
            <h3 class="font-semibold text-lg text-slate-800 dark:text-white mb-2">Du erfüllst noch nicht alle Voraussetzungen zur Bestellung.</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Aktuell hast du <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $percent }}% Kursfortschritt</span>.
                Für die Bestellung der Willkommensbox benötigst du mindestens <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $threshold }}% Kursfortschritt</span>.
            </p>
            <div class="mt-6 w-full bg-slate-100 dark:bg-slate-700 rounded-full h-2">
                <div class="h-2 rounded-full" style="width: {{ min($percent, 100) }}%; background-color: var(--brand-secondary, #00A8A8)"></div>
            </div>
            <a href="{{ route('dashboard') }}" class="inline-flex items-center justify-center mt-6 px-5 py-2 rounded-lg text-white text-sm font-semibold hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                Weiterlernen
            </a>
        </div>
    @elseif ($state === 'submitted')
        <div class="max-w-2xl mx-auto bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-8">
            <div class="text-center mb-6">
                <x-icon name="check-circle" class="w-10 h-10 mx-auto text-emerald-500 mb-3" />
                <h3 class="font-semibold text-lg text-slate-800 dark:text-white">Deine Willkommensbox ist bestellt.</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Wir versenden sie an die folgende Adresse.</p>
            </div>
            <dl class="divide-y divide-slate-100 dark:divide-slate-700 border-t border-b border-slate-100 dark:border-slate-700">
                <div class="py-2.5 grid grid-cols-2 gap-4 text-sm">
                    <dt class="text-slate-500 dark:text-slate-400">Straße</dt>
                    <dd class="text-slate-800 dark:text-slate-200 font-medium">{{ $order->street }}</dd>
                </div>
                <div class="py-2.5 grid grid-cols-2 gap-4 text-sm">
                    <dt class="text-slate-500 dark:text-slate-400">PLZ / Ort</dt>
                    <dd class="text-slate-800 dark:text-slate-200 font-medium">{{ $order->postal_code }} {{ $order->city }}</dd>
                </div>
                <div class="py-2.5 grid grid-cols-2 gap-4 text-sm">
                    <dt class="text-slate-500 dark:text-slate-400">Land</dt>
                    <dd class="text-slate-800 dark:text-slate-200 font-medium">{{ $order->country }}</dd>
                </div>
                @if ($order->note)
                    <div class="py-2.5 grid grid-cols-2 gap-4 text-sm">
                        <dt class="text-slate-500 dark:text-slate-400">Anmerkung</dt>
                        <dd class="text-slate-800 dark:text-slate-200 font-medium">{{ $order->note }}</dd>
                    </div>
                @endif
            </dl>
        </div>
    @elseif ($state === 'form')
        <div class="max-w-2xl mx-auto bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-8">
            <h3 class="font-semibold text-lg text-slate-800 dark:text-white mb-1">Bestelle deine Willkommensbox</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">Mit deiner Lernplattform ist eine Willkommensbox inklusive &mdash; bitte gib an, wohin wir sie schicken sollen.</p>

            <form method="POST" action="{{ route('willkommensbox.store') }}" class="space-y-5">
                @csrf
                <div>
                    <x-input-label for="street" value="Straße & Hausnummer *" />
                    <x-text-input id="street" name="street" type="text" class="mt-1 block w-full" :value="old('street', $user->street)" required />
                    <x-input-error :messages="$errors->get('street')" class="mt-2" />
                </div>
                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="postal_code" value="PLZ *" />
                        <x-text-input id="postal_code" name="postal_code" type="text" class="mt-1 block w-full" :value="old('postal_code', $user->postal_code)" required />
                        <x-input-error :messages="$errors->get('postal_code')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="city" value="Ort *" />
                        <x-text-input id="city" name="city" type="text" class="mt-1 block w-full" :value="old('city', $user->city)" required />
                        <x-input-error :messages="$errors->get('city')" class="mt-2" />
                    </div>
                </div>
                <div>
                    <x-input-label for="country" value="Land *" />
                    <select id="country" name="country" required
                            class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                        <option value="" disabled @selected(! old('country', $user->country))>Bitte wählen…</option>
                        @foreach (\App\Support\Countries::OPTIONS as $countryOption)
                            <option value="{{ $countryOption }}" @selected(old('country', $user->country) === $countryOption)>{{ $countryOption }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('country')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="note" value="Anmerkung (optional)" />
                    <textarea id="note" name="note" rows="3"
                              class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ old('note') }}</textarea>
                    <x-input-error :messages="$errors->get('note')" class="mt-2" />
                </div>

                <button type="submit" class="w-full inline-flex items-center justify-center px-5 py-2.5 rounded-lg text-white text-sm font-semibold hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                    Jetzt bestellen
                </button>
            </form>
        </div>
    @endif
</x-app-layout>

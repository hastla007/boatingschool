<x-superadmin-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Website-Einstellungen</h1>
            <a href="{{ route('superadmin.mail-templates.edit') }}" class="px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 text-sm font-medium hover:bg-slate-50 dark:hover:bg-slate-700 transition">Mailvorlagen</a>
        </div>
    </x-slot>

    <div class="max-w-3xl bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6">
        <form method="POST" action="{{ route('superadmin.settings.update') }}" class="space-y-5">
            @csrf
            @method('PATCH')

            <div>
                <x-input-label for="site_name" value="Name der Plattform" />
                <x-text-input id="site_name" name="site_name" type="text" class="mt-1 block w-full" :value="old('site_name', $settings->site_name)" required />
                <x-input-error :messages="$errors->get('site_name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="support_email" value="Zentrale Support-E-Mail" />
                <x-text-input id="support_email" name="support_email" type="email" class="mt-1 block w-full" :value="old('support_email', $settings->support_email)" />
                <x-input-error :messages="$errors->get('support_email')" class="mt-2" />
            </div>

            <div class="pt-2 border-t border-slate-100 dark:border-slate-700">
                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" name="maintenance_mode" value="1" @checked(old('maintenance_mode', $settings->maintenance_mode)) class="rounded border-slate-300">
                    Wartungsmodus aktiv
                </label>
                <div class="mt-3">
                    <x-input-label for="maintenance_message" value="Wartungshinweis" />
                    <textarea id="maintenance_message" name="maintenance_message" rows="3"
                              class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ old('maintenance_message', $settings->maintenance_message) }}</textarea>
                </div>
            </div>

            {{-- Impressum und Datenschutzerklärung für alle Bootsschulen (LegalPages). --}}
            <div class="pt-2 border-t border-slate-100 dark:border-slate-700 space-y-4">
                <h2 class="font-semibold text-slate-800 dark:text-slate-100">Impressum und Datenschutzerklärung</h2>
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    Eine Vorlage für alle Bootsschulen. Jede Schule hat unter ihrer Adresse eigene Seiten
                    <code>/impressum</code> und <code>/datenschutz</code>; die Platzhalter werden mit ihren Stammdaten aus dem
                    Branding gefüllt. Eine Zeile, deren Platzhalter alle leer sind, entfällt. Formatierung: <code>## Überschrift</code>,
                    <code>- Aufzählung</code>, <code>**fett**</code>, Leerzeile = neuer Absatz.
                </p>
                <details class="text-sm text-slate-600 dark:text-slate-300">
                    <summary class="cursor-pointer font-medium">Platzhalter</summary>
                    <ul class="mt-2 space-y-1">
                        @foreach (\App\Support\LegalPages::PLACEHOLDERS as $key => $meaning)
                            <li><code>{{ '{'.$key.'}' }}</code> – {{ $meaning }}</li>
                        @endforeach
                    </ul>
                </details>
                @if (! $settings->imprint_template || ! $settings->privacy_template)
                    <p class="text-sm p-3 rounded-lg bg-amber-50 text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">
                        Für {{ ! $settings->imprint_template && ! $settings->privacy_template ? 'beide Seiten' : (! $settings->imprint_template ? 'das Impressum' : 'die Datenschutzerklärung') }}
                        gilt der mitgelieferte Entwurf. Er ist nur ein Ausgangspunkt: Bitte rechtlich prüfen lassen (z. B. Anwalt oder Generator) und hier anpassen.
                    </p>
                @endif
                <div>
                    <x-input-label for="operator_info" value="Betreiber der Plattform ({betreiber})" />
                    <textarea id="operator_info" name="operator_info" rows="4" placeholder="Firma, Anschrift, E-Mail des Plattform-Betreibers"
                              class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ old('operator_info', $settings->operator_info) }}</textarea>
                    <x-input-error :messages="$errors->get('operator_info')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="imprint_template" value="Vorlage Impressum" />
                    <textarea id="imprint_template" name="imprint_template" rows="14"
                              class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm font-mono">{{ old('imprint_template', $settings->imprint_template ?: \App\Support\LegalPages::DEFAULT_IMPRESSUM) }}</textarea>
                    <x-input-error :messages="$errors->get('imprint_template')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="privacy_template" value="Vorlage Datenschutzerklärung" />
                    <textarea id="privacy_template" name="privacy_template" rows="20"
                              class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm font-mono">{{ old('privacy_template', $settings->privacy_template ?: \App\Support\LegalPages::DEFAULT_DATENSCHUTZ) }}</textarea>
                    <x-input-error :messages="$errors->get('privacy_template')" class="mt-2" />
                </div>
            </div>

            <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: #005FD7">
                Speichern
            </button>
        </form>
    </div>
</x-superadmin-layout>

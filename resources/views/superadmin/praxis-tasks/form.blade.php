<x-superadmin-layout>
    <x-slot name="header">
        <div>
            <div class="text-xs text-slate-600 dark:text-slate-400"><a href="{{ route('superadmin.praxis-tasks.index', $course) }}" class="hover:underline">Praxistrainer &middot; {{ $course->name }}</a></div>
            <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">{{ $task ? 'Karte bearbeiten' : 'Neue Karte' }}</h1>
        </div>
    </x-slot>

    @php($input = 'mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm')
    <div class="max-w-3xl bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6">
        <form method="POST" action="{{ $task ? route('superadmin.praxis-tasks.update', $task) : route('superadmin.praxis-tasks.store', $course) }}" enctype="multipart/form-data" class="space-y-5">
            @csrf
            @if ($task)
                @method('PATCH')
            @endif

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="kategorie" value="Kategorie" />
                    <input id="kategorie" name="kategorie" list="kategorien" required maxlength="255" value="{{ old('kategorie', $task?->kategorie) }}" class="{{ $input }}">
                    <datalist id="kategorien">
                        @foreach ($categories as $category)
                            <option value="{{ $category }}"></option>
                        @endforeach
                    </datalist>
                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Bestehende Kategorie wählen oder neue eintippen.</p>
                    <x-input-error :messages="$errors->get('kategorie')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="unterkategorie" value="Unterkategorie / Kartentitel" />
                    <x-text-input id="unterkategorie" name="unterkategorie" type="text" maxlength="255" class="mt-1 block w-full" :value="old('unterkategorie', $task?->unterkategorie)" />
                    <x-input-error :messages="$errors->get('unterkategorie')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="pruefungsbezug" value="Prüfungsbezug (optional)" />
                    <x-text-input id="pruefungsbezug" name="pruefungsbezug" type="text" maxlength="255" class="mt-1 block w-full" :value="old('pruefungsbezug', $task?->pruefungsbezug)" />
                </div>
                <div>
                    <x-input-label for="sort_order" value="Reihenfolge (optional)" />
                    <x-text-input id="sort_order" name="sort_order" type="number" min="1" class="mt-1 block w-full" :value="old('sort_order', $task?->sort_order)" />
                    <x-input-error :messages="$errors->get('sort_order')" class="mt-2" />
                </div>
            </div>

            <div>
                <x-input-label for="frage" value="Frage / Aufgabe" />
                <textarea id="frage" name="frage" rows="3" required class="{{ $input }}">{{ old('frage', $task?->frage) }}</textarea>
                <x-input-error :messages="$errors->get('frage')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="antwort" value="Antwort" />
                <textarea id="antwort" name="antwort" rows="6" required class="{{ $input }}">{{ old('antwort', $task?->antwort) }}</textarea>
                <x-input-error :messages="$errors->get('antwort')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="erklaerung" value="Erklärung (optional)" />
                <textarea id="erklaerung" name="erklaerung" rows="4" class="{{ $input }}">{{ old('erklaerung', $task?->erklaerung) }}</textarea>
            </div>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="quelle" value="Quelle (optional)" />
                    <x-text-input id="quelle" name="quelle" type="text" maxlength="255" class="mt-1 block w-full" :value="old('quelle', $task?->quelle)" />
                </div>
                <div>
                    <x-input-label for="quellen_url" value="Quellen-Link (optional)" />
                    <x-text-input id="quellen_url" name="quellen_url" type="url" maxlength="2048" class="mt-1 block w-full" :value="old('quellen_url', $task?->quellen_url)" />
                    <x-input-error :messages="$errors->get('quellen_url')" class="mt-2" />
                </div>
            </div>

            <fieldset class="border-t border-slate-100 dark:border-slate-700 pt-4 space-y-3">
                <legend class="text-sm font-medium text-slate-700 dark:text-slate-200">Bild</legend>
                @if ($task?->media)
                    <div class="flex items-start gap-3">
                        <img src="{{ $task->media->storage_path }}" alt="{{ $task->media->alt_text }}" class="w-40 rounded-xl border border-slate-100 dark:border-slate-700">
                        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                            <input type="checkbox" name="remove_image" value="1" class="rounded border-slate-300"> Bild entfernen
                        </label>
                    </div>
                @endif
                <div>
                    <x-input-label for="image" value="{{ $task?->media ? 'Neues Bild (ersetzt das bisherige)' : 'Bild hochladen (optional)' }}" />
                    <input id="image" name="image" type="file" accept="image/png,image/jpeg,image/webp" class="mt-1 block w-full text-sm">
                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">PNG, JPG oder WebP, max. 4 MB. Bei Manövern, Knoten und Sicherheit zeigt der Trainer das Bild erst mit der Lösung.</p>
                    <x-input-error :messages="$errors->get('image')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="alt_text" value="Bildbeschreibung (für Screenreader)" />
                    <x-text-input id="alt_text" name="alt_text" type="text" maxlength="255" class="mt-1 block w-full" :value="old('alt_text', $task?->media?->alt_text)" />
                </div>
            </fieldset>

            <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: #005FD7">
                {{ $task ? 'Änderungen speichern' : 'Karte anlegen' }}
            </button>
        </form>
    </div>
</x-superadmin-layout>

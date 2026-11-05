<x-superadmin-layout>
    <x-slot name="header">
        <div>
            <div class="text-xs text-slate-600 dark:text-slate-400"><a href="{{ route('superadmin.settings.edit') }}" class="hover:underline">Website</a></div>
            <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Mailvorlagen</h1>
        </div>
    </x-slot>

    <form method="POST" action="{{ route('superadmin.mail-templates.update') }}" class="max-w-3xl space-y-4">
        @csrf
        @method('PATCH')
        <p class="text-sm text-slate-600 dark:text-slate-400">
            Je Mail lassen sich der Betreff sowie ein Text vor und nach dem festen Inhalt anpassen. Der feste Inhalt mit Daten, Links und Schaltfläche bleibt immer erhalten.
            Leere Felder verwenden den Standard. Einfache Formatierung mit Markdown (z. B. **fett**) ist möglich.
        </p>
        @if ($errors->any())
            <p class="text-sm text-red-700 dark:text-red-300">{{ $errors->first() }}</p>
        @endif

        @foreach ($definitions as $key => $definition)
            @php
                $t = $stored[$key] ?? [];
            @endphp
            <fieldset class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 space-y-3">
                <legend class="sr-only">{{ $definition['label'] }}</legend>
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="font-medium text-slate-700 dark:text-slate-200">{{ $definition['label'] }}</h3>
                    @if ($definition['placeholders'])
                        <span class="text-xs text-slate-600 dark:text-slate-400">Platzhalter: {{ collect($definition['placeholders'])->map(fn ($p) => '{'.$p.'}')->implode(' ') }}</span>
                    @endif
                </div>
                <div>
                    <x-input-label for="{{ $key }}-subject" value="Betreff" />
                    <x-text-input id="{{ $key }}-subject" name="templates[{{ $key }}][subject]" type="text" maxlength="200" class="mt-1 block w-full" :value="old('templates.'.$key.'.subject', $t['subject'] ?? '')" placeholder="Standard: {{ $definition['subject'] }}" />
                </div>
                <div class="grid sm:grid-cols-2 gap-3">
                    <div>
                        <x-input-label for="{{ $key }}-intro" value="Text davor" />
                        <textarea id="{{ $key }}-intro" name="templates[{{ $key }}][intro]" rows="3" maxlength="3000" class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm" placeholder="z. B. Hallo, schön dass du dabei bist!">{{ old('templates.'.$key.'.intro', $t['intro'] ?? '') }}</textarea>
                    </div>
                    <div>
                        <x-input-label for="{{ $key }}-outro" value="Text danach" />
                        <textarea id="{{ $key }}-outro" name="templates[{{ $key }}][outro]" rows="3" maxlength="3000" class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm" placeholder="z. B. Viel Erfolg beim Lernen!">{{ old('templates.'.$key.'.outro', $t['outro'] ?? '') }}</textarea>
                    </div>
                </div>
            </fieldset>
        @endforeach

        <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90">Speichern</button>
    </form>
</x-superadmin-layout>

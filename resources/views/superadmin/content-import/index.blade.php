<x-superadmin-layout>
    <x-slot name="header">
        <div>
            <div class="text-xs text-slate-600 dark:text-slate-400"><a href="{{ route('superadmin.modules.index') }}" class="hover:underline">Module &amp; Fragen</a></div>
            <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Fragen importieren</h1>
        </div>
    </x-slot>

    <div class="max-w-3xl space-y-4">
        @if ($report)
            <div class="rounded-2xl p-5 {{ $report['ok'] ? 'bg-emerald-50 border border-emerald-200 dark:bg-emerald-900/20 dark:border-emerald-800' : 'bg-red-50 border border-red-200 dark:bg-red-900/20 dark:border-red-800' }}">
                <h3 class="font-medium text-slate-800 dark:text-slate-100 mb-2">
                    {{ $report['ok'] ? 'Import abgeschlossen' : 'Import fehlgeschlagen' }}: {{ $report['label'] }} <span class="font-normal text-sm">({{ $report['file'] }})</span>
                </h3>
                <ul class="text-sm text-slate-700 dark:text-slate-200 space-y-0.5 max-h-96 overflow-y-auto">
                    @forelse ($report['lines'] as $line)
                        <li class="{{ str_starts_with($line, 'Zeile') ? 'pl-4 text-xs text-slate-600 dark:text-slate-400' : '' }}">{{ $line }}</li>
                    @empty
                        <li>Keine Ausgabe.</li>
                    @endforelse
                </ul>
                @if ($report['ok'] && collect($report['lines'])->contains(fn ($l) => str_starts_with($l, 'Neue Revision') && ! str_ends_with($l, ': 0')))
                    <p class="mt-3 text-sm text-slate-700 dark:text-slate-200">Geänderte Fragen warten als neue Fassung auf Prüfung: in der Fragenliste des Moduls als „Review ausstehend“ markiert, im Fragen-Editor mit Vergleich veröffentlichen oder verwerfen.</p>
                @endif
            </div>
        @endif

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-1">Fragenbasis (Excel)</h3>
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-3">
                Wie <code>php artisan content:import</code>: neue Fragen werden angelegt, unveränderte übersprungen, geänderte als neue Fassung zur Prüfung abgelegt.
            </p>
            <form method="POST" action="{{ route('superadmin.content-import.questions') }}" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <div>
                    <x-input-label for="questions-file" value="Datei (.xlsx, max. 20 MB)" />
                    <input id="questions-file" name="file" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required class="mt-1 block w-full text-sm">
                    @if (old('_form') === 'questions')
                        <x-input-error :messages="$errors->get('file')" class="mt-2" />
                    @endif
                </div>
                <div>
                    <x-input-label for="sheet" value="Tabellenblatt" />
                    <x-text-input id="sheet" name="sheet" type="text" class="mt-1 block w-full" :value="old('sheet', 'Central Question Pool')" />
                </div>
                <input type="hidden" name="_form" value="questions">
                <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90 transition">Fragen importieren</button>
            </form>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-1">Erklärungen (CSV)</h3>
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-3">
                Wie <code>php artisan content:import-feedback</code>: aktualisiert die Erklärungen „richtig/falsch“ bestehender Fragen (Semikolon-getrennt, Spalten wie in der mitgelieferten Feedback-Datei).
            </p>
            <form method="POST" action="{{ route('superadmin.content-import.feedback') }}" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <div>
                    <x-input-label for="feedback-file" value="Datei (.csv, max. 10 MB)" />
                    <input id="feedback-file" name="file" type="file" accept=".csv,text/csv" required class="mt-1 block w-full text-sm">
                    @if (old('_form') === 'feedback')
                        <x-input-error :messages="$errors->get('file')" class="mt-2" />
                    @endif
                </div>
                <input type="hidden" name="_form" value="feedback">
                <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90 transition">Erklärungen importieren</button>
            </form>
        </div>
    </div>
</x-superadmin-layout>

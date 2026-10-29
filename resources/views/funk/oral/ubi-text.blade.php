<x-app-layout>
    <x-slot name="header">
        @include('funk._header', ['title' => 'Mündliche Prüfung', 'icon' => 'microphone'])
    </x-slot>

    @php
        $label = $modul === 'aufnahme' ? 'Aufnahme' : 'Abgabe';
        $route = fn (int $n) => route('funk.oral.ubi', ['course' => $course, 'modul' => $modul, 'number' => $n]);
        // Abgabe: *Wort* hervorheben; Lösung = Zeile mit buchstabierten Wörtern.
        $lines = collect($text['lines'] ?? [])->map(fn ($line) => [
            'html' => preg_replace('/\*([^*]+)\*/', '<mark class="font-semibold underline decoration-2 underline-offset-2" style="background: transparent; color: var(--brand-secondary, #007A7A)">$1</mark>', e($line)),
            'spelled' => preg_replace_callback('/\*([^*]+)\*/', fn ($m) => $m[1].' ('.\App\Support\FunkTraining::spellGerman($m[1]).')', $line),
        ]);
    @endphp

    <div class="space-y-4">
        @include('funk._tabs', [
            'tabs' => collect($catalogs)->mapWithKeys(fn ($c) => [$c => route('funk.oral.index', ['course' => $course, 'teil' => $c])])->all(),
            'active' => 'UBI',
        ])

        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('funk.oral.index', ['course' => $course, 'teil' => 'UBI', 'modul' => $modul]) }}" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50">
                <x-icon name="chevron-right" class="w-4 h-4 rotate-180" /> Zur Übersicht
            </a>
            <h2 class="font-semibold text-lg text-slate-800 dark:text-white">{{ $label }} {{ $text['number'] }}</h2>
            <span class="text-xs rounded-full bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 px-2 py-0.5">{{ $text['number'] }} / {{ $total }}</span>
            <form method="POST" action="{{ route('funk.completed', ['course' => $course, 'type' => $modul, 'catalog' => 'UBI', 'number' => $text['number']]) }}" class="sm:ml-auto">
                @csrf
                <button type="submit" class="inline-flex items-center gap-1.5 rounded-full border px-4 py-1.5 text-sm font-medium transition {{ $completed ? 'text-white border-transparent' : 'bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 border-slate-300 dark:border-slate-600 hover:bg-slate-50' }}"
                        @if ($completed) style="background-color: var(--brand-secondary, #007A7A)" @endif>
                    <x-icon name="check-circle" class="w-4 h-4" /> {{ $completed ? 'Erledigt' : 'Als erledigt markieren' }}
                </button>
            </form>
        </div>

        @if ($modul === 'aufnahme')
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 flex items-center gap-3">
                <button type="button" x-data @click="funkSpeak(@js($text['text']), 'de-DE', 0.85)" class="w-11 h-11 rounded-full flex items-center justify-center text-white shrink-0" style="background-color: var(--brand-secondary, #007A7A)" aria-label="Text vorlesen">
                    <x-icon name="play" class="w-5 h-5" />
                </button>
                <div class="text-sm text-slate-700 dark:text-slate-200">Funktext anhören <span class="text-slate-500 dark:text-slate-400">(Sprachausgabe deines Browsers)</span></div>
            </div>

            <form method="POST" action="{{ route('funk.oral.ubi.evaluate', ['course' => $course, 'number' => $text['number']]) }}" class="space-y-4">
                @csrf
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
                    <label for="transcript" class="font-medium text-slate-800 dark:text-white block mb-2">Mitschreiben</label>
                    <textarea id="transcript" name="transcript" rows="5" placeholder="Hör dir den Text an und schreib ihn hier mit …" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ old('transcript', $transcript) }}</textarea>
                    @error('transcript')
                        <p class="mt-1 text-sm text-rose-600">Schreib zuerst mit, dann kannst du auswerten.</p>
                    @enderror
                    @if ($evaluation)
                        <div class="mt-3 text-sm font-medium text-slate-700 dark:text-slate-200">{{ $evaluation['percent'] }}&nbsp;% der Wörter getroffen</div>
                        @if ($evaluation['missing'])
                            <div class="mt-1 text-sm text-rose-600 dark:text-rose-400">Fehlt oder anders geschrieben: {{ implode(', ', array_map(fn ($w) => mb_strtolower($w), array_slice($evaluation['missing'], 0, 25))) }}</div>
                        @endif
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg px-5 py-2.5 text-sm font-semibold text-white" style="background-color: var(--brand-secondary, #007A7A)">
                        <x-icon name="check-circle" class="w-4 h-4" /> Auswerten
                    </button>
                </div>
            </form>

            <details class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 text-sm" @if ($evaluation) open @endif>
                <summary class="cursor-pointer font-medium text-slate-700 dark:text-slate-200">Lösung anzeigen</summary>
                <p class="mt-2 text-slate-700 dark:text-slate-300 leading-relaxed">{{ $text['text'] }}</p>
            </details>
        @else
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-2">Vorzulesender Text</div>
                <div class="space-y-1 font-medium text-slate-800 dark:text-slate-100">
                    @foreach ($lines as $line)
                        <div>{!! $line['html'] !!}</div>
                    @endforeach
                </div>
            </div>
            <details class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 text-sm">
                <summary class="cursor-pointer font-medium text-slate-700 dark:text-slate-200">Lösung anzeigen</summary>
                <div class="mt-2 space-y-1 text-slate-700 dark:text-slate-300">
                    @foreach ($lines as $line)
                        <div>{{ $line['spelled'] }}</div>
                    @endforeach
                </div>
                <button type="button" x-data @click="funkSpeak(@js($lines->pluck('spelled')->implode('. ')), 'de-DE', 0.9)" class="mt-3 inline-flex items-center gap-1.5 rounded-full border border-slate-300 dark:border-slate-600 px-3 py-1 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50">
                    <x-icon name="speaker-wave" class="w-4 h-4" /> Lösung vorlesen
                </button>
            </details>
        @endif

        <div class="grid grid-cols-2 gap-3">
            @if ($previous)
                <a href="{{ $route($previous) }}" class="text-center rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 py-2.5 text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-50">&larr; Vorherige</a>
            @else
                <span class="text-center rounded-xl border border-slate-100 dark:border-slate-800 py-2.5 text-sm text-slate-500 dark:text-slate-400">&larr; Vorherige</span>
            @endif
            @if ($next)
                <a href="{{ $route($next) }}" class="text-center rounded-xl py-2.5 text-sm font-semibold text-white hover:opacity-95" style="background-color: var(--brand-secondary, #007A7A)">Nächste &rarr;</a>
            @else
                <a href="{{ route('funk.oral.index', ['course' => $course, 'teil' => 'UBI', 'modul' => $modul]) }}" class="text-center rounded-xl py-2.5 text-sm font-semibold text-white hover:opacity-95" style="background-color: var(--brand-secondary, #007A7A)">Zur Übersicht</a>
            @endif
        </div>
    </div>

    @include('funk._speech')
</x-app-layout>

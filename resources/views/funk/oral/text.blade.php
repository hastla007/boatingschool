<x-app-layout>
    <x-slot name="header">
        @include('funk._header', ['title' => 'Mündliche Prüfung', 'icon' => 'microphone'])
    </x-slot>

    @php
        $languages = ['de' => ['Deutsch', 'de-DE'], 'en' => ['Englisch', 'en-GB']];
        $directionUrl = fn (string $richtung) => route('funk.oral.text', ['course' => $course, 'catalog' => $catalog, 'number' => $text['number'], 'richtung' => $richtung]);
    @endphp

    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('funk.oral.index', ['course' => $course, 'teil' => $catalog, 'modul' => 'texte']) }}" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50">
                <x-icon name="chevron-right" class="w-4 h-4 rotate-180" /> Zur Übersicht
            </a>
            <h2 class="font-semibold text-lg text-slate-800 dark:text-white">Seefunktext {{ $text['number'] }}</h2>
            <span class="text-xs rounded-full bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 px-2 py-0.5">{{ $text['number'] }} / {{ $total }}</span>
            <form method="POST" action="{{ route('funk.completed', ['course' => $course, 'type' => 'text', 'catalog' => $catalog, 'number' => $text['number']]) }}" class="sm:ml-auto">
                @csrf
                <button type="submit" class="inline-flex items-center gap-1.5 rounded-full border px-4 py-1.5 text-sm font-medium transition {{ $completed ? 'text-white border-transparent' : 'bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 border-slate-300 dark:border-slate-600 hover:bg-slate-50' }}"
                        @if ($completed) style="background-color: var(--brand-secondary, #007A7A)" @endif>
                    <x-icon name="check-circle" class="w-4 h-4" /> {{ $completed ? 'Erledigt' : 'Als erledigt markieren' }}
                </button>
            </form>
        </div>

        <x-funk-info>
            So läuft die Prüfung: Der Prüfer liest den Text vor &ndash; du bekommst ihn nicht zu sehen. Du schreibst mit und
            übersetzt danach deine Mitschrift. Übe genauso: anhören, mitschreiben, übersetzen. Der Originaltext bleibt als
            Lösungshilfe eingeklappt.
        </x-funk-info>

        @include('funk._tabs', [
            'tabs' => ['Deutsch → Englisch' => $directionUrl('de-en'), 'Englisch → Deutsch' => $directionUrl('en-de')],
            'active' => $direction === 'de-en' ? 'Deutsch → Englisch' : 'Englisch → Deutsch',
        ])

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 space-y-3">
            <div class="flex items-center gap-3">
                <button type="button" x-data @click="funkSpeak(@js($text[$from]), '{{ $languages[$from][1] }}', 0.85)" class="w-11 h-11 rounded-full flex items-center justify-center text-white shrink-0" style="background-color: var(--brand-secondary, #007A7A)" aria-label="Text vorlesen">
                    <x-icon name="play" class="w-5 h-5" />
                </button>
                <div class="text-sm text-slate-700 dark:text-slate-200">{{ $languages[$from][0] }} anhören <span class="text-slate-500 dark:text-slate-400">(Sprachausgabe deines Browsers)</span></div>
            </div>
            <details class="text-sm">
                <summary class="cursor-pointer font-medium text-slate-700 dark:text-slate-200">Originaltext anzeigen (Lösungshilfe)</summary>
                <p class="mt-2 text-slate-700 dark:text-slate-300 leading-relaxed">{{ $text[$from] }}</p>
            </details>
        </div>

        <form method="POST" action="{{ route('funk.oral.text.evaluate', ['course' => $course, 'catalog' => $catalog, 'number' => $text['number']]) }}" class="space-y-4">
            @csrf
            <input type="hidden" name="richtung" value="{{ $direction }}">
            @foreach (['transcript' => ['Deine Mitschrift ('.$languages[$from][0].')', 'Spiel den Text ab und schreib ihn hier mit …', $from], 'translation' => [$languages[$to][0].'e Übersetzung', 'Schreib hier deine Übersetzung …', $to]] as $name => [$label, $placeholder, $lang])
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
                    <label for="{{ $name }}" class="font-medium text-slate-800 dark:text-white block mb-2">{{ $label }}</label>
                    <textarea id="{{ $name }}" name="{{ $name }}" rows="5" placeholder="{{ $placeholder }}" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ old($name, $answers[$name] ?? '') }}</textarea>
                    @if ($evaluation)
                        @php $result = $evaluation[$name]; @endphp
                        <div class="mt-3 text-sm font-medium text-slate-700 dark:text-slate-200">{{ $result['percent'] }}&nbsp;% der Wörter getroffen</div>
                        @if ($result['missing'])
                            <div class="mt-1 text-sm text-rose-600 dark:text-rose-400">Fehlt oder anders geschrieben: {{ implode(', ', array_map(fn ($w) => mb_strtolower($w), array_slice($result['missing'], 0, 25))) }}</div>
                        @endif
                        <div class="mt-3 rounded-lg bg-slate-50 dark:bg-slate-900/40 border border-slate-200 dark:border-slate-700 p-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-1">Lösung</div>
                            <div class="text-sm text-slate-700 dark:text-slate-200">{{ $text[$lang] }}</div>
                        </div>
                    @endif
                </div>
            @endforeach
            @error('transcript')
                <p class="text-sm text-rose-600">{{ $message }}</p>
            @enderror
            <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg px-5 py-2.5 text-sm font-semibold text-white" style="background-color: var(--brand-secondary, #007A7A)">
                <x-icon name="check-circle" class="w-4 h-4" /> Auswerten
            </button>
        </form>

        <div class="grid grid-cols-2 gap-3">
            @if ($previous)
                <a href="{{ route('funk.oral.text', ['course' => $course, 'catalog' => $catalog, 'number' => $previous, 'richtung' => $direction]) }}" class="text-center rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 py-2.5 text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-50">&larr; Vorheriger</a>
            @else
                <span class="text-center rounded-xl border border-slate-100 dark:border-slate-800 py-2.5 text-sm text-slate-500 dark:text-slate-400">&larr; Vorheriger</span>
            @endif
            @if ($next)
                <a href="{{ route('funk.oral.text', ['course' => $course, 'catalog' => $catalog, 'number' => $next, 'richtung' => $direction]) }}" class="text-center rounded-xl py-2.5 text-sm font-semibold text-white hover:opacity-95" style="background-color: var(--brand-secondary, #007A7A)">Nächster &rarr;</a>
            @else
                <a href="{{ route('funk.oral.index', ['course' => $course, 'teil' => $catalog, 'modul' => 'texte']) }}" class="text-center rounded-xl py-2.5 text-sm font-semibold text-white hover:opacity-95" style="background-color: var(--brand-secondary, #007A7A)">Zur Übersicht</a>
            @endif
        </div>
    </div>

    @include('funk._speech')
</x-app-layout>

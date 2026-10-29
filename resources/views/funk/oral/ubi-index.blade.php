<x-app-layout>
    <x-slot name="header">
        @include('funk._header', ['title' => 'Mündliche Prüfung', 'icon' => 'microphone'])
    </x-slot>

    @php
        $modulUrl = fn (string $modul) => route('funk.oral.index', ['course' => $course, 'teil' => 'UBI', 'modul' => $modul]);
    @endphp

    <div class="space-y-4">
        @include('funk._tabs', [
            'tabs' => collect($catalogs)->mapWithKeys(fn ($c) => [$c => route('funk.oral.index', ['course' => $course, 'teil' => $c])])->all(),
            'active' => 'UBI',
        ])
        @include('funk._tabs', [
            'tabs' => ['Aufnahme' => $modulUrl('aufnahme'), 'Abgabe' => $modulUrl('abgabe'), 'Buchstabiertafel' => $modulUrl('buchstabiertafel')],
            'active' => ['aufnahme' => 'Aufnahme', 'abgabe' => 'Abgabe', 'buchstabiertafel' => 'Buchstabiertafel'][$modul],
        ])

        @if ($modul === 'buchstabiertafel')
            <x-funk-info>
                Internationale Buchstabiertafel: In der UBI-Prüfung buchstabierst du Schiffsnamen, Ortsnamen und schwierige Wörter
                damit, Zahlen Ziffer für Ziffer („zwo“ statt „zwei“). Karte umdrehen, dann ehrlich einschätzen: Wusstest du das
                Wort? {{ $streakToLearn }}&times; in Folge gewusst &rarr; gilt als gelernt und fliegt aus dem Quiz.
            </x-funk-info>
            @include('funk._vocab', [
                'tabUrl' => fn (array $query) => route('funk.oral.index', array_merge(['course' => $course, 'teil' => 'UBI', 'modul' => 'buchstabiertafel'], $query)),
                'answerUrl' => route('funk.oral.vocab', ['course' => $course, 'catalog' => 'UBI']),
                'unit' => 'Buchstaben',
                'quizLabel' => 'Quiz: Buchstabe → Wort',
            ])
            @if ($ansicht === 'uebersicht')
                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Ziffern</h3>
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
                    @foreach (\App\Support\FunkTraining::DIGITS as $digit => $word)
                        <button type="button" x-data @click="funkSpeak(@js($word), 'de-DE')" class="flex items-center gap-3 rounded-xl bg-white dark:bg-slate-800 shadow-sm px-4 py-2.5 text-left hover:shadow-md transition">
                            <span class="w-9 h-9 rounded-lg flex items-center justify-center font-bold text-white shrink-0" style="background-color: var(--brand-primary, #005FD7)">{{ $digit }}</span>
                            <span class="text-sm font-medium text-slate-800 dark:text-white">{{ $word }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
        @else
            <x-funk-info>
                @if ($modul === 'aufnahme')
                    Bei der Textaufnahme liest der Prüfer einen Funktext vor, den du wortgenau mitschreibst. Hör dir hier die
                    Übungstexte an, schreib mit und vergleiche danach mit der Lösung.
                @else
                    Bei der Textabgabe liest du dem Prüfer einen vorgelegten Funktext laut vor und buchstabierst die markierten
                    Wörter mit der internationalen Buchstabiertafel. Hier sind die zu buchstabierenden Wörter <strong>hervorgehoben</strong>.
                @endif
                {{ count($completed) }} von {{ count($texts) }} erledigt.
            </x-funk-info>
            <div class="grid grid-cols-3 sm:grid-cols-5 md:grid-cols-7 lg:grid-cols-9 gap-3">
                @foreach ($texts as $text)
                    @php $done = in_array($text['key'], $completed, true); @endphp
                    <a href="{{ route('funk.oral.ubi', ['course' => $course, 'modul' => $modul, 'number' => $text['number']]) }}"
                       class="relative aspect-square rounded-2xl flex items-center justify-center text-lg font-semibold shadow-sm hover:shadow-md transition {{ $done ? 'text-white' : 'bg-white dark:bg-slate-800 text-slate-800 dark:text-white' }}"
                       @if ($done) style="background: linear-gradient(150deg, var(--brand-secondary, #007A7A), color-mix(in srgb, var(--brand-secondary, #007A7A) 60%, #001233));" @endif>
                        {{ $text['number'] }}
                        @if ($done)
                            <x-icon name="check-circle" class="w-4 h-4 absolute top-2 right-2 text-white/80" />
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    @include('funk._speech')
</x-app-layout>

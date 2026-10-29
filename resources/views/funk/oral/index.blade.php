<x-app-layout>
    <x-slot name="header">
        @include('funk._header', ['title' => 'Mündliche Prüfung', 'icon' => 'microphone'])
    </x-slot>

    @php
        $tabUrl = fn (array $query) => route('funk.oral.index', array_merge(['course' => $course, 'teil' => $catalog, 'modul' => $modul], $query));
    @endphp

    <div class="space-y-4">
        @include('funk._tabs', [
            'tabs' => collect($catalogs)->mapWithKeys(fn ($c) => [$c => route('funk.oral.index', ['course' => $course, 'teil' => $c])])->all(),
            'active' => $catalog,
        ])

            @include('funk._tabs', [
                'tabs' => ['Seefunktexte' => $tabUrl(['modul' => 'texte']), 'Vokabeln' => $tabUrl(['modul' => 'vokabeln'])],
                'active' => $modul === 'texte' ? 'Seefunktexte' : 'Vokabeln',
            ])

            @if ($modul === 'texte')
                @if (count($texts) > 0)
                    <x-funk-info>
                        In der Prüfung liest der Prüfer zwei Seefunktexte vor: einen englischen, den du ins Deutsche übersetzt, und
                        einen deutschen, den du ins Englische übersetzt. Diese {{ count($texts) }} Übungstexte sind wie die amtlichen
                        aufgebaut &ndash; Seenot, Dringlichkeit, Sicherheit &ndash; und nutzen dieselben Formulierungen. Übe alle:
                        anhören, mitschreiben, übersetzen. {{ count($textsCompleted) }} von {{ count($texts) }} erledigt.
                    </x-funk-info>
                    <div class="grid grid-cols-3 sm:grid-cols-5 md:grid-cols-7 lg:grid-cols-9 gap-3">
                        @foreach ($texts as $text)
                            @php $done = in_array($text['key'], $textsCompleted, true); @endphp
                            <a href="{{ route('funk.oral.text', ['course' => $course, 'catalog' => $catalog, 'number' => $text['number']]) }}"
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
            @else
                <x-funk-info>
                    Vokabeltrainer für den englischen Teil der SRC-Prüfung (Deutsch &rarr; Englisch). Karte umdrehen, dann ehrlich
                    einschätzen: Wusstest du sie? {{ $streakToLearn }}&times; in Folge gewusst &rarr; gilt als gelernt und fliegt aus dem Quiz.
                </x-funk-info>

                @include('funk._vocab', [
                    'answerUrl' => route('funk.oral.vocab', ['course' => $course, 'catalog' => $catalog]),
                    'unit' => 'Vokabeln',
                    'quizLabel' => 'Quiz',
                ])
            @endif
    </div>

    @include('funk._speech')
</x-app-layout>

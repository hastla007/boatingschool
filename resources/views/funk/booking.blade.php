<x-app-layout>
    <x-slot name="header">
        @include('funk._header', ['title' => 'Prüfung buchen', 'icon' => 'calendar-days'])
    </x-slot>

    @php
        $theories = array_values(array_filter([
            ['label' => 'Vollprüfung', 'data' => $info['theory']],
            isset($info['addon_theory']) ? ['label' => 'Ergänzungsprüfung für SRC-Inhaber', 'data' => $info['addon_theory']] : null,
        ]));
        if ($addonFirst) {
            $theories = array_reverse($theories);
        }
        $parts = [
            ['key' => 'theory', 'title' => 'Theorie', 'icon' => 'document-text', 'link' => route('exam.intro', $course), 'linkLabel' => 'Zur Prüfungssimulation'],
            ['key' => 'oral', 'title' => $catalog === 'SRC' ? 'Englisch' : 'Textaufnahme und Textabgabe', 'icon' => 'microphone', 'link' => route('funk.oral.index', ['course' => $course, 'teil' => $catalog]), 'linkLabel' => 'Zur mündlichen Prüfung'],
            ['key' => 'praxis', 'title' => 'Praxis am Funkgerät', 'icon' => 'signal', 'link' => route('funk.praxis.index', ['course' => $course, 'teil' => $catalog]), 'linkLabel' => 'Zur Praxis-Prüfungssimulation'],
        ];
        $card = 'bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5';
        $heading = 'font-semibold text-slate-800 dark:text-white flex items-center gap-2 mb-3';
    @endphp

    <div class="space-y-4">
        @include('funk._tabs', [
            'tabs' => collect($catalogs)->mapWithKeys(fn ($c) => [$c => route('funk.booking', ['course' => $course, 'teil' => $c])])->all(),
            'active' => $catalog,
        ])

        <div>
            <h2 class="text-2xl font-semibold text-slate-800 dark:text-white">{{ $info['title'] }}</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400 max-w-3xl">{{ $info['intro'] }}</p>
        </div>

        @if ($addonFirst)
            <x-funk-info>
                Du hast schon das SRC: Für dich gilt die verkürzte Ergänzungsprüfung mit {{ $info['addon_theory']['questions'] }} Fragen
                und einem kürzeren praktischen Teil.
            </x-funk-info>
        @endif

        <section class="{{ $card }}">
            <h3 class="{{ $heading }}"><x-icon name="calendar-days" class="w-5 h-5" style="color: var(--brand-secondary, #007A7A)" /> Termin buchen</h3>
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
                Die {{ $catalog }}-Prüfung nimmt ein Prüfungsausschuss des Deutschen Segler-Verbands (DSV) oder des Deutschen
                Motoryachtverbands (DMYV) ab. Such dir einen Ausschuss in deiner Nähe aus.
            </p>
            <div class="grid md:grid-cols-2 gap-3">
                @foreach ($info['providers'] as $provider)
                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-4 flex flex-col">
                        <div class="font-semibold text-slate-800 dark:text-white">{{ $provider['name'] }}</div>
                        <div class="text-xs text-slate-500 dark:text-slate-400">{{ $provider['full'] }}</div>
                        <p class="text-sm text-slate-600 dark:text-slate-300 mt-2 flex-1">{{ $provider['text'] }}</p>
                        <a href="{{ $provider['url'] }}" target="_blank" rel="noopener noreferrer"
                           class="mt-3 self-start inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-semibold text-white"
                           style="background-color: var(--brand-secondary, #007A7A)">
                            Zum {{ $provider['name'] }} <x-icon name="arrow-top-right-on-square" class="w-4 h-4" />
                        </a>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="{{ $card }}">
            <h3 class="{{ $heading }}"><x-icon name="list-bullet" class="w-5 h-5" style="color: var(--brand-secondary, #007A7A)" /> So meldest du dich an</h3>
            <ol class="space-y-4">
                @foreach ([
                    ['Antrag stellen', $info['apply'], null],
                    ['Unterlagen zusammenstellen', 'Leg diese Unterlagen bereit:', $info['documents']],
                    ['Spätestens 14 Tage vorher', 'Gebühr überweisen und Unterlagen einreichen: Beides muss spätestens 14 Tage vor dem Prüfungstermin beim Prüfungsausschuss vorliegen.', null],
                    ['Am Prüfungstag', 'Bring deinen Personalausweis oder Reisepass mit.', null],
                ] as $index => [$title, $text, $list])
                    <li class="flex gap-3">
                        <span class="w-7 h-7 rounded-full flex items-center justify-center text-sm font-semibold text-white shrink-0" style="background-color: var(--brand-secondary, #007A7A)">{{ $index + 1 }}</span>
                        <div class="min-w-0">
                            <div class="font-medium text-slate-800 dark:text-white">{{ $title }}</div>
                            <p class="text-sm text-slate-600 dark:text-slate-400">{{ $text }}</p>
                            @if ($list)
                                <ul class="mt-1 list-disc pl-5 text-sm text-slate-700 dark:text-slate-300 space-y-0.5">
                                    @foreach ($list as $item)
                                        <li>{{ $item }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>

        <div class="grid sm:grid-cols-3 gap-3">
            @foreach ([
                ['banknotes', 'Prüfungsgebühr', 'ca. '.$info['fee'].' €'],
                ['clock', 'Anmeldung', 'mind. 14 Tage vorher'],
                ['users', 'Mindestalter', '15 Jahre'],
            ] as [$icon, $label, $value])
                <div class="{{ $card }} flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center shrink-0 bg-slate-100 dark:bg-slate-700">
                        <x-icon :name="$icon" class="w-5 h-5" style="color: var(--brand-secondary, #007A7A)" />
                    </div>
                    <div>
                        <div class="text-xs text-slate-500 dark:text-slate-400">{{ $label }}</div>
                        <div class="font-semibold text-slate-800 dark:text-white">{{ $value }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        <section class="{{ $card }}">
            <h3 class="{{ $heading }}"><x-icon name="academic-cap" class="w-5 h-5" style="color: var(--brand-secondary, #007A7A)" /> Was dich am Prüfungstag erwartet</h3>
            <div class="space-y-3">
                @foreach ($parts as $part)
                    <div class="rounded-xl bg-slate-50 dark:bg-slate-900/40 border-l-4 p-4" style="border-left-color: var(--brand-secondary, #007A7A)">
                        <div class="font-semibold text-slate-800 dark:text-white flex items-center gap-2">
                            <x-icon :name="$part['icon']" class="w-5 h-5 text-slate-500 dark:text-slate-400" /> {{ $part['title'] }}
                        </div>
                        <p class="text-sm text-slate-600 dark:text-slate-300 mt-1">{{ $info['parts'][$part['key']] }}</p>
                        @if ($part['key'] === 'theory')
                            @foreach ($theories as $theory)
                                <div class="mt-2 text-sm text-slate-700 dark:text-slate-300 flex flex-wrap gap-x-5 gap-y-1">
                                    @if (count($theories) > 1)
                                        <span class="font-medium w-full">{{ $theory['label'] }}</span>
                                    @endif
                                    <span>Umfang: <strong>{{ $theory['data']['questions'] }} Fragen aus {{ $theory['data']['catalog'] }}</strong></span>
                                    <span>Bestanden: <strong>ab {{ $theory['data']['min_correct'] }} richtigen (max. {{ $theory['data']['questions'] - $theory['data']['min_correct'] }} Fehler)</strong></span>
                                    <span>Dauer: <strong>{{ $theory['data']['minutes'] }} Minuten</strong></span>
                                </div>
                            @endforeach
                        @endif
                        <a href="{{ $part['link'] }}" class="mt-2 inline-flex items-center gap-1 text-sm font-medium" style="color: var(--brand-secondary, #007A7A)">
                            {{ $part['linkLabel'] }} <x-icon name="arrow-right" class="w-3.5 h-3.5" />
                        </a>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="{{ $card }}">
            <h3 class="{{ $heading }}"><x-icon name="question-mark-circle" class="w-5 h-5" style="color: var(--brand-secondary, #007A7A)" /> Häufige Fragen</h3>
            <div class="divide-y divide-slate-100 dark:divide-slate-700">
                @foreach ($info['faq'] as $question => $answer)
                    <details class="group py-3">
                        <summary class="flex items-center justify-between gap-3 cursor-pointer list-none font-medium text-slate-800 dark:text-white">
                            {{ $question }}
                            <x-icon name="chevron-right" class="w-4 h-4 text-slate-500 dark:text-slate-400 shrink-0 transition group-open:rotate-90" />
                        </summary>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $answer }}</p>
                    </details>
                @endforeach
            </div>
        </section>

        <p class="text-xs text-slate-500 dark:text-slate-400">
            Gebühren und Fristen können sich ändern und je Prüfungsausschuss leicht abweichen – maßgeblich sind die Angaben deines Prüfungsausschusses.
        </p>
    </div>
</x-app-layout>

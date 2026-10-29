<x-app-layout>
    <x-slot name="header">
        @include('funk._header', ['title' => 'Prüfung buchen', 'icon' => 'calendar-days'])
    </x-slot>

    @php
        $focus = $variants[0];
        $linkLabels = [
            'exam' => 'Zur Prüfungssimulation',
            'navigation' => 'Zu den Navigationsaufgaben',
            'funk-oral' => 'Zur mündlichen Prüfung',
            'funk-praxis' => 'Zur Praxis-Prüfungssimulation',
        ];
        $stats = array_values(array_filter([
            ['banknotes', 'Prüfungsgebühr', 'ca. '.$info['fee']],
            $info['lead_time'] ? ['clock', 'Anmeldung', $info['lead_time']] : null,
            ['users', 'Mindestalter', $info['min_age']],
        ]));
        $card = 'bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5';
        $heading = 'font-semibold text-slate-800 dark:text-white flex items-center gap-2 mb-3';
    @endphp

    <div class="space-y-4">
        @include('funk._tabs', ['tabs' => $tabs, 'active' => $activeTab])

        <div>
            <h2 class="text-2xl font-semibold text-slate-800 dark:text-white">{{ $info['title'] }}</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400 max-w-3xl">{{ $info['intro'] }}</p>
        </div>

        @isset($focus['note'])
            <x-funk-info>{{ $focus['note'] }}</x-funk-info>
        @endisset

        <section class="{{ $card }}">
            <h3 class="{{ $heading }}"><x-icon name="calendar-days" class="w-5 h-5" style="color: var(--brand-secondary, #007A7A)" /> Termin buchen</h3>
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
                Die Prüfung nimmt ein Prüfungsausschuss des Deutschen Segler-Verbands (DSV) oder des Deutschen
                Motoryachtverbands (DMYV) ab. Such dir einen Ausschuss in deiner Nähe aus.
            </p>
            <div class="grid md:grid-cols-2 gap-3">
                @foreach ($providers as $provider)
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
                    ['Rechtzeitig einreichen', $info['deadline'], null],
                    ['Am Prüfungstag', 'Bring deinen Personalausweis oder Reisepass mit, dazu die Originale der eingereichten Nachweise.', null],
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

        <div class="grid gap-3 {{ count($stats) === 3 ? 'sm:grid-cols-3' : 'sm:grid-cols-2' }}">
            @foreach ($stats as [$icon, $label, $value])
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
                @foreach ($examParts as $part)
                    <div class="rounded-xl bg-slate-50 dark:bg-slate-900/40 border-l-4 p-4" style="border-left-color: var(--brand-secondary, #007A7A)">
                        <div class="font-semibold text-slate-800 dark:text-white flex items-center gap-2">
                            <x-icon :name="$part['icon']" class="w-5 h-5 text-slate-500 dark:text-slate-400" /> {{ $part['title'] }}
                        </div>
                        <p class="text-sm text-slate-600 dark:text-slate-300 mt-1">{{ $part['text'] }}</p>
                        @if ($part['key'] === 'theory')
                            @foreach ($variants as $index => $variant)
                                @if ($index === 1)
                                    <details class="group mt-3">
                                        <summary class="cursor-pointer list-none text-sm font-medium text-slate-700 dark:text-slate-200 flex items-center gap-1">
                                            <x-icon name="chevron-right" class="w-4 h-4 text-slate-500 dark:text-slate-400 transition group-open:rotate-90" />
                                            Weitere Prüfungsformen
                                        </summary>
                                @endif
                                <div class="mt-2 text-sm text-slate-700 dark:text-slate-300 flex flex-wrap gap-x-5 gap-y-1">
                                    @if (count($variants) > 1)
                                        <span class="font-medium w-full">{{ $variant['label'] }}</span>
                                    @endif
                                    <span>Umfang: <strong>{{ $variant['scope'] }}</strong></span>
                                    @if ($variant['pass'])
                                        <span>Bestanden: <strong>{{ $variant['pass'] }}</strong></span>
                                    @endif
                                    @if ($variant['minutes'])
                                        <span>Dauer: <strong>{{ $variant['minutes'] }} Minuten</strong></span>
                                    @endif
                                    @isset($variant['extra'])
                                        <span class="w-full">{{ $variant['extra'] }}</span>
                                    @endisset
                                </div>
                                @if ($index > 0 && $loop->last)
                                    </details>
                                @endif
                            @endforeach
                        @endif
                        @if ($part['url'])
                            <a href="{{ $part['url'] }}" class="mt-2 inline-flex items-center gap-1 text-sm font-medium" style="color: var(--brand-secondary, #007A7A)">
                                {{ $linkLabels[$part['link']] }} <x-icon name="arrow-right" class="w-3.5 h-3.5" />
                            </a>
                        @endif
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
            Gebühren, Fristen und Abläufe können sich ändern und je Prüfungsausschuss abweichen – maßgeblich sind die Angaben deines Prüfungsausschusses.
        </p>
    </div>
</x-app-layout>

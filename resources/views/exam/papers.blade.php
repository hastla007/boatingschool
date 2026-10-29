<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            {{-- Bug-Review (Zurück-Pfeile ohne Beschriftung): nur ein Icon ohne
                 Text -- ohne aria-label hatte dieser Link keinen für
                 Screenreader erkennbaren Namen. --}}
            <a href="{{ route('courses.show', $course) }}" aria-label="Zurück zum Kurs" class="w-8 h-8 rounded-full flex items-center justify-center text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700 shrink-0">
                <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
            </a>
            <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200 flex items-center gap-2">
                <x-icon name="clipboard-document-check" class="w-5 h-5 text-slate-600 dark:text-slate-400" /> Prüfungssimulation
            </h1>
            <div class="flex-1 flex items-center gap-2 max-w-xs ml-4">
                <div class="flex-1 bg-slate-100 dark:bg-slate-700 rounded-full h-1.5">
                    <div class="h-1.5 rounded-full" style="width: {{ $overallPercent }}%; background-color: var(--brand-secondary, #007A7A)"></div>
                </div>
                <span class="text-xs text-slate-500 dark:text-slate-400 shrink-0">{{ $overallPercent }}%</span>
            </div>
        </div>
    </x-slot>

    <div class="space-y-4">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
            <h3 class="font-medium text-slate-800 dark:text-white mb-2">Herzlich willkommen zu den Prüfungsbögen!</h3>
            @php
                $addonCopy = [
                    'ADD-SEE-TO-BIN' => ['kurs' => 'Ergänzung See → Binnen', 'bereich' => 'Binnen', 'vorhanden' => 'SBF See', 'profil' => 'binnen-motor-with-see'],
                    'ADD-BIN-TO-SEE' => ['kurs' => 'Ergänzung Binnen → See', 'bereich' => 'See', 'vorhanden' => 'SBF Binnen', 'profil' => 'see-with-binnen'],
                ];
            @endphp
            @php
                $fullCourseCopy = [
                    'SBF-SEE' => ['bereich' => 'See'],
                    'SBF-BIN-MOTOR' => ['bereich' => 'Binnen'],
                ];
            @endphp
            @if (isset($addonCopy[$course->code]))
                @php $copy = $addonCopy[$course->code]; @endphp
                <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                    Hier findest Du alle {{ $paperStats->count() }} offiziellen Ergänzungsbögen für den Kurs „{{ $copy['kurs'] }}&quot;. Die
                    Bögen haben immer die gleiche Zusammensetzung, sodass Du Dich gezielt und effizient vorbereiten kannst.
                    Jeder Prüfungsbogen enthält 23 Multiple-Choice-Fragen aus dem Bereich {{ $copy['bereich'] }} &mdash; die 7 allgemeinen
                    Basisfragen entfallen, da diese mit Deinem vorhandenen {{ $copy['vorhanden'] }} bereits geprüft wurden.
                </p>
                <p class="text-xs text-slate-600 dark:text-slate-400 mt-3">
                    Hinweis: Die Zuordnung der Fragen zu den einzelnen Bögen entspricht den amtlichen Ergänzungsbögen
                    (Quelle: seeundbinnen.de, Profil „{{ $copy['profil'] }}&quot;).
                </p>
            @elseif ($course->code === 'SBF-SEE-BIN')
                <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                    Hier findest Du alle 30 offiziellen Prüfungsbögen &mdash; 15 für SBF See mit 30 Multiple-Choice-Fragen
                    (7 allgemeine Basisfragen und 23 Fragen See) und 15 für SBF Binnen mit den 23 Fragen Binnen. Legst Du beide
                    Prüfungen am selben Tag ab, werden die Basisfragen nur einmal geprüft. Die Bögen haben immer die gleiche
                    Zusammensetzung, sodass Du Dich gezielt und effizient auf beide Prüfungen vorbereiten kannst.
                </p>
                <p class="text-xs text-slate-600 dark:text-slate-400 mt-3">
                    Hinweis: Die Zuordnung der Fragen zu den einzelnen Bögen entspricht den amtlichen Prüfungsbögen
                    (Quelle: seeundbinnen.de).
                </p>
            @elseif ($course->code === 'SRC-UBI')
                <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                    Hier findest Du die offiziellen Prüfungsbögen für <strong>SRC und UBI</strong>, getrennt nach Funkschein,
                    damit Du gezielt üben kannst. Legst Du beide Scheine in einem Prüfungsdurchlauf ab, sind für Dich die
                    Bögen <strong>SRC</strong> und <strong>UBI-Ergänzung</strong> relevant &mdash; der UBI-Ergänzungsbogen
                    ersetzt dann den vollständigen UBI-Bogen.
                </p>
                <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed mt-2">
                    Die Fragen kommen genau so in der Prüfung dran, und die Bögen haben immer die gleiche Zusammensetzung.
                </p>
            @elseif (isset($fullCourseCopy[$course->code]))
                @php $copy = $fullCourseCopy[$course->code]; @endphp
                <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                    Hier findest Du alle {{ $paperStats->count() }} offiziellen Prüfungsbögen für diesen Kurs. Die
                    Bögen haben immer die gleiche Zusammensetzung, sodass Du Dich gezielt und effizient vorbereiten kannst.
                    Jeder Prüfungsbogen enthält 30 Multiple-Choice-Fragen &mdash; 7 allgemeine Basisfragen und 23 spezifische
                    Fragen aus dem Bereich {{ $copy['bereich'] }}.
                    @if ($hasNavigationTasks)
                        Außerdem kommt in der Prüfung eine Navigationsaufgabe vor. Diese findest Du im Bereich
                        <a href="{{ route('exam.navigation.index', $course) }}" class="inline-flex items-center px-2 py-0.5 rounded-md text-white text-xs font-medium" style="background-color: var(--brand-primary, #005FD7)">Navigation Prüfungssimulation</a>.
                    @endif
                </p>
                <p class="text-xs text-slate-600 dark:text-slate-400 mt-3">
                    Hinweis: Die Zuordnung der Fragen zu den einzelnen Bögen entspricht den amtlichen Prüfungsbögen
                    (Quelle: seeundbinnen.de).
                </p>
            @elseif ($course->code === 'SBF-BIN-SEGEL')
                <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                    Hier findest Du alle {{ $paperStats->count() }} offiziellen Prüfungsbögen für die Kombiprüfung SBF Binnen unter Segel. Die
                    Bögen haben immer die gleiche Zusammensetzung, sodass Du Dich gezielt und effizient vorbereiten kannst.
                    Jeder Prüfungsbogen enthält 37 Multiple-Choice-Fragen &mdash; 7 allgemeine Basisfragen, 23 spezifische Fragen
                    Binnen und 7 spezifische Segelfragen.
                </p>
                <p class="text-xs text-slate-600 dark:text-slate-400 mt-3">
                    Hinweis: Die Zuordnung der Fragen zu den einzelnen Bögen entspricht den amtlichen Prüfungsbögen
                    (Quelle: seeundbinnen.de).
                </p>
            @elseif ($course->code === 'SRC')
                <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                    Hier findest Du alle {{ $paperStats->count() }} offiziellen Prüfungsbögen für den SRC Vollkurs. Die Bögen haben immer
                    die gleiche Zusammensetzung, sodass Du Dich gezielt und effizient vorbereiten kannst. Jeder Prüfungsbogen enthält 24
                    Multiple-Choice-Fragen, Bearbeitungszeit 30 Minuten, Bestehensgrenze 19 von 24 Fragen (max. 5 Fehler).
                </p>
                <p class="text-xs text-slate-600 dark:text-slate-400 mt-3">
                    Hinweis: Zur SRC-Prüfung gehören zusätzlich ein englisches Diktat und eine schriftliche Übersetzung, die nicht Teil
                    dieser Online-Simulation sind. Quelle der Prüfungsbögen: Fachstelle der WSV für Verkehrstechniken, Koblenz.
                </p>
            @elseif ($course->code === 'FKN')
                <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                    Hier findest Du alle {{ $paperStats->count() }} offiziellen Prüfungsbögen für den FKN Vollkurs (Fachkundenachweis für
                    Seenotsignalmittel). Jeder Prüfungsbogen enthält 15 Fragen &mdash; teils Mehrfachauswahl-Fragen, teils offene Fragen ohne
                    Antwortoptionen (als Lernkarte mit Musterantwort). Bearbeitungszeit 30 Minuten, Bestehensgrenze 24 von 30 Punkten
                    (amtlich mit Teilpunkten bewertet; hier vereinfacht als 12 von 15 Fragen richtig, da die offenen Fragen als
                    Lernkarten nicht automatisiert bewertet werden).
                </p>
                <p class="text-xs text-slate-600 dark:text-slate-400 mt-3">
                    Hinweis: Die Zuordnung der Fragen zu den einzelnen Bögen entspricht dem amtlichen FKN-Fragenkatalog (Quelle: PANord).
                </p>
            @elseif ($course->code === 'ADD-BIN-SEGEL')
                <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                    Hier findest Du alle {{ $paperStats->count() }} offiziellen Prüfungsbögen für die Ergänzungsprüfung „Binnen &rarr; Segeln&quot;. Da Du
                    Deinen SBF Binnen bereits besitzt, entfallen der allgemeine Teil und die Basisfragen &mdash; jeder Bogen enthält nur noch
                    7 spezifische Segelfragen. Du musst mindestens 5 der 7 Fragen richtig beantworten, die Bearbeitungszeit beträgt 15 Minuten.
                </p>
                <p class="text-xs text-slate-600 dark:text-slate-400 mt-3">
                    Hinweis: Die Zuordnung der Fragen zu den einzelnen Bögen entspricht den amtlichen Prüfungsbögen
                    (Quelle: seeundbinnen.de).
                </p>
            @endif
        </div>

        <a href="{{ route('favorites.exam', $course) }}" class="flex items-center gap-4 rounded-2xl p-5 text-white hover:opacity-95 transition"
           style="background: linear-gradient(120deg, var(--brand-secondary, #007A7A), color-mix(in srgb, var(--brand-secondary, #007A7A) 55%, #001233));">
            <div class="w-12 h-12 rounded-full bg-white/15 flex items-center justify-center shrink-0">
                <x-icon name="star" class="w-6 h-6" />
            </div>
            <div class="flex-1">
                <div class="font-semibold">Gespeicherte Fragen</div>
                <div class="text-sm text-white/80">{{ $favoritesMastered }}/{{ $favoritesTotal }} gemeistert</div>
            </div>
            <x-icon name="arrow-right" class="w-4 h-4 text-white/70 shrink-0" />
        </a>

        @php
            $groupedStats = $paperStats->groupBy('group');
            $lastGroupLabel = $groupedStats->keys()->last();
            $waLink = (isset($branding) && auth()->check())
                ? \App\Support\WhatsAppLink::for($branding, auth()->user(), $course)
                : null;
        @endphp
        @unless ($isVerified)
            <div class="rounded-xl bg-amber-50 border border-amber-200 text-amber-700 dark:bg-amber-900/30 dark:border-amber-800 dark:text-amber-300 px-4 py-3 text-sm flex items-start gap-2">
                <x-icon name="lock-closed" class="w-5 h-5 shrink-0 mt-0.5" />
                <span>Für diesen Kurs sind die Prüfungsbögen vorbereitet, aber das Regelwerk (Fragenmix, Zeitlimit, Bestehensgrenze)
                ist fachlich noch nicht freigegeben. Die Prüfungsbögen starten erst nach Verifizierung.</span>
            </div>
        @endunless
        @foreach ($groupedStats as $groupLabel => $statsInGroup)
            <div>
                {{-- In Kombikursen wird jede Bogengruppe nach dem Regelwerk ihres Teilkurses bewertet. --}}
                @php($groupRuleSet = $statsInGroup->first()['ruleSet'])
                <h4 class="font-medium text-slate-700 dark:text-slate-200 {{ $groupRuleSet ? 'mb-1' : 'mb-3' }}">{{ $groupLabel ?: $course->name }}</h4>
                @if ($groupRuleSet)
                    <p class="text-xs text-slate-600 dark:text-slate-400 mb-3">
                        {{ $groupRuleSet->passingSummary($statsInGroup->first()['total']) }}
                        @if ($groupRuleSet->note())
                            <span class="block mt-0.5">{{ $groupRuleSet->note() }}</span>
                        @endif
                    </p>
                @endif
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                    @foreach ($statsInGroup as $stat)
                        @if ($stat['startable'])
                            <form method="POST" action="{{ route('exam.papers.start', ['course' => $course, 'paper' => $stat['paper']]) }}">
                                @csrf
                                <button type="submit" class="w-full rounded-2xl p-4 text-white text-left hover:opacity-95 transition"
                                        style="background: linear-gradient(150deg, #0B3D64, #071E33);">
                                    <div class="flex items-center justify-between mb-1">
                                        <x-progress-ring :percent="$stat['percent']" :size="52" :stroke="5" class="[&_circle:first-child]:stroke-white/20 [&_circle:last-child]:stroke-white [&_span]:text-white" />
                                        @if ($stat['attempted'])
                                            <x-icon name="clock" class="w-4 h-4 text-white/40" />
                                        @endif
                                    </div>
                                    <div class="font-semibold">Bogen {{ $stat['displayNumber'] }}</div>
                                    <div class="text-xs text-white/70">{{ $stat['correct'] }}/{{ $stat['total'] }}</div>
                                </button>
                            </form>
                        @else
                            <div class="w-full rounded-2xl p-4 text-left opacity-50" style="background: linear-gradient(150deg, #0B3D64, #071E33);">
                                <div class="flex items-center justify-between mb-1">
                                    <x-progress-ring :percent="$stat['percent']" :size="52" :stroke="5" class="[&_circle:first-child]:stroke-white/20 [&_circle:last-child]:stroke-white [&_span]:text-white" />
                                    <x-icon name="lock-closed" class="w-4 h-4 text-white/40" />
                                </div>
                                <div class="font-semibold text-white">Bogen {{ $stat['displayNumber'] }}</div>
                                <div class="text-xs text-white/70">{{ $stat['correct'] }}/{{ $stat['total'] }}</div>
                            </div>
                        @endif
                    @endforeach

                    @if ($waLink && $groupLabel === $lastGroupLabel)
                        <div class="flex items-center justify-center">
                            <a href="{{ $waLink }}" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-2 pl-3 pr-4 py-3 rounded-full shadow-lg text-white text-sm font-medium hover:opacity-90 transition"
                               {{-- Bug-Review Oberfläche U6: siehe components/whatsapp-support-button.blade.php --}}
                               style="background-color: #075E54"
                               title="WhatsApp Support - {{ $currentTenant->name }}">
                                <x-whatsapp-icon class="w-6 h-6 shrink-0" />
                                <span class="hidden sm:inline truncate">{{ $currentTenant->name }}</span>
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</x-app-layout>

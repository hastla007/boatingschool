<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('courses.show', $course) }}" class="w-8 h-8 rounded-full flex items-center justify-center text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700 shrink-0">
                <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
            </a>
            <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200 flex items-center gap-2">
                <x-icon name="clipboard-document-check" class="w-5 h-5 text-slate-400" /> Prüfungssimulation
            </h2>
            <div class="flex-1 flex items-center gap-2 max-w-xs ml-4">
                <div class="flex-1 bg-slate-100 dark:bg-slate-700 rounded-full h-1.5">
                    <div class="h-1.5 rounded-full" style="width: {{ $overallPercent }}%; background-color: var(--brand-secondary, #00A8A8)"></div>
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
                <p class="text-xs text-slate-400 mt-3">
                    Hinweis: Die Zuordnung der Fragen zu den einzelnen Bögen entspricht den amtlichen Ergänzungsbögen
                    (Quelle: seeundbinnen.de, Profil „{{ $copy['profil'] }}&quot;).
                </p>
            @elseif ($course->code === 'SBF-SEE-BIN')
                <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                    Hier findest Du alle 30 offiziellen Prüfungsbögen &mdash; 15 für SBF See und 15 für SBF Binnen, jeweils mit
                    30 Multiple-Choice-Fragen (7 allgemeine Basisfragen und 23 kursspezifische Fragen). Die Bögen haben immer
                    die gleiche Zusammensetzung, sodass Du Dich gezielt und effizient auf beide Prüfungen vorbereiten kannst.
                </p>
                <p class="text-xs text-slate-400 mt-3">
                    Hinweis: Die Zuordnung der Fragen zu den einzelnen Bögen entspricht den amtlichen Prüfungsbögen
                    (Quelle: seeundbinnen.de).
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
                <p class="text-xs text-slate-400 mt-3">
                    Hinweis: Die Zuordnung der Fragen zu den einzelnen Bögen entspricht den amtlichen Prüfungsbögen
                    (Quelle: seeundbinnen.de).
                </p>
            @endif
        </div>

        <a href="{{ route('favorites.exam', $course) }}" class="flex items-center gap-4 rounded-2xl p-5 text-white hover:opacity-95 transition"
           style="background: linear-gradient(120deg, var(--brand-secondary, #00A8A8), color-mix(in srgb, var(--brand-secondary, #00A8A8) 55%, #001233));">
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
        @foreach ($groupedStats as $groupLabel => $statsInGroup)
            <div>
                <h4 class="font-medium text-slate-700 dark:text-slate-200 mb-3">{{ $groupLabel ?: $course->name }}</h4>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                    @foreach ($statsInGroup as $stat)
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
                    @endforeach

                    @if ($waLink && $groupLabel === $lastGroupLabel)
                        <div class="flex items-center justify-center">
                            <a href="{{ $waLink }}" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-2 pl-3 pr-4 py-3 rounded-full shadow-lg text-white text-sm font-medium hover:opacity-90 transition"
                               style="background-color: #25D366"
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

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
            <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                Hier findest Du alle {{ $paperStats->count() }} offiziellen Prüfungsbögen für den Sportbootführerschein See. Die
                Bögen haben immer die gleiche Zusammensetzung, sodass Du Dich gezielt und effizient vorbereiten kannst.
                Jeder Prüfungsbogen enthält 30 Multiple-Choice-Fragen &mdash; 7 allgemeine Basisfragen und 23 spezifische
                Fragen aus dem Bereich See.
                @if ($hasNavigationTasks)
                    Außerdem kommt in der Prüfung eine Navigationsaufgabe vor. Diese findest Du im Bereich
                    <a href="{{ route('exam.navigation.index', $course) }}" class="inline-flex items-center px-2 py-0.5 rounded-md text-white text-xs font-medium" style="background-color: var(--brand-primary, #005FD7)">Navigation Prüfungssimulation</a>.
                @endif
            </p>
            <p class="text-xs text-slate-400 mt-3">
                Hinweis: Die Zuordnung der Fragen zu den einzelnen Bögen ist eine Demo-Verteilung aus dem echten Fragenpool
                und ersetzt keine amtliche Bogen-Zusammenstellung &mdash; vor Produktivbetrieb durch das offizielle Material ersetzen.
            </p>
        </div>

        <a href="{{ route('favorites.index', $course) }}" class="flex items-center gap-4 rounded-2xl p-5 text-white hover:opacity-95 transition"
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

        <div>
            <h4 class="font-medium text-slate-700 dark:text-slate-200 mb-3">{{ $course->name }}</h4>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                @foreach ($paperStats as $stat)
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
                            <div class="font-semibold">Bogen {{ $stat['paper']->paper_number }}</div>
                            <div class="text-xs text-white/70">{{ $stat['correct'] }}/{{ $stat['total'] }}</div>
                        </button>
                    </form>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>

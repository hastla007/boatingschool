<x-app-layout>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-800 dark:text-white">Hallo {{ explode(' ', Auth::user()->name)[0] }}!</h1>
            <p class="text-slate-500 dark:text-slate-400">Schön, dass du wieder da bist.</p>
        </div>

        @if ($courses->isEmpty())
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 text-center">
                <x-icon name="life-buoy" class="w-8 h-8 mx-auto text-slate-600 dark:text-slate-400 mb-2" />
                <p class="text-slate-600 dark:text-slate-300">Für dich ist noch kein Kurs freigeschaltet.</p>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Bitte wende dich an deine Bootsschule, um Zugang zu erhalten.</p>
            </div>
        @else
            {{-- Jeder gebuchte Kurs bekommt seinen eigenen "Weiterlernen"-Banner
                 direkt unter dem Kurstitel sowie einen eigenen Fortschritts-Block
                 samt Fortschritt-/Favoriten-Kacheln, nicht nur der aktivste Kurs. --}}
            <div class="space-y-6">
                @foreach ($courses as $courseSummary)
                    <div>
                        <h2 class="font-semibold text-slate-700 dark:text-slate-200 mb-3">{{ $courseSummary['course']->name }}</h2>
                        {{-- Der Banner führt je nach Kursfortschritt zum nächsten sinnvollen
                             Schritt: erst Videokurs, dann Smarttrainer, ab hoher Mastery-Quote
                             die Prüfungssimulation (siehe DashboardController::$bannerMode). --}}
                        @php
                            $banner = match ($courseSummary['banner_mode']) {
                                'video' => [
                                    'href' => route('video.index', $courseSummary['course']),
                                    'icon' => 'play',
                                    'title' => 'Videokurs ansehen',
                                    'subtitle' => $courseSummary['course']->name.' · Starte mit den Lernvideos',
                                ],
                                'exam' => [
                                    'href' => route('exam.intro', $courseSummary['course']),
                                    'icon' => 'clipboard-document-check',
                                    'title' => 'Prüfungsaufgaben',
                                    'subtitle' => $courseSummary['course']->name.' · Teste dich in der Prüfungssimulation',
                                ],
                                default => [
                                    'href' => route('learning.show', $courseSummary['course']),
                                    'icon' => 'bolt',
                                    'title' => 'Weiterlernen',
                                    'subtitle' => $courseSummary['course']->name.' · Smarttrainer wählt die nächste Frage für dich',
                                ],
                            };
                        @endphp
                        <a href="{{ $banner['href'] }}"
                           class="flex items-center justify-between rounded-2xl p-5 text-white shadow-sm hover:opacity-95 transition mb-3"
                           style="background: linear-gradient(120deg, var(--brand-primary, #005FD7), color-mix(in srgb, var(--brand-primary, #005FD7) 65%, #001233));">
                            <div class="flex items-center gap-4">
                                <div class="w-11 h-11 rounded-xl bg-white/15 flex items-center justify-center shrink-0">
                                    <x-icon :name="$banner['icon']" class="w-6 h-6" />
                                </div>
                                <div>
                                    <div class="font-semibold text-lg">{{ $banner['title'] }}</div>
                                    {{-- Bug-Review N22: $banner['subtitle'] enthält den
                                         Kursnamen (course_definition wird nur vom
                                         Superadmin gepflegt, aktuell also kein
                                         erreichbarer XSS-Pfad) -- {{ }} statt {!! !!}
                                         escapt ihn trotzdem automatisch, als Verteidigung
                                         für den Fall, dass Kursnamen künftig aus weniger
                                         vertrauenswürdiger Quelle stammen. --}}
                                    <div class="text-white/80 text-sm">{{ $banner['subtitle'] }}</div>
                                </div>
                            </div>
                            <x-icon name="arrow-right" class="w-6 h-6 shrink-0" />
                        </a>
                        <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-stretch">
                            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-4">
                                <x-progress-ring :percent="$courseSummary['percent']" :size="72" :stroke="7" />
                                <div class="min-w-0">
                                    <div class="text-sm font-medium text-slate-700 dark:text-slate-200 truncate">{{ $courseSummary['course']->name }}</div>
                                    <div class="text-xs text-slate-600 dark:text-slate-400">Fortschritt gesamt</div>
                                </div>
                            </div>
                            <x-stat-tile icon="check-circle" :value="$courseSummary['correct']" label="richtig" tone="success" />
                            <x-stat-tile icon="x-circle" :value="$courseSummary['incorrect']" label="falsch" tone="danger" />
                            <x-stat-tile icon="chart-bar" :value="$courseSummary['attempts']" label="gesamt beantwortet" />
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">
                            <a href="{{ route('learning.show', $courseSummary['course']) }}"
                               class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-3 hover:shadow-md transition">
                                <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center shrink-0" style="color: var(--brand-primary, #005FD7)">
                                    <x-icon name="bolt" class="w-5 h-5" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-medium text-slate-700 dark:text-slate-200">Zum Kurs</div>
                                    <div class="text-xs text-slate-600 dark:text-slate-400 truncate">{{ $courseSummary['course']->name }}</div>
                                </div>
                                <x-icon name="chevron-right" class="w-4 h-4 text-slate-300 shrink-0" />
                            </a>
                            <a href="{{ route('progress.show', $courseSummary['course']) }}"
                               class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-3 hover:shadow-md transition">
                                <div class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-700 flex items-center justify-center shrink-0 text-slate-600 dark:text-slate-200">
                                    <x-icon name="chart-bar" class="w-5 h-5" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-medium text-slate-700 dark:text-slate-200">Fortschritt &amp; Statistiken</div>
                                    <div class="text-xs text-slate-600 dark:text-slate-400 truncate">{{ $courseSummary['course']->name }}</div>
                                </div>
                                <x-icon name="chevron-right" class="w-4 h-4 text-slate-300 shrink-0" />
                            </a>
                            <a href="{{ route('favorites.smart-learning', $courseSummary['course']) }}"
                               class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-3 hover:shadow-md transition">
                                <div class="w-10 h-10 rounded-xl bg-amber-50 dark:bg-amber-900/30 flex items-center justify-center shrink-0 text-amber-500">
                                    <x-icon name="star" class="w-5 h-5" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-medium text-slate-700 dark:text-slate-200">Favoriten &amp; Fehler</div>
                                    <div class="text-xs text-slate-600 dark:text-slate-400 truncate">{{ $courseSummary['course']->name }}</div>
                                </div>
                                <x-icon name="chevron-right" class="w-4 h-4 text-slate-300 shrink-0" />
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>

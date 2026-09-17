<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('courses.show', $course) }}" class="w-8 h-8 rounded-full flex items-center justify-center text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700 shrink-0">
                <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
            </a>
            <x-icon name="light-bulb" class="w-6 h-6 text-amber-400" />
            <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Smart-Learning</h2>
        </div>
    </x-slot>

    <div class="max-w-3xl mx-auto space-y-4">
        <div class="flex items-center gap-3">
            <div class="flex-1 bg-slate-100 dark:bg-slate-700 rounded-full h-2">
                <div class="h-2 rounded-full" style="width: {{ $overallPercent }}%; background-color: var(--brand-secondary, #00A8A8)"></div>
            </div>
            <span class="text-sm font-medium text-slate-600 dark:text-slate-300 shrink-0">{{ $overallPercent }}%</span>
        </div>

        <div class="flex items-start gap-2 bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-900/40 rounded-xl px-4 py-3 text-sm text-amber-800 dark:text-amber-200">
            <x-icon name="light-bulb" class="w-4 h-4 mt-0.5 shrink-0" />
            <span>Im Smart-Learning befinden sich alle prüfungsrelevanten Fragen. Dein Fortschritt erhöht sich, sobald du Fragen mehrfach richtig beantwortest.</span>
        </div>

        <a href="{{ route('learning.show', ['course' => $course, 'mode' => 'favorites']) }}"
           class="flex items-center gap-4 rounded-2xl p-4 text-white overflow-hidden bg-gradient-to-br from-teal-500 to-emerald-700 hover:opacity-95 transition">
            <x-progress-ring :percent="$favoritesTotal > 0 ? (int) round($favoritesMastered / $favoritesTotal * 100) : 0" :size="48" :stroke="5" class="shrink-0 [&_circle:first-child]:stroke-white/25 [&_span]:text-white" />
            <div class="flex-1 min-w-0">
                <div class="font-semibold">Gespeicherte Fragen</div>
                <div class="text-xs text-white/80">{{ $favoritesMastered }}/{{ $favoritesTotal }} gemeistert</div>
            </div>
            <x-icon name="bookmark" class="w-5 h-5 text-white/60 shrink-0" />
        </a>

        <div class="space-y-2">
            @foreach ($moduleGroups as $group)
                <div x-data="{ open: {{ $loop->first ? 'true' : 'false' }} }" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
                    <button type="button" x-on:click="open = !open"
                            class="w-full flex items-center gap-3 px-4 py-3.5 text-left hover:bg-slate-50 dark:hover:bg-slate-700/50 transition">
                        <span class="flex-1 font-medium text-slate-700 dark:text-slate-200">{{ $group['module']->name }}</span>
                        <span class="text-xs text-slate-400 shrink-0">{{ $group['mastered'] }}/{{ $group['total'] }} &middot; {{ $group['percent'] }}%</span>
                        <x-icon name="chevron-right" class="w-4 h-4 text-slate-400 shrink-0 transition-transform" x-bind:class="open ? 'rotate-90' : ''" />
                    </button>
                    <div x-show="open" x-transition class="px-4 pb-4 grid sm:grid-cols-2 gap-2">
                        @foreach ($group['topics'] as $topicEntry)
                            <a href="{{ route('learning.show', ['course' => $course, 'mode' => 'topic', 'module' => $group['module']->id, 'topic' => $topicEntry['topic']]) }}"
                               class="flex items-center gap-3 rounded-xl px-3 py-3 text-white transition hover:opacity-90"
                               style="background: linear-gradient(120deg, #0B2A4A, #14395E);">
                                <x-progress-ring :percent="$topicEntry['percent']" :size="40" :stroke="4" class="shrink-0 [&_circle:first-child]:stroke-white/25" />
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium truncate">{{ $topicEntry['topic'] }}</div>
                                    <div class="text-xs text-white/70">{{ $topicEntry['mastered'] }}/{{ $topicEntry['total'] }} Fragen</div>
                                </div>
                                <x-icon name="light-bulb" class="w-4 h-4 text-white/50 shrink-0" />
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach

            @if ($praxisCategories->isNotEmpty())
                <div x-data="{ open: false }" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
                    <button type="button" x-on:click="open = !open"
                            class="w-full flex items-center gap-3 px-4 py-3.5 text-left hover:bg-slate-50 dark:hover:bg-slate-700/50 transition">
                        <span class="flex-1 font-medium text-slate-700 dark:text-slate-200">Trainer für die praktische Prüfung SBF See</span>
                        <x-icon name="chevron-right" class="w-4 h-4 text-slate-400 shrink-0 transition-transform" x-bind:class="open ? 'rotate-90' : ''" />
                    </button>
                    <div x-show="open" x-transition class="px-4 pb-4 grid sm:grid-cols-2 gap-2">
                        @foreach ($praxisCategories as $entry)
                            <a href="{{ route('praxistrainer.index', ['course' => $course, 'kategorie' => $entry['kategorie']]) }}"
                               class="flex items-center gap-3 rounded-xl px-3 py-3 text-white transition hover:opacity-90"
                               style="background: linear-gradient(120deg, #0B2A4A, #14395E);">
                                <x-progress-ring :percent="$entry['percent']" :size="40" :stroke="4" class="shrink-0 [&_circle:first-child]:stroke-white/25" />
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium truncate">{{ $entry['kategorie'] }}</div>
                                    <div class="text-xs text-white/70">{{ $entry['mastered'] }}/{{ $entry['total'] }} Fragen</div>
                                </div>
                                <x-icon name="light-bulb" class="w-4 h-4 text-white/50 shrink-0" />
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>

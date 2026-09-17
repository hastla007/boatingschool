<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">{{ $course->name }}</h2>
    </x-slot>

    @php
        $tileColors = [
            'from-teal-400 to-teal-500',
            'from-teal-500 to-emerald-600',
            'from-emerald-600 to-teal-700',
            'from-sky-500 to-blue-600',
        ];
        $icons = ['book-open', 'compass', 'flag', 'life-buoy', 'shield-check', 'chart-bar'];
    @endphp

    <div class="grid lg:grid-cols-[1fr_320px] gap-4 mb-6">
        @if ($hasVideoCourse)
            <a href="{{ route('video.index', $course) }}" class="rounded-2xl p-6 text-white flex items-center gap-5 hover:opacity-95 transition"
               style="background: linear-gradient(120deg, var(--brand-primary, #005FD7), color-mix(in srgb, var(--brand-primary, #005FD7) 55%, #001233));">
                <x-progress-ring :percent="$videoPercent" :size="72" :stroke="6" class="shrink-0 [&_circle:first-child]:stroke-white/25" />
                <div>
                    <div class="font-semibold text-lg">{{ $course->name }}</div>
                    <div class="text-white/80 text-sm">Dein Videokurs für {{ $course->name }}</div>
                    <div class="text-white/90 text-sm mt-1 inline-flex items-center gap-1">
                        {{ $videoPercent >= 100 ? 'Abgeschlossen' : 'Weiterschauen' }} <x-icon name="arrow-right" class="w-3.5 h-3.5" />
                    </div>
                </div>
            </a>
        @else
            <div class="rounded-2xl p-6 bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400 text-sm">
                Für diesen Kurs ist noch kein Videokurs hinterlegt.
            </div>
        @endif

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex flex-col items-center justify-center">
            <div class="text-sm font-medium text-slate-600 dark:text-slate-300 mb-2">Kursfortschritt</div>
            <x-progress-ring :percent="$overallPercent" :size="88" :stroke="8" />
            <div class="flex items-center gap-3 text-xs text-slate-400 mt-2">
                <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full" style="background-color: var(--brand-secondary, #00A8A8)"></span> Gefestigt</span>
                <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-slate-200 dark:bg-slate-600"></span> Offen</span>
            </div>
        </div>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
        @foreach ($modules as $entry)
            <a href="{{ route('learning.show', $course) }}?mode=smarttrainer&module={{ $entry['module']->id }}"
               class="relative rounded-2xl p-4 h-28 flex flex-col justify-between text-white overflow-hidden bg-gradient-to-br {{ $tileColors[$loop->index % count($tileColors)] }} hover:opacity-95 transition">
                <x-icon :name="$icons[$loop->index % count($icons)]" class="w-6 h-6 text-white/60 self-end" />
                <div>
                    <div class="font-semibold">{{ $entry['module']->name }}</div>
                    <div class="text-xs text-white/80">{{ $entry['mastered'] }} / {{ $entry['total'] }} &middot; {{ $entry['percent'] }}%</div>
                </div>
            </a>
        @endforeach

        <a href="{{ route('exam.intro', $course) }}" class="relative rounded-2xl p-4 h-28 flex flex-col justify-between text-white overflow-hidden bg-gradient-to-br from-indigo-600 to-blue-700 hover:opacity-95 transition">
            <x-icon name="clipboard-document-check" class="w-6 h-6 text-white/60 self-end" />
            <div>
                <div class="font-semibold">Prüfungssimulation</div>
                <div class="text-xs text-white/80">{{ $hasExam ? 'Bereit zum Starten' : 'Noch nicht freigegeben' }}</div>
            </div>
        </a>

        @if ($hasVideoCourse)
            <a href="{{ route('video.index', $course) }}" class="relative rounded-2xl p-4 h-28 flex flex-col justify-between text-white overflow-hidden bg-gradient-to-br from-blue-600 to-cyan-500 hover:opacity-95 transition">
                <x-icon name="academic-cap" class="w-6 h-6 text-white/60 self-end" />
                <div>
                    <div class="font-semibold">Knoten</div>
                    <div class="text-xs text-white/80">{{ $videoPercent }}% angesehen</div>
                </div>
            </a>
        @endif

        @if ($hasNavigationTasks)
            <a href="{{ route('exam.navigation.index', $course) }}" class="relative rounded-2xl p-4 h-28 flex flex-col justify-between text-white overflow-hidden bg-gradient-to-br from-amber-500 to-orange-600 hover:opacity-95 transition">
                <x-icon name="compass" class="w-6 h-6 text-white/60 self-end" />
                <div>
                    <div class="font-semibold">Navigationsaufgaben</div>
                    <div class="text-xs text-white/80">Übung mit Musterlösung</div>
                </div>
            </a>
        @endif

        @if ($hasVideoCourse)
            <a href="{{ route('video.index', $course) }}" class="relative rounded-2xl p-4 h-28 flex flex-col justify-between text-white overflow-hidden bg-gradient-to-br from-sky-500 to-indigo-600 hover:opacity-95 transition">
                <x-icon name="flag" class="w-6 h-6 text-white/60 self-end" />
                <div>
                    <div class="font-semibold">Navigation</div>
                    <div class="text-xs text-white/80">{{ $videoPercent }}% angesehen</div>
                </div>
            </a>
        @endif

        @if ($hasVideoCourse)
            <a href="{{ route('video.index', $course) }}" class="relative rounded-2xl p-4 h-28 flex flex-col justify-between text-white overflow-hidden bg-gradient-to-br from-rose-500 to-red-600 hover:opacity-95 transition">
                <x-icon name="bolt" class="w-6 h-6 text-white/60 self-end" />
                <div>
                    <div class="font-semibold">Praxisvideos (Motor)</div>
                    <div class="text-xs text-white/80">{{ $videoPercent }}% angesehen</div>
                </div>
            </a>
        @endif
    </div>

    <div class="space-y-3">
        @foreach ($modules as $entry)
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
                <div class="flex items-center gap-3 mb-2">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-300">
                        <x-icon :name="$icons[$loop->index % count($icons)]" class="w-4 h-4" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="font-medium text-slate-700 dark:text-slate-200">{{ $entry['module']->name }}</div>
                    </div>
                    <div class="text-sm text-slate-400 shrink-0">{{ $entry['mastered'] }} / {{ $entry['total'] }} &middot; {{ $entry['percent'] }}%</div>
                </div>
                <div class="w-full bg-slate-100 dark:bg-slate-700 rounded-full h-2 mb-3">
                    <div class="h-2 rounded-full" style="width: {{ $entry['percent'] }}%; background-color: var(--brand-secondary, #00A8A8)"></div>
                </div>
                <div class="flex flex-wrap gap-2 text-sm">
                    <a href="{{ route('learning.show', $course) }}?mode=new&module={{ $entry['module']->id }}" class="px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-600 transition">Neue Fragen</a>
                    <a href="{{ route('learning.show', $course) }}?mode=wrong&module={{ $entry['module']->id }}" class="px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-600 transition">Falsch beantwortet</a>
                </div>
            </div>
        @endforeach
    </div>
</x-app-layout>

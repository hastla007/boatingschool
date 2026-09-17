<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">{{ $course->name }}</h2>
                <p class="text-sm text-slate-400">Fortschritt gesamt</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('learning.show', $course) }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-white text-sm font-medium" style="background-color: var(--brand-primary, #005FD7)">
                    <x-icon name="bolt" class="w-4 h-4" /> Smarttrainer starten
                </a>
                <a href="{{ route('exam.intro', $course) }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-sm font-medium text-slate-700 dark:text-slate-200">
                    <x-icon name="clipboard-document-check" class="w-4 h-4" /> Prüfungssimulation
                </a>
            </div>
        </div>
    </x-slot>

    @php
        $icons = ['book-open', 'compass', 'flag', 'life-buoy', 'shield-check', 'chart-bar'];
    @endphp

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
                    <a href="{{ route('learning.show', $course) }}?mode=new" class="px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-600 transition">Neue Fragen</a>
                    <a href="{{ route('learning.show', $course) }}?mode=wrong" class="px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-600 transition">Falsch beantwortet</a>
                </div>
            </div>
        @endforeach
    </div>
</x-app-layout>

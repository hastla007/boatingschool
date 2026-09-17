<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">{{ $course->name }}</h2>
                <p class="text-sm text-slate-400">Fortschritt gesamt</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('learning.show', $course) }}" class="px-4 py-2 rounded-lg text-white text-sm font-medium" style="background-color: var(--brand-primary, #005FD7)">Smarttrainer starten</a>
                <a href="{{ route('exam.intro', $course) }}" class="px-4 py-2 rounded-lg border border-slate-300 text-sm font-medium text-slate-700 dark:text-slate-200">Prüfungssimulation</a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-3">
        @foreach ($modules as $entry)
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-4">
                <div class="flex items-center justify-between mb-2">
                    <div class="font-medium text-slate-700 dark:text-slate-200">{{ $entry['module']->name }}</div>
                    <div class="text-sm text-slate-400">{{ $entry['mastered'] }} / {{ $entry['total'] }} · {{ $entry['percent'] }}%</div>
                </div>
                <div class="w-full bg-slate-100 dark:bg-slate-700 rounded-full h-2 mb-3">
                    <div class="h-2 rounded-full" style="width: {{ $entry['percent'] }}%; background-color: var(--brand-secondary, #00A8A8)"></div>
                </div>
                <div class="flex flex-wrap gap-2 text-sm">
                    <a href="{{ route('learning.show', $course) }}?mode=new" class="px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-200">Neue Fragen</a>
                    <a href="{{ route('learning.show', $course) }}?mode=wrong" class="px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-200">Falsch beantwortet</a>
                </div>
            </div>
        @endforeach
    </div>
</x-app-layout>

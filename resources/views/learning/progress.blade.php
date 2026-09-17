<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Mein Fortschritt &amp; Statistiken</h2>
    </x-slot>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-4">
            <div class="text-2xl font-bold text-slate-800 dark:text-white">{{ $overallAccuracy }}%</div>
            <div class="text-xs text-slate-400">Trefferquote gesamt</div>
        </div>
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-4">
            <div class="text-2xl font-bold text-slate-800 dark:text-white">{{ $answered }}/{{ $totalQuestions }}</div>
            <div class="text-xs text-slate-400">Fragen bearbeitet</div>
        </div>
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-4">
            <div class="text-2xl font-bold text-emerald-600">{{ $mastered }}</div>
            <div class="text-xs text-slate-400">gefestigt</div>
        </div>
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-4">
            <div class="text-2xl font-bold text-amber-500">{{ $dueReviews }}</div>
            <div class="text-xs text-slate-400">Wiederholung fällig</div>
        </div>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-4">
        <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Trefferquote nach Themen</h3>
        <div class="space-y-3">
            @forelse ($byTopic as $topic => $stats)
                <div>
                    <div class="flex justify-between text-sm mb-1">
                        <span class="text-slate-600 dark:text-slate-300">{{ $topic }}</span>
                        <span class="text-slate-400">{{ $stats['accuracy'] }}%</span>
                    </div>
                    <div class="w-full bg-slate-100 dark:bg-slate-700 rounded-full h-2">
                        <div class="h-2 rounded-full {{ $stats['accuracy'] < 60 ? 'bg-rose-500' : '' }}"
                             style="width: {{ $stats['accuracy'] }}%; {{ $stats['accuracy'] >= 60 ? 'background-color: var(--brand-secondary, #00A8A8)' : '' }}"></div>
                    </div>
                </div>
            @empty
                <p class="text-slate-500 text-sm">Noch keine Lernaktivität vorhanden.</p>
            @endforelse
        </div>
    </div>
</x-app-layout>

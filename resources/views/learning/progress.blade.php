<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Mein Fortschritt &amp; Statistiken</h2>
    </x-slot>

    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 mb-6 items-stretch">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-4">
            <x-progress-ring :percent="$overallAccuracy" :size="72" :stroke="7" />
            <div>
                <div class="text-sm font-medium text-slate-700 dark:text-slate-200">Trefferquote</div>
                <div class="text-xs text-slate-400">gesamt</div>
            </div>
        </div>
        <x-stat-tile icon="book-open" :value="$answered.'/'.$totalQuestions" label="Fragen bearbeitet" />
        <x-stat-tile icon="check-circle" :value="$mastered" label="gefestigt" tone="success" />
        <x-stat-tile icon="clock" :value="$dueReviews" label="Wiederholung fällig" tone="warning" />
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
        <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
            <x-icon name="chart-bar" class="w-4 h-4 text-slate-400" /> Trefferquote nach Themen
        </h3>
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

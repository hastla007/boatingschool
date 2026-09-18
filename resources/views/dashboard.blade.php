<x-app-layout>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-800 dark:text-white">Hallo {{ explode(' ', Auth::user()->name)[0] }}!</h1>
            <p class="text-slate-500 dark:text-slate-400">Schön, dass du wieder da bist.</p>
        </div>

        @if ($courses->isEmpty())
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 text-center">
                <x-icon name="life-buoy" class="w-8 h-8 mx-auto text-slate-300 mb-2" />
                <p class="text-slate-600 dark:text-slate-300">Für dich ist noch kein Kurs freigeschaltet.</p>
                <p class="text-sm text-slate-400 mt-1">Bitte wende dich an deine Bootsschule, um Zugang zu erhalten.</p>
            </div>
        @else
            @php($primary = $courses->sortByDesc(fn($c) => $c['answered'])->first())
            <a href="{{ route('learning.show', $primary['course']) }}"
               class="flex items-center justify-between rounded-2xl p-5 text-white shadow-sm hover:opacity-95 transition"
               style="background: linear-gradient(120deg, var(--brand-primary, #005FD7), color-mix(in srgb, var(--brand-primary, #005FD7) 65%, #001233));">
                <div class="flex items-center gap-4">
                    <div class="w-11 h-11 rounded-xl bg-white/15 flex items-center justify-center shrink-0">
                        <x-icon name="bolt" class="w-6 h-6" />
                    </div>
                    <div>
                        <div class="font-semibold text-lg">Weiterlernen</div>
                        <div class="text-white/80 text-sm">{{ $primary['course']->name }} &middot; Smarttrainer wählt die nächste Frage für dich</div>
                    </div>
                </div>
                <x-icon name="arrow-right" class="w-6 h-6 shrink-0" />
            </a>

            <div>
                <h2 class="font-semibold text-slate-700 dark:text-slate-200 mb-3">Dein Fortschritt</h2>
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-stretch">
                    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-4">
                        <x-progress-ring :percent="$primary['percent']" :size="72" :stroke="7" />
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-slate-700 dark:text-slate-200 truncate">{{ $primary['course']->name }}</div>
                            <div class="text-xs text-slate-400">Fortschritt gesamt</div>
                        </div>
                    </div>
                    <x-stat-tile icon="check-circle" :value="$totalCorrect" label="richtig" tone="success" />
                    <x-stat-tile icon="x-circle" :value="$totalIncorrect" label="falsch" tone="danger" />
                    <x-stat-tile icon="chart-bar" :value="$totalAttempts" label="gesamt beantwortet" />
                </div>
                @if ($dueReviews > 0)
                    <p class="text-sm text-amber-600 mt-3 flex items-center gap-1.5">
                        <x-icon name="clock" class="w-4 h-4" /> {{ $dueReviews }} Wiederholung(en) sind fällig.
                    </p>
                @endif
            </div>
        @endif
    </div>
</x-app-layout>

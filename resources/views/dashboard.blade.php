<x-app-layout>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-800 dark:text-white">Hallo {{ explode(' ', Auth::user()->name)[0] }}!</h1>
            <p class="text-slate-500 dark:text-slate-400">Schön, dass du wieder da bist.</p>
        </div>

        @if ($courses->isEmpty())
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-6 text-center">
                <p class="text-slate-600 dark:text-slate-300">Für dich ist noch kein Kurs freigeschaltet.</p>
                <p class="text-sm text-slate-400 mt-1">Bitte wende dich an deine Bootsschule, um Zugang zu erhalten.</p>
            </div>
        @else
            @php($primary = $courses->sortByDesc(fn($c) => $c['answered'])->first())
            <a href="{{ route('learning.show', $primary['course']) }}"
               class="block rounded-xl p-5 text-white shadow-sm hover:opacity-95 transition"
               style="background-color: var(--brand-primary, #005FD7)">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="font-semibold text-lg">Weiterlernen</div>
                        <div class="text-white/80 text-sm">{{ $primary['course']->name }} · Smarttrainer wählt die nächste Frage für dich</div>
                    </div>
                    <span class="text-2xl">→</span>
                </div>
            </a>

            <div>
                <h2 class="font-semibold text-slate-700 dark:text-slate-200 mb-3">Dein Fortschritt</h2>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-4">
                        <div class="text-2xl font-bold text-slate-800 dark:text-white">{{ $primary['percent'] }}%</div>
                        <div class="text-xs text-slate-400">{{ $primary['course']->name }}</div>
                    </div>
                    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-4">
                        <div class="text-2xl font-bold text-emerald-600">{{ $totalCorrect }}</div>
                        <div class="text-xs text-slate-400">richtig</div>
                    </div>
                    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-4">
                        <div class="text-2xl font-bold text-rose-500">{{ $totalIncorrect }}</div>
                        <div class="text-xs text-slate-400">falsch</div>
                    </div>
                    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-4">
                        <div class="text-2xl font-bold text-slate-800 dark:text-white">{{ $totalAttempts }}</div>
                        <div class="text-xs text-slate-400">gesamt beantwortet</div>
                    </div>
                </div>
                @if ($dueReviews > 0)
                    <p class="text-sm text-amber-600 mt-2">{{ $dueReviews }} Wiederholung(en) sind fällig.</p>
                @endif
            </div>

            <div>
                <h2 class="font-semibold text-slate-700 dark:text-slate-200 mb-3">Deine Kurse</h2>
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach ($courses as $summary)
                        <a href="{{ route('courses.show', $summary['course']) }}" class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-4 hover:shadow-md transition block">
                            <div class="font-medium text-slate-800 dark:text-white">{{ $summary['course']->name }}</div>
                            <div class="text-xs text-slate-400 mb-2">Fortschritt gesamt</div>
                            <div class="w-full bg-slate-100 dark:bg-slate-700 rounded-full h-2">
                                <div class="h-2 rounded-full" style="width: {{ $summary['percent'] }}%; background-color: var(--brand-secondary, #00A8A8)"></div>
                            </div>
                            <div class="text-xs text-slate-500 mt-1">{{ $summary['percent'] }}%</div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-app-layout>

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
            <x-icon name="light-bulb" class="w-4 h-4 text-slate-400" /> Fortschritt nach Themen (Smart-Learning)
        </h3>
        <div class="space-y-3">
            @forelse ($byTopic as $topic => $stats)
                <a href="{{ route('learning.show', ['course' => $stats['course'], 'mode' => 'topic', 'module' => $stats['module']->id, 'topic' => $topic]) }}" class="block group">
                    <div class="flex justify-between text-sm mb-1">
                        <span class="text-slate-600 dark:text-slate-300 group-hover:underline">{{ $topic }}</span>
                        <span class="text-slate-400">{{ $stats['mastered'] }}/{{ $stats['total'] }} &middot; {{ $stats['percent'] }}%</span>
                    </div>
                    <div class="w-full bg-slate-100 dark:bg-slate-700 rounded-full h-2">
                        <div class="h-2 rounded-full {{ $stats['percent'] < 60 ? 'bg-rose-500' : '' }}"
                             style="width: {{ $stats['percent'] }}%; {{ $stats['percent'] >= 60 ? 'background-color: var(--brand-secondary, #00A8A8)' : '' }}"></div>
                    </div>
                </a>
            @empty
                <p class="text-slate-500 text-sm">Noch keine Lernaktivität vorhanden.</p>
            @endforelse
        </div>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 mt-4">
        <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
            <x-icon name="clipboard-document-check" class="w-4 h-4 text-slate-400" /> Ergebnisse der Prüfungssimulationen
        </h3>
        <div class="space-y-3">
            @forelse ($examResults as $session)
                <div class="rounded-xl border border-slate-100 dark:border-slate-700 px-4 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-slate-700 dark:text-slate-200 truncate">
                                {{ $session->course->name }}
                                <span class="text-slate-400 font-normal">&middot; {{ $session->displayLabel }}</span>
                            </div>
                            <div class="text-xs text-slate-400">{{ $session->submitted_at?->format('d.m.Y H:i') }}</div>
                        </div>
                        <div class="flex items-center gap-3 shrink-0">
                            <span class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ (int) round($session->score * 100) }}%</span>
                            <span class="inline-flex items-center gap-1 text-xs px-2 py-1 rounded-full {{ $session->passed ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-rose-50 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300' }}">
                                <x-icon :name="$session->passed ? 'check-circle' : 'x-circle'" class="w-3.5 h-3.5" />
                                {{ $session->passed ? 'Bestanden' : 'Nicht bestanden' }}
                            </span>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 mt-3">
                        <form method="POST" action="{{ $session->paper ? route('exam.papers.start', ['course' => $session->course, 'paper' => $session->paper]) : route('exam.start', $session->course) }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-600 transition">
                                <x-icon name="arrow-path" class="w-3.5 h-3.5" /> Erneut starten
                            </button>
                        </form>
                        @if ($session->wrongQuestionIds->isNotEmpty())
                            <a href="{{ route('learning.show', ['course' => $session->course, 'mode' => 'smarttrainer', 'questions' => $session->wrongQuestionIds->implode(',')]) }}"
                               class="inline-flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-600 transition">
                                <x-icon name="x-circle" class="w-3.5 h-3.5" /> Falsche Fragen erneut lernen
                            </a>
                        @endif
                        <a href="{{ route('exam.result', $session) }}"
                           class="inline-flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-lg text-white hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                            <x-icon name="chart-bar" class="w-3.5 h-3.5" /> Ergebnisse
                        </a>
                    </div>
                </div>
            @empty
                <p class="text-slate-500 text-sm">Noch keine abgeschlossene Prüfungssimulation vorhanden.</p>
            @endforelse
        </div>
    </div>
</x-app-layout>

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Prüfungsergebnis</h2>
    </x-slot>

    <div class="max-w-2xl mx-auto space-y-6">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-6 text-center">
            <div class="text-4xl mb-2">{{ $examSession->passed ? '✅' : '❌' }}</div>
            <div class="text-2xl font-bold {{ $examSession->passed ? 'text-emerald-600' : 'text-rose-500' }}">
                {{ $examSession->passed ? 'Bestanden' : 'Nicht bestanden' }}
            </div>
            <div class="text-slate-500 mt-1">{{ (int) round($examSession->score * 100) }}% richtig · {{ $questions->where('correct', true)->count() }}/{{ $questions->count() }} Fragen</div>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-4">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Themenanalyse</h3>
            <div class="space-y-2">
                @foreach ($topicBreakdown as $topic => $stats)
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-600 dark:text-slate-300">{{ $topic }}</span>
                        <span class="text-slate-400">{{ $stats['correct'] }}/{{ $stats['total'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        @if ($wrong->isNotEmpty())
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-4">
                <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Fehlerliste</h3>
                <div class="space-y-3">
                    @foreach ($wrong as $item)
                        <div class="border-l-4 border-rose-400 pl-3">
                            <div class="text-slate-700 dark:text-slate-200 text-sm">{{ $item->revision->question_text }}</div>
                            <div class="text-xs text-slate-400">Richtige Antwort: {{ $item->revision->correctAnswer()?->answer_text }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <a href="{{ route('courses.show', $examSession->course) }}" class="block text-center px-5 py-2 rounded-lg text-white text-sm font-medium" style="background-color: var(--brand-primary, #005FD7)">
            Zurück zum Kurs
        </a>
    </div>
</x-app-layout>

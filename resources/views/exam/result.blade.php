<x-app-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Prüfungsergebnis</h1>
    </x-slot>

    <div class="max-w-2xl mx-auto space-y-6">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 text-center">
            <div class="w-16 h-16 rounded-full mx-auto mb-3 flex items-center justify-center {{ $examSession->passed ? 'bg-emerald-50 dark:bg-emerald-900/30' : 'bg-rose-50 dark:bg-rose-900/30' }}">
                <x-icon :name="$examSession->passed ? 'check-circle' : 'x-circle'" class="w-9 h-9 {{ $examSession->passed ? 'text-emerald-500' : 'text-rose-500' }}" />
            </div>
            <div class="text-2xl font-bold {{ $examSession->passed ? 'text-emerald-600' : 'text-rose-500' }}">
                {{ $examSession->passed ? 'Bestanden' : 'Nicht bestanden' }}
            </div>
            {{-- Bug-Review (Hoch): open_text-Fragen zählten hier bisher nicht
                 zur Quote (siehe ExamEvaluationService) -- diese Anzeige wies
                 sie deshalb separat als "nicht bewertet" aus. Sie fließen
                 jetzt wie jede andere Frage in $examSession->score ein. --}}
            <div class="text-slate-500 mt-1">{{ (int) round($examSession->score * 100) }}% richtig &middot; {{ $questions->where('correct', true)->count() }}/{{ $questions->count() }} Fragen</div>
            @if ($openTextCount > 0)
                {{-- Bug-Review (FKN offene Fragen): die Quote oben enthält
                     Punkte aus offenen Fragen, die per Selbsteinschätzung
                     (nicht automatisch) bewertet wurden -- dieser Hinweis
                     macht das transparent, statt es unausgewiesen zu lassen. --}}
                <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    Davon {{ $openTextCorrect }}/{{ $openTextCount }} Punkte aus offenen Fragen durch Selbsteinschätzung (keine automatische Prüfung).
                </div>
            @endif
            @if ($partResults !== [])
                {{-- Amtliche Teilgrenzen: jeder Teil muss für sich bestanden sein. --}}
                <div class="mt-3 flex flex-wrap justify-center gap-2">
                    @foreach ($partResults as $part)
                        <span class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-medium {{ $part['passed'] ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-rose-50 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300' }}">
                            <x-icon :name="$part['passed'] ? 'check-circle' : 'x-circle'" class="w-4 h-4" />
                            {{ $part['label'] }}: {{ $part['correct'] }}/{{ $part['total'] }} (mind. {{ $part['min_correct'] }}/{{ $part['of'] }})
                        </span>
                    @endforeach
                </div>
            @endif
            @if ($examSession->ruleSet->note())
                <div class="text-xs text-slate-500 dark:text-slate-400 mt-2">{{ $examSession->ruleSet->note() }}</div>
            @endif
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
                <x-icon name="chart-bar" class="w-4 h-4 text-slate-600 dark:text-slate-400" /> Themenanalyse
            </h3>
            <div class="space-y-2">
                @foreach ($topicBreakdown as $topic => $stats)
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-600 dark:text-slate-300">{{ $topic }}</span>
                        <span class="text-slate-600 dark:text-slate-400">{{ $stats['correct'] }}/{{ $stats['total'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        @if ($wrong->isNotEmpty())
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
                <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Fehlerliste</h3>
                <div class="space-y-3">
                    @foreach ($wrong as $item)
                        <div class="border-l-4 border-rose-400 pl-3">
                            <div class="text-slate-700 dark:text-slate-200 text-sm">{{ $item->revision->question_text }}</div>
                            @if ($item->revision->question_type === 'multi_choice')
                                <div class="text-xs text-slate-600 dark:text-slate-400">Richtige Antworten: {{ $item->revision->correctAnswers()->pluck('answer_text')->implode('; ') }}</div>
                            @elseif ($item->revision->question_type === 'open_text')
                                <div class="text-xs text-slate-600 dark:text-slate-400">Musterantwort: {{ $item->revision->correctAnswer()?->answer_text }}</div>
                            @else
                                <div class="text-xs text-slate-600 dark:text-slate-400">Richtige Antwort: {{ $item->revision->correctAnswer()?->answer_text }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($unanswered->isNotEmpty())
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
                <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Nicht beantwortet</h3>
                <div class="space-y-3">
                    @foreach ($unanswered as $item)
                        <div class="border-l-4 border-amber-400 pl-3">
                            <div class="text-slate-700 dark:text-slate-200 text-sm">{{ $item->revision->question_text }}</div>
                            @if ($item->revision->question_type === 'multi_choice')
                                <div class="text-xs text-slate-600 dark:text-slate-400">Richtige Antworten: {{ $item->revision->correctAnswers()->pluck('answer_text')->implode('; ') }}</div>
                            @elseif ($item->revision->question_type === 'open_text')
                                <div class="text-xs text-slate-600 dark:text-slate-400">Musterantwort: {{ $item->revision->correctAnswer()?->answer_text }}</div>
                            @else
                                <div class="text-xs text-slate-600 dark:text-slate-400">Richtige Antwort: {{ $item->revision->correctAnswer()?->answer_text }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <a href="{{ route('courses.show', $examSession->course) }}" class="flex items-center justify-center gap-1.5 text-center px-5 py-2.5 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
            Zurück zum Kurs
        </a>
    </div>
</x-app-layout>

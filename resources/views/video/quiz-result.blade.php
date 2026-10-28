<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            {{-- Bug-Review (Zurück-Pfeile ohne Beschriftung): nur ein Icon ohne
                 Text -- ohne aria-label hatte dieser Link keinen für
                 Screenreader erkennbaren Namen. --}}
            <a href="{{ route('video.show', array_filter(['course' => $course, 'lesson' => $lesson, 'kapitel' => $moduleIds ? implode(',', $moduleIds) : null])) }}"
               aria-label="Zurück zur Lektion"
               class="w-8 h-8 rounded-full flex items-center justify-center text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700 shrink-0">
                <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
            </a>
            <div class="flex-1">
                <div class="text-xs text-slate-600 dark:text-slate-400">{{ $lesson->title }}</div>
                <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Fragen zum Video</h1>
            </div>
            <div class="text-sm text-slate-500 shrink-0">{{ $total }}/{{ $total }}</div>
        </div>
    </x-slot>

    <div class="max-w-2xl mx-auto space-y-4">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 text-center">
            <h3 class="text-lg font-semibold text-slate-800 dark:text-white">Auswertung</h3>
            <p class="text-sm text-slate-500 mt-1">Du hast {{ $correctCount }} von {{ $total }} Fragen richtig beantwortet!</p>
        </div>

        @foreach ($reviews as $review)
            @php $revision = $review['revision']; @endphp
            <div class="rounded-2xl p-5 {{ $review['correct'] ? 'bg-emerald-50 dark:bg-emerald-900/20' : 'bg-rose-50 dark:bg-rose-900/20' }}">
                <h4 class="text-center font-medium text-slate-800 dark:text-white mb-4">{{ $revision->question_text }}</h4>
                <div class="space-y-2">
                    @foreach ($revision->answers as $answer)
                        @php
                            $state = 'bg-white dark:bg-slate-800 border-transparent';
                            $icon = null;
                            $wasSelected = $answer->id === $review['selectedAnswerId']
                                || in_array($answer->id, $review['selectedAnswerIds'] ?? [], true);
                            if ($answer->is_correct) {
                                $state = 'bg-white dark:bg-slate-800 border-emerald-500';
                                $icon = ['check-circle', 'text-emerald-500'];
                            } elseif ($wasSelected) {
                                $state = 'bg-white dark:bg-slate-800 border-rose-500';
                                $icon = ['x-circle', 'text-rose-500'];
                            }
                        @endphp
                        <div class="flex items-center gap-3 border rounded-xl px-4 py-3 {{ $state }}">
                            <span class="text-slate-700 dark:text-slate-200 flex-1 text-sm">{{ $answer->answer_text }}</span>
                            @if ($icon)
                                <x-icon :name="$icon[0]" class="w-5 h-5 shrink-0 {{ $icon[1] }}" />
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div class="flex flex-col items-center gap-3 pt-2">
            <a href="{{ route('video.quiz.show', array_filter(['course' => $course, 'lesson' => $lesson, 'position' => 1, 'kapitel' => $moduleIds ? implode(',', $moduleIds) : null])) }}"
               class="w-full sm:w-auto sm:min-w-[20rem] text-center px-6 py-2.5 rounded-full text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-secondary, #007A7A)">
                Alle Fragen erneut versuchen
            </a>

            @if ($wrongQuestionIds->isNotEmpty())
                <a href="{{ route('video.quiz.show', array_filter(['course' => $course, 'lesson' => $lesson, 'position' => 1, 'kapitel' => $moduleIds ? implode(',', $moduleIds) : null, 'questions' => $wrongQuestionIds->implode(',')])) }}"
                   class="w-full sm:w-auto sm:min-w-[20rem] text-center px-6 py-2.5 rounded-full text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-secondary, #007A7A)">
                    Nur falsche Fragen erneut versuchen
                </a>
            @else
                <span class="w-full sm:w-auto sm:min-w-[20rem] text-center px-6 py-2.5 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400 text-sm font-medium cursor-not-allowed">
                    Nur falsche Fragen erneut versuchen
                </span>
            @endif

            <form method="POST" action="{{ route('video.complete', array_filter(['course' => $course, 'lesson' => $lesson, 'kapitel' => $moduleIds ? implode(',', $moduleIds) : null])) }}" class="w-full sm:w-auto sm:min-w-[20rem]">
                @csrf
                <button type="submit" class="w-full text-center px-6 py-2.5 rounded-full text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                    Nächstes Kapitel
                </button>
            </form>
        </div>
    </div>
</x-app-layout>

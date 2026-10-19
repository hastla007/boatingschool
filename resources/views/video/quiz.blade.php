<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('video.show', array_filter(['course' => $course, 'lesson' => $lesson, 'kapitel' => $moduleIds ? implode(',', $moduleIds) : null])) }}"
               class="w-8 h-8 rounded-full flex items-center justify-center text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700 shrink-0">
                <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
            </a>
            @php
                $linkParams = fn (array $extra = []) => array_filter(array_merge(['course' => $course, 'lesson' => $lesson, 'kapitel' => $moduleIds ? implode(',', $moduleIds) : null, 'questions' => $questionsParam], $extra));
            @endphp
            <div class="flex-1">
                <div class="text-xs text-slate-400">{{ $lesson->title }}</div>
                <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Fragen zum Video</h2>
            </div>
            <div class="text-sm text-slate-500 shrink-0">{{ $position }}/{{ $total }}</div>
        </div>
    </x-slot>

    <div class="max-w-2xl mx-auto bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6">
        <div class="w-full bg-slate-100 dark:bg-slate-700 rounded-full h-1.5 mb-6">
            <div class="h-1.5 rounded-full transition-all" style="width: {{ $total ? ($position / $total * 100) : 0 }}%; background-color: var(--brand-secondary, #00A8A8)"></div>
        </div>

        @foreach ($revision->media as $media)
            <img src="{{ $media->storage_path }}" alt="{{ $media->alt_text }}" class="rounded-lg mb-4 max-h-64 mx-auto">
        @endforeach

        <h3 class="text-lg font-medium text-slate-800 dark:text-white mb-4">{{ $revision->question_text }}</h3>

        <form method="POST" action="{{ route('video.quiz.answer', $linkParams(['position' => $position])) }}" x-data="{ selected: {{ $selectedAnswerId ? "'{$selectedAnswerId}'" : 'null' }} }">
            @csrf

            <div class="space-y-2">
                @foreach ($revision->answers as $answer)
                    @php
                        $state = 'border-slate-200 dark:border-slate-600 hover:border-slate-400';
                        $showCorrectIcon = false;
                        $showWrongIcon = false;
                        if ($answered) {
                            if ($answer->is_correct) {
                                $state = 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/30';
                                $showCorrectIcon = true;
                            } elseif ($answer->id === $selectedAnswerId) {
                                $state = 'border-rose-500 bg-rose-50 dark:bg-rose-900/30';
                                $showWrongIcon = true;
                            }
                        }
                    @endphp
                    <label class="flex items-center gap-3 border rounded-xl px-4 py-3 cursor-pointer transition {{ $state }}">
                        <input type="radio" name="answer_id" value="{{ $answer->id }}" x-model="selected"
                               @checked($answer->id === $selectedAnswerId) {{ $answered ? 'disabled' : '' }} class="shrink-0">
                        <span class="text-slate-700 dark:text-slate-200 flex-1">{{ $answer->answer_text }}</span>
                        @if ($showCorrectIcon)
                            <x-icon name="check-circle" class="w-5 h-5 text-emerald-500 shrink-0" />
                        @elseif ($showWrongIcon)
                            <x-icon name="x-circle" class="w-5 h-5 text-rose-500 shrink-0" />
                        @endif
                    </label>
                @endforeach
            </div>

            @unless ($answered)
                <div class="flex justify-end mt-6">
                    <button type="submit" x-bind:disabled="!selected"
                            class="px-5 py-2 rounded-lg text-white text-sm font-medium disabled:opacity-40 hover:opacity-90 transition"
                            style="background-color: var(--brand-primary, #005FD7)">
                        Antwort senden
                    </button>
                </div>
            @endunless
        </form>

        @if ($answered)
            <div class="mt-4 rounded-xl p-4 flex items-start gap-2 {{ $correct ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-rose-50 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300' }}">
                <x-icon :name="$correct ? 'check-circle' : 'x-circle'" class="w-5 h-5 shrink-0 mt-0.5" />
                <div>
                    <strong>{{ $correct ? 'Richtig!' : 'Leider falsch.' }}</strong>
                    @if (!$correct)
                        <div class="text-sm mt-1">Richtige Antwort: {{ $revision->correctAnswer()?->answer_text }}</div>
                    @endif
                    @php($feedback = $correct ? $revision->feedback_correct : $revision->feedback_incorrect)
                    @if ($feedback)
                        <div class="text-sm mt-2 whitespace-pre-line">{{ $feedback }}</div>
                    @endif
                </div>
            </div>
            <div class="flex justify-end mt-4">
                @if ($position < $total)
                    <a href="{{ route('video.quiz.show', $linkParams(['position' => $position + 1])) }}"
                       class="inline-flex items-center gap-1.5 px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                        Nächste Frage <x-icon name="arrow-right" class="w-4 h-4" />
                    </a>
                @else
                    <a href="{{ route('video.quiz.result', $linkParams()) }}"
                       class="inline-flex items-center gap-1.5 px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                        Auswertung anzeigen <x-icon name="arrow-right" class="w-4 h-4" />
                    </a>
                @endif
            </div>
        @endif
    </div>
</x-app-layout>

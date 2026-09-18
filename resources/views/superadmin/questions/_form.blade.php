@php
    $answers = $revision?->answers ?? collect();
    $answerText = fn (string $key) => old("answers.{$key}", optional($answers->firstWhere('answer_key', $key))->answer_text);
    $correctKey = old('correct', optional($answers->firstWhere('is_correct', true))->answer_key ?? 'A');
@endphp

<div>
    <x-input-label for="question_text" value="Frage" />
    <textarea id="question_text" name="question_text" rows="3" required
              class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ old('question_text', $revision?->question_text) }}</textarea>
    <x-input-error :messages="$errors->get('question_text')" class="mt-2" />
</div>

<div class="grid sm:grid-cols-3 gap-4">
    <div>
        <x-input-label for="topic" value="Thema" />
        <x-text-input id="topic" name="topic" type="text" class="mt-1 block w-full" :value="old('topic', $revision?->topic)" />
    </div>
    <div>
        <x-input-label for="subtopic" value="Unterthema" />
        <x-text-input id="subtopic" name="subtopic" type="text" class="mt-1 block w-full" :value="old('subtopic', $revision?->subtopic)" />
    </div>
    <div>
        <x-input-label for="smartmodus_kategorie" value="Smart-Learning-Kategorie" />
        <x-text-input id="smartmodus_kategorie" name="smartmodus_kategorie" type="text" class="mt-1 block w-full" :value="old('smartmodus_kategorie', $revision?->smartmodus_kategorie)" />
    </div>
</div>

@if (! $revision)
    <div>
        <x-input-label for="official_number" value="Amtliche Fragennummer (optional)" />
        <x-text-input id="official_number" name="official_number" type="text" class="mt-1 block w-full" :value="old('official_number')" />
    </div>
@endif

<div class="pt-2 border-t border-slate-100 dark:border-slate-700">
    <p class="text-sm font-medium text-slate-600 dark:text-slate-300 mb-3">Antworten (die richtige markieren)</p>
    <div class="space-y-3">
        @foreach (['A', 'B', 'C', 'D'] as $key)
            <div class="flex items-center gap-3">
                <input type="radio" name="correct" value="{{ $key }}" @checked($correctKey === $key) required class="shrink-0">
                <span class="w-5 text-sm text-slate-400 shrink-0">{{ $key }}</span>
                <x-text-input name="answers[{{ $key }}]" type="text" class="block w-full" :value="$answerText($key)" required />
            </div>
        @endforeach
    </div>
    <x-input-error :messages="$errors->get('answers')" class="mt-2" />
    <x-input-error :messages="$errors->get('correct')" class="mt-2" />
</div>

<div class="grid sm:grid-cols-2 gap-4 pt-2 border-t border-slate-100 dark:border-slate-700">
    <div>
        <x-input-label for="feedback_correct" value="Feedback bei richtiger Antwort (optional)" />
        <textarea id="feedback_correct" name="feedback_correct" rows="4"
                  class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ old('feedback_correct', $revision?->feedback_correct) }}</textarea>
        <p class="text-xs text-slate-400 mt-1">Wird unter „Richtig!" angezeigt.</p>
    </div>
    <div>
        <x-input-label for="feedback_incorrect" value="Feedback bei falscher Antwort (optional)" />
        <textarea id="feedback_incorrect" name="feedback_incorrect" rows="4"
                  class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ old('feedback_incorrect', $revision?->feedback_incorrect) }}</textarea>
        <p class="text-xs text-slate-400 mt-1">Wird unter „Leider falsch." angezeigt.</p>
    </div>
</div>

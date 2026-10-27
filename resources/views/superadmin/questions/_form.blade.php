@php
    // Bug-Review (Hoch): dieses Formular kannte bisher nur Single-Choice --
    // question_type wurde beim Speichern nie gelesen, sondern beim Anlegen
    // UND bei jeder Bearbeitung hart auf 'single_choice' gesetzt, die
    // Validierung verlangte zwingend genau die Antworten A-D plus ein
    // "correct". Eine bestehende Multi-Choice- oder Offene-Frage wurde
    // dadurch bei der ERSTEN Bearbeitung in diesem Editor lautlos in eine
    // Single-Choice-Frage mit genau 4 (teils erfundenen) Antworten
    // umgewandelt -- betraf alle 151 FKN/SKN-Fragen (siehe
    // FknSknContentSeeder), die multi_choice/open_text mischen. Der Fragetyp
    // ist jetzt Teil des Formulars: beim Anlegen wählbar, bei einer
    // bestehenden Frage unveränderlich (ein nachträglicher Typwechsel würde
    // die Antwortstruktur der Frage grundlegend ändern) und steuert, welcher
    // Antwort-Abschnitt unten angezeigt wird.
    $questionType = old('question_type', $revision?->question_type ?? 'single_choice');
    $answers = $revision?->answers ?? collect();

    if (old('multi_answers') !== null) {
        $oldCorrect = array_map('strval', old('multi_correct', []));
        $initialMultiAnswers = collect(old('multi_answers'))->values()->map(fn ($a, $i) => [
            'text' => $a['text'] ?? '',
            'correct' => in_array((string) $i, $oldCorrect, true),
        ])->all();
    } else {
        $initialMultiAnswers = $answers->values()->map(fn ($a) => [
            'text' => $a->answer_text,
            'correct' => (bool) $a->is_correct,
        ])->all();
    }
    if (count($initialMultiAnswers) < 2) {
        $initialMultiAnswers = array_pad($initialMultiAnswers, 2, ['text' => '', 'correct' => false]);
    }

    $singleAnswerText = fn (string $key) => old("answers.{$key}", optional($answers->firstWhere('answer_key', $key))->answer_text);
    $singleCorrectKey = old('correct', optional($answers->firstWhere('is_correct', true))->answer_key ?? 'A');

    $modelAnswer = old('model_answer', optional($answers->first())->answer_text);
@endphp

<div x-data="{ questionType: '{{ $questionType }}' }" class="space-y-5">
    <div>
        <x-input-label for="question_type" value="Fragetyp" />
        @if ($revision)
            <input type="hidden" name="question_type" value="{{ $questionType }}">
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                {{ ['single_choice' => 'Single Choice', 'multi_choice' => 'Multi Choice', 'open_text' => 'Offene Frage (Lernkarte)'][$questionType] ?? $questionType }}
                <span class="block text-xs text-slate-600 dark:text-slate-400">Der Fragetyp kann nachträglich nicht mehr geändert werden.</span>
            </p>
        @else
            <select id="question_type" name="question_type" x-model="questionType"
                    class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                <option value="single_choice" @selected($questionType === 'single_choice')>Single Choice (eine richtige Antwort)</option>
                <option value="multi_choice" @selected($questionType === 'multi_choice')>Multi Choice (mehrere richtige Antworten)</option>
                <option value="open_text" @selected($questionType === 'open_text')>Offene Frage (Lernkarte, Selbsteinschätzung)</option>
            </select>
        @endif
        <x-input-error :messages="$errors->get('question_type')" class="mt-2" />
    </div>

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

    <div>
        <x-input-label for="official_number" value="Amtliche Fragennummer (optional)" />
        <x-text-input id="official_number" name="official_number" type="text" class="mt-1 block w-full" :value="old('official_number', $question?->official_number)" />
    </div>

    {{-- Bug-Review (Fragen-Editor verliert Metadaten): competency/
         image_required/source_*/valid_from/valid_to bestückt der Import
         (ImportContentQuestions/FknSknContentSeeder) bereits -- ohne diese
         Felder im Formular wurden sie bei jeder Bearbeitung auf der neuen
         Revision stillschweigend geleert, was den nächsten Reimport
         außerdem lautlos eine neue Review-Revision erzeugen ließ (die
         Felder fließen in ImportContentQuestions::hashRevision() ein). --}}
    <div class="grid sm:grid-cols-2 gap-4 pt-2 border-t border-slate-100 dark:border-slate-700">
        <div>
            <x-input-label for="competency" value="Kompetenz (optional)" />
            <x-text-input id="competency" name="competency" type="text" class="mt-1 block w-full" :value="old('competency', $revision?->competency)" />
        </div>
        <div class="flex items-end pb-2">
            <label for="image_required" class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                <input type="checkbox" id="image_required" name="image_required" value="1" @checked(old('image_required', $revision?->image_required))>
                Bild zu dieser Frage erforderlich
            </label>
        </div>
    </div>

    <div class="grid sm:grid-cols-4 gap-4">
        <div>
            <x-input-label for="source_catalog" value="Quelle: Katalog (optional)" />
            <x-text-input id="source_catalog" name="source_catalog" type="text" class="mt-1 block w-full" :value="old('source_catalog', $revision?->source_catalog)" />
        </div>
        <div>
            <x-input-label for="source_version" value="Quelle: Version (optional)" />
            <x-text-input id="source_version" name="source_version" type="text" class="mt-1 block w-full" :value="old('source_version', $revision?->source_version)" />
        </div>
        <div>
            <x-input-label for="source_page" value="Quelle: Seite (optional)" />
            <x-text-input id="source_page" name="source_page" type="text" class="mt-1 block w-full" :value="old('source_page', $revision?->source_page)" />
        </div>
        <div>
            <x-input-label for="source_question_id" value="Quelle: Fragen-ID (optional)" />
            <x-text-input id="source_question_id" name="source_question_id" type="text" class="mt-1 block w-full" :value="old('source_question_id', $revision?->source_question_id)" />
        </div>
    </div>

    <div class="grid sm:grid-cols-2 gap-4">
        <div>
            <x-input-label for="valid_from" value="Gültig ab (optional)" />
            <x-text-input id="valid_from" name="valid_from" type="date" class="mt-1 block w-full" :value="old('valid_from', optional($revision?->valid_from)->format('Y-m-d'))" />
        </div>
        <div>
            <x-input-label for="valid_to" value="Gültig bis (optional)" />
            <x-text-input id="valid_to" name="valid_to" type="date" class="mt-1 block w-full" :value="old('valid_to', optional($revision?->valid_to)->format('Y-m-d'))" />
            <x-input-error :messages="$errors->get('valid_to')" class="mt-2" />
        </div>
    </div>

    <div class="pt-2 border-t border-slate-100 dark:border-slate-700" x-show="questionType === 'single_choice'" style="display: none">
        <p class="text-sm font-medium text-slate-600 dark:text-slate-300 mb-3">Antworten (die richtige markieren)</p>
        <div class="space-y-3">
            @foreach (['A', 'B', 'C', 'D'] as $key)
                <div class="flex items-center gap-3">
                    <input type="radio" id="correct_{{ $key }}" name="correct" value="{{ $key }}" @checked($singleCorrectKey === $key) class="shrink-0">
                    <label for="correct_{{ $key }}" class="w-5 text-sm text-slate-600 dark:text-slate-400 shrink-0">{{ $key }}</label>
                    <x-input-label for="answer_{{ $key }}" value="Antworttext {{ $key }}" class="sr-only" />
                    <x-text-input id="answer_{{ $key }}" name="answers[{{ $key }}]" type="text" class="block w-full" :value="$singleAnswerText($key)" />
                </div>
            @endforeach
        </div>
        <x-input-error :messages="$errors->get('answers')" class="mt-2" />
        <x-input-error :messages="$errors->get('correct')" class="mt-2" />
    </div>

    <div class="pt-2 border-t border-slate-100 dark:border-slate-700"
         x-data="{ multiAnswers: {{ json_encode($initialMultiAnswers, JSON_HEX_APOS | JSON_HEX_QUOT) }} }"
         x-show="questionType === 'multi_choice'" style="display: none">
        <p class="text-sm font-medium text-slate-600 dark:text-slate-300 mb-3">Antworten (alle richtigen markieren)</p>
        <div class="space-y-3">
            <template x-for="(answer, index) in multiAnswers" :key="index">
                <div class="flex items-center gap-3">
                    <input type="checkbox" :name="'multi_correct[]'" :value="index" :aria-label="'Antwort ' + (index + 1) + ' als richtig markieren'" x-model="answer.correct" class="shrink-0">
                    <input type="text" :name="'multi_answers['+index+'][text]'" :aria-label="'Antworttext ' + (index + 1)" x-model="answer.text"
                           class="block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                    <button type="button" @click="multiAnswers.splice(index, 1)" class="text-xs text-red-500 hover:underline whitespace-nowrap">Entfernen</button>
                </div>
            </template>
        </div>
        <button type="button" @click="multiAnswers.push({ text: '', correct: false })"
                class="mt-3 px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 text-sm font-medium hover:bg-slate-50 dark:hover:bg-slate-700 transition">
            + Antwort hinzufügen
        </button>
        <x-input-error :messages="$errors->get('multi_answers')" class="mt-2" />
        <x-input-error :messages="$errors->get('multi_correct')" class="mt-2" />
    </div>

    <div class="pt-2 border-t border-slate-100 dark:border-slate-700" x-show="questionType === 'open_text'" style="display: none">
        <x-input-label for="model_answer" value="Musterantwort (Rückseite der Lernkarte)" />
        <textarea id="model_answer" name="model_answer" rows="3"
                  class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ $modelAnswer }}</textarea>
        <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Dient nur als Lernkarte -- der Lerner schätzt selbst ein, ob er die Antwort wusste, fließt nicht automatisch in eine Auswertung ein.</p>
        <x-input-error :messages="$errors->get('model_answer')" class="mt-2" />
    </div>

    <div class="grid sm:grid-cols-2 gap-4 pt-2 border-t border-slate-100 dark:border-slate-700">
        <div>
            <x-input-label for="feedback_correct" value="Feedback bei richtiger Antwort (optional)" />
            <textarea id="feedback_correct" name="feedback_correct" rows="4"
                      class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ old('feedback_correct', $revision?->feedback_correct) }}</textarea>
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Wird unter „Richtig!" angezeigt.</p>
        </div>
        <div>
            <x-input-label for="feedback_incorrect" value="Feedback bei falscher Antwort (optional)" />
            <textarea id="feedback_incorrect" name="feedback_incorrect" rows="4"
                      class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ old('feedback_incorrect', $revision?->feedback_incorrect) }}</textarea>
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Wird unter „Leider falsch." angezeigt.</p>
        </div>
    </div>
</div>

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Prüfungssimulation</h1>
            <div class="text-sm text-slate-500 dark:text-slate-400 flex items-center gap-1.5">
                <x-icon name="compass" class="w-4 h-4 text-slate-600 dark:text-slate-400" />
                Navigationsaufgabe {{ $task?->task_number }}
                @if (! is_null($remainingSeconds))
                    <span class="text-slate-300">&middot;</span>
                    <x-icon name="clock" class="w-4 h-4 text-slate-600 dark:text-slate-400" />
                    <span id="exam-timer" data-remaining="{{ $remainingSeconds }}" aria-label="Verbleibende Prüfungszeit">{{ sprintf('%02d:%02d', intdiv($remainingSeconds, 60), $remainingSeconds % 60) }}</span>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="max-w-3xl mx-auto space-y-4">
        <div class="rounded-xl bg-sky-50 border border-sky-200 text-sky-900 dark:bg-sky-900/30 dark:border-sky-800 dark:text-sky-100 px-4 py-3 text-sm">
            Teil 2 der Prüfung: die Navigationsaufgabe. Bestanden ist sie mit mindestens {{ $rule['min_points'] }} von {{ $rule['of'] }} Punkten
            &ndash; unabhängig vom Fragebogen. Du brauchst die Übungsseekarte&nbsp;49 (BSH) mit Kursdreieck und Zirkel.
        </div>

        @if ($task)
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
                <h2 class="font-medium text-slate-800 dark:text-white mb-2">Navigationsaufgabe {{ $task->task_number }}</h2>
                <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">{{ $task->scenario_text }}</p>
                @if ($task->hint)
                    <p class="mt-2 text-xs text-slate-600 dark:text-slate-400">{{ $task->hint }}</p>
                @endif
            </div>
            @if ($task->chart_image_path)
                <figure class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-3">
                    <img src="{{ Storage::disk('public')->url($task->chart_image_path) }}" alt="Kartenausschnitt zu Aufgabe {{ $task->task_number }}" class="w-full rounded-xl" loading="lazy">
                    @if ($task->chart_caption)
                        <figcaption class="mt-2 text-xs text-slate-600 dark:text-slate-400">{{ $task->chart_caption }}</figcaption>
                    @endif
                </figure>
            @endif
        @endif

        @if ($step === 'answer')
            <form method="POST" action="{{ route('exam.navigation-part.submit', $examSession) }}" class="space-y-3"
                  x-data="{ busy: false }" @submit="if (busy || ! confirm('Navigationsaufgabe abgeben? Danach lassen sich die Antworten nicht mehr ändern.')) { $event.preventDefault() } else { busy = true }">
                @csrf
                @foreach ($answers as $answer)
                    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
                        <label for="nav-{{ $answer->id }}" class="block text-sm text-slate-700 dark:text-slate-200">
                            <span class="font-medium">Frage {{ $answer->question->question_number }}:</span> {{ $answer->question->question_text }}
                        </label>
                        <input id="nav-{{ $answer->id }}" name="answers[{{ $answer->navigation_question_id }}]" type="text" maxlength="500"
                               placeholder="{{ \App\Support\NavigationAnswer::placeholder($answer->question->answer_text) }}"
                               class="mt-2 w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100 text-sm">
                    </div>
                @endforeach
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-slate-600 dark:text-slate-400">Messwerte (Kurs, Peilung, Position, Distanz, Fahrt, Zeit) werden automatisch mit Toleranz geprüft; andere Antworten bewertest du nach der Abgabe selbst anhand der Musterlösung.</p>
                    <button type="submit" :disabled="busy" class="disabled:opacity-50 px-4 py-2 rounded-lg text-white text-sm font-medium" style="background-color: var(--brand-primary, #005FD7)">Navigationsaufgabe abgeben</button>
                </div>
            </form>
        @else
            <form method="POST" action="{{ route('exam.navigation-part.assess', $examSession) }}" class="space-y-3">
                @csrf
                <p class="text-sm text-slate-600 dark:text-slate-300">Diese Antworten lassen sich nicht automatisch prüfen. Vergleiche sie mit der Musterlösung und bewerte ehrlich &ndash; danach wird die Prüfung ausgewertet.</p>
                @foreach ($toAssess as $answer)
                    <fieldset class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
                        <legend class="sr-only">Frage {{ $answer->question->question_number }}</legend>
                        <p class="text-sm text-slate-700 dark:text-slate-200"><span class="font-medium">Frage {{ $answer->question->question_number }}:</span> {{ $answer->question->question_text }}</p>
                        <p class="mt-2 text-sm"><span class="text-slate-500 dark:text-slate-400">Deine Antwort:</span> {{ $answer->answer }}</p>
                        <p class="mt-1 text-sm rounded-lg bg-slate-50 dark:bg-slate-700/50 p-2"><span class="font-medium">Musterlösung:</span> {{ $answer->question->answer_text }}</p>
                        <div class="mt-2 flex flex-wrap gap-4 text-sm">
                            <label class="inline-flex items-center gap-2"><input type="radio" name="known[{{ $answer->id }}]" value="1" required> Richtig</label>
                            <label class="inline-flex items-center gap-2"><input type="radio" name="known[{{ $answer->id }}]" value="0"> Falsch</label>
                        </div>
                    </fieldset>
                @endforeach
                <div class="flex justify-end">
                    <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium" style="background-color: var(--brand-primary, #005FD7)">Prüfung auswerten</button>
                </div>
            </form>
        @endif

        <form method="POST" action="{{ route('exam.finish', $examSession) }}" class="text-center" x-data="{}"
              @submit="if (! confirm('Prüfung jetzt vorzeitig abgeben? Unbeantwortete Teile zählen als falsch.')) $event.preventDefault()">
            @csrf
            <button type="submit" class="text-xs text-slate-600 dark:text-slate-400 underline">Prüfung vorzeitig abgeben</button>
        </form>
    </div>
</x-app-layout>

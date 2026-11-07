<x-app-layout>
    <x-slot name="header">
        <div class="text-xs text-slate-600 dark:text-slate-400 flex items-center gap-1">
            <a href="{{ route('exam.intro', $course) }}" class="hover:underline">Prüfungssimulation &middot; {{ $course->name }}</a>
            <x-icon name="chevron-right" class="w-3 h-3" />
            <span>Navigationsaufgaben</span>
        </div>
        <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200 flex items-center gap-2">
            <x-icon name="compass" class="w-5 h-5 text-slate-600 dark:text-slate-400" /> Navigationsaufgaben
        </h1>
    </x-slot>

    <div class="max-w-3xl mx-auto space-y-4">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-3">
                Hier findest du alle {{ $tasks->count() }} Navigationsaufgaben für den Sportbootführerschein See als Übung
                mit direkt einsehbarer Musterlösung &mdash; anders als in der Prüfungssimulation gibt es hier sofortiges Feedback zum Selbststudium.
            </p>
            <div class="flex flex-wrap gap-2">
                @foreach ($tasks as $item)
                    @php($status = $taskStatus[$item->id] ?? null)
                    <a href="{{ route('exam.navigation.show', ['course' => $course, 'task' => $item]) }}"
                       title="Aufgabe {{ $item->task_number }}{{ $status === 'done' ? ' – alles richtig' : ($status === 'started' ? ' – begonnen' : '') }}"
                       class="w-9 h-9 rounded-lg flex items-center justify-center text-sm font-medium transition {{ $item->id === $task->id ? 'text-white' : match ($status) {
                           'done' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200 hover:bg-emerald-200',
                           'started' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200 hover:bg-amber-200',
                           default => 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-600',
                       } }}"
                       @style(["background-color: var(--brand-primary, #005FD7)" => $item->id === $task->id])>
                        {{ $item->task_number }}
                    </a>
                @endforeach
            </div>
            @php($doneCount = collect($taskStatus)->filter(fn ($s) => $s === 'done')->count())
            <p class="mt-3 text-xs text-slate-600 dark:text-slate-400 flex flex-wrap items-center gap-x-3 gap-y-1">
                <span>{{ $doneCount }} von {{ $tasks->count() }} Aufgaben vollständig richtig</span>
                <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded bg-emerald-200 dark:bg-emerald-800"></span> alles richtig</span>
                <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded bg-amber-200 dark:bg-amber-800"></span> begonnen</span>
            </p>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
            <h3 class="font-medium text-slate-800 dark:text-white mb-2">Navigationsaufgabe {{ $task->task_number }}</h3>
            <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">{{ $task->scenario_text }}</p>
            @if ($task->hint)
                <div class="mt-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-700 dark:bg-amber-900/30 dark:border-amber-800 dark:text-amber-300 px-3 py-2 text-xs flex items-start gap-2">
                    <x-icon name="shield-check" class="w-4 h-4 shrink-0 mt-0.5" />
                    <span>{{ $task->hint }}</span>
                </div>
            @endif
        </div>

        @if ($task->chart_image_path)
            <figure class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-3">
                <a href="{{ Storage::disk('public')->url($task->chart_image_path) }}" target="_blank" rel="noopener">
                    <img src="{{ Storage::disk('public')->url($task->chart_image_path) }}" alt="Kartenausschnitt zu Aufgabe {{ $task->task_number }}" class="w-full rounded-xl" loading="lazy">
                </a>
                @if ($task->chart_caption)
                    <figcaption class="mt-2 text-xs text-slate-600 dark:text-slate-400">{{ $task->chart_caption }}</figcaption>
                @endif
            </figure>
        @endif
        <div class="rounded-xl bg-sky-50 border border-sky-200 text-sky-800 dark:bg-sky-900/30 dark:border-sky-800 dark:text-sky-200 px-3 py-2 text-xs flex items-start gap-2">
            <x-icon name="map-pin" class="w-4 h-4 shrink-0 mt-0.5" />
            <span>Für die Aufgaben brauchst du die Übungsseekarte&nbsp;49 (BSH, „Deutsche Bucht“) mit Kursdreieck und Zirkel &ndash; erhältlich im nautischen Fachhandel oder bei deiner Bootsschule.</span>
        </div>

        <div class="space-y-2">
            @foreach ($task->questions as $question)
                @php($p = $progress->get($question->id))
                @php($auto = $autoCheck[$question->id] ?? false)
                @php($errorKey = 'answer_'.$question->id)
                <div id="frage-{{ $question->question_number }}" x-data="{ open: {{ $p ? 'true' : 'false' }} }" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden scroll-mt-20">
                    <div class="px-4 pt-3 flex items-center justify-between gap-3">
                        <span class="text-sm font-medium text-slate-700 dark:text-slate-200">Frage {{ $question->question_number }}</span>
                        @if ($p && $p->correct === true)
                            <span class="text-xs font-medium rounded-full px-2 py-0.5 bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">Richtig</span>
                        @elseif ($p && $p->correct === false)
                            <span class="text-xs font-medium rounded-full px-2 py-0.5 bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200">Falsch</span>
                        @elseif ($p)
                            <span class="text-xs font-medium rounded-full px-2 py-0.5 bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Bitte selbst bewerten</span>
                        @endif
                    </div>
                    <div class="px-4 pt-1 pb-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $question->question_text }}
                    </div>
                    <form method="POST" action="{{ route('exam.navigation.answer', ['course' => $course, 'task' => $task, 'question' => $question]) }}" class="px-4 pb-3 flex flex-wrap gap-2">
                        @csrf
                        <label for="answer-{{ $question->id }}" class="sr-only">Deine Antwort zu Frage {{ $question->question_number }}</label>
                        <input id="answer-{{ $question->id }}" name="answer" type="text" maxlength="500" value="{{ $p?->answer }}"
                               placeholder="{{ $auto ? \App\Support\NavigationAnswer::placeholder($question->answer_text) : 'Deine Antwort' }}"
                               class="w-full sm:w-auto sm:flex-1 min-w-0 rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100 text-sm">
                        <button type="submit" class="rounded-lg px-3 py-2 text-sm font-medium text-white" style="background-color: var(--brand-primary, #005FD7)">
                            {{ $auto ? 'Prüfen' : 'Speichern' }}
                        </button>
                        <button type="button" x-on:click="open = !open" class="rounded-lg px-3 py-2 text-sm font-medium bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-600"
                                x-text="open ? 'Lösung ausblenden' : 'Lösung zeigen'">Lösung zeigen</button>
                        @error($errorKey)
                            <p class="w-full text-xs text-red-700 dark:text-red-300">{{ $message }}</p>
                        @enderror
                    </form>
                    <div x-show="open" x-transition class="px-4 pb-4 space-y-2">
                        <div class="rounded-xl bg-slate-50 dark:bg-slate-700/50 border border-slate-100 dark:border-slate-700 p-3 text-sm text-slate-600 dark:text-slate-300">
                            <span class="font-medium text-slate-700 dark:text-slate-200">Musterlösung: </span>{{ $question->answer_text }}
                        </div>
                        @if ($auto)
                            <p class="text-xs text-slate-600 dark:text-slate-400">
                                Automatische Prüfung mit Toleranz: Kurse/Peilungen ±{{ (int) App\Support\NavigationAnswer::TOLERANCE['angle'] }}°, Positionen ±0,3', Distanzen ±0,2 sm, Fahrt ±0,3 kn, Zeiten ±3 min.
                                Stimmt dein Ergebnis trotzdem, kannst du dich selbst bewerten.
                            </p>
                        @endif
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="text-slate-600 dark:text-slate-400">Selbst bewerten:</span>
                            @foreach (['richtig' => 'Hatte ich richtig', 'falsch' => 'Hatte ich falsch'] as $value => $label)
                                <form method="POST" action="{{ route('exam.navigation.answer', ['course' => $course, 'task' => $task, 'question' => $question]) }}">
                                    @csrf
                                    <input type="hidden" name="self" value="{{ $value }}">
                                    <button type="submit" class="rounded-lg px-3 py-1.5 text-sm font-medium border {{ $value === 'richtig' ? 'border-emerald-300 text-emerald-800 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-200 dark:hover:bg-emerald-900/30' : 'border-red-300 text-red-800 hover:bg-red-50 dark:border-red-700 dark:text-red-200 dark:hover:bg-red-900/30' }}">{{ $label }}</button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <p class="text-xs text-slate-600 dark:text-slate-400">
            Quelle: ELWIS &mdash; Fragenkatalog See, Navigationsaufgaben.
        </p>
    </div>
</x-app-layout>

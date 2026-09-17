<x-app-layout>
    <x-slot name="header">
        <div class="text-xs text-slate-400 flex items-center gap-1">
            <a href="{{ route('exam.intro', $course) }}" class="hover:underline">Prüfungssimulation &middot; {{ $course->name }}</a>
            <x-icon name="chevron-right" class="w-3 h-3" />
            <span>Navigationsaufgaben</span>
        </div>
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200 flex items-center gap-2">
            <x-icon name="compass" class="w-5 h-5 text-slate-400" /> Navigationsaufgaben
        </h2>
    </x-slot>

    <div class="max-w-3xl mx-auto space-y-4">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-3">
                Hier findest Du alle {{ $tasks->count() }} Navigationsaufgaben für den Sportbootführerschein See als Übung
                mit direkt einsehbarer Musterlösung &mdash; anders als in der Prüfungssimulation gibt es hier sofortes Feedback zum Selbststudium.
            </p>
            <div class="flex flex-wrap gap-2">
                @foreach ($tasks as $item)
                    <a href="{{ route('exam.navigation.show', ['course' => $course, 'task' => $item]) }}"
                       class="w-9 h-9 rounded-lg flex items-center justify-center text-sm font-medium transition {{ $item->id === $task->id ? 'text-white' : 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-600' }}"
                       @style(["background-color: var(--brand-primary, #005FD7)" => $item->id === $task->id])>
                        {{ $item->task_number }}
                    </a>
                @endforeach
            </div>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
            <h3 class="font-medium text-slate-800 dark:text-white mb-2">Navigationsaufgabe {{ $task->task_number }}</h3>
            <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">{{ $task->scenario_text }}</p>
        </div>

        <div class="space-y-2">
            @foreach ($task->questions as $question)
                <div x-data="{ open: false }" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
                    <button type="button" x-on:click="open = !open"
                            class="w-full flex items-center justify-between gap-3 px-4 py-3 text-left">
                        <span class="text-sm font-medium text-slate-700 dark:text-slate-200">Frage {{ $question->question_number }}</span>
                        <x-icon name="chevron-right" class="w-4 h-4 text-slate-400 shrink-0 transition-transform" x-bind:class="open ? 'rotate-90' : ''" />
                    </button>
                    <div class="px-4 pb-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $question->question_text }}
                    </div>
                    <div x-show="open" x-transition class="px-4 pb-4">
                        <div class="rounded-xl bg-slate-50 dark:bg-slate-700/50 border border-slate-100 dark:border-slate-700 p-3 text-sm text-slate-600 dark:text-slate-300">
                            <span class="font-medium text-slate-700 dark:text-slate-200">Musterlösung: </span>{{ $question->answer_text }}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <p class="text-xs text-slate-400">
            Hinweis: Szenarien und Musterlösungen sind Demo-Platzhalter und ersetzen keine amtlichen Navigationsaufgaben &mdash;
            vor Produktivbetrieb durch fachlich geprüftes Material der Bootsschule ersetzen.
        </p>
    </div>
</x-app-layout>

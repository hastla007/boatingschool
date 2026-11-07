<x-superadmin-layout>
    <x-slot name="header">
        <div>
            <div class="text-xs text-slate-600 dark:text-slate-400"><a href="{{ route('superadmin.navigation.index', $task->course) }}" class="hover:underline">Navigationsaufgaben &middot; {{ $task->course->name }}</a></div>
            <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Aufgabe {{ $task->task_number }} bearbeiten</h1>
        </div>
    </x-slot>

    @php
        $input = 'mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm';
        $rows = $task->questions->map(fn ($q) => ['id' => $q->id, 'question_text' => $q->question_text, 'answer_text' => $q->answer_text])->all();
        $rows[] = ['id' => null, 'question_text' => '', 'answer_text' => ''];
    @endphp
    <form method="POST" action="{{ route('superadmin.navigation.tasks.update', $task) }}" class="max-w-4xl bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 space-y-5">
        @csrf
        @method('PATCH')
        @if ($errors->any())
            <p class="text-sm text-red-700 dark:text-red-300">{{ $errors->first() }}</p>
        @endif
        <div>
            <x-input-label for="scenario_text" value="Szenario" />
            <textarea id="scenario_text" name="scenario_text" rows="5" required class="{{ $input }}">{{ old('scenario_text', $task->scenario_text) }}</textarea>
        </div>
        <div>
            <x-input-label for="hint" value="Hinweis (optional)" />
            <textarea id="hint" name="hint" rows="2" class="{{ $input }}">{{ old('hint', $task->hint) }}</textarea>
        </div>

        <fieldset class="space-y-3">
            <legend class="text-sm font-medium text-slate-700 dark:text-slate-200">Teilfragen</legend>
            @foreach ($rows as $i => $row)
                <div class="border border-slate-100 dark:border-slate-700 rounded-xl p-3 space-y-2">
                    <div class="flex items-center justify-between text-xs text-slate-600 dark:text-slate-400">
                        <span>{{ $row['id'] ? 'Frage '.($i + 1) : 'Neue Teilfrage (optional)' }}</span>
                        @if ($row['id'])
                            <label class="flex items-center gap-1"><input type="checkbox" name="questions[{{ $i }}][delete]" value="1" class="rounded border-slate-300"> entfernen</label>
                            <input type="hidden" name="questions[{{ $i }}][id]" value="{{ $row['id'] }}">
                        @endif
                    </div>
                    <label for="q-{{ $i }}" class="sr-only">Frage</label>
                    <textarea id="q-{{ $i }}" name="questions[{{ $i }}][question_text]" rows="2" placeholder="Frage" class="block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ $row['question_text'] }}</textarea>
                    <label for="a-{{ $i }}" class="sr-only">Musterlösung</label>
                    <input id="a-{{ $i }}" name="questions[{{ $i }}][answer_text]" value="{{ $row['answer_text'] }}" placeholder="Musterlösung, z. B. rwK = 079°" class="block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                </div>
            @endforeach
            <p class="text-xs text-slate-600 dark:text-slate-400">Lösungen nur aus Messwerten (z. B. „rwK = 079°“, „6,1 sm“, „15:12 Uhr“) prüft die App automatisch mit Toleranz; alle anderen bewerten Teilnehmer selbst. Entfernte Teilfragen löschen den Lernstand dazu.</p>
        </fieldset>

        <div class="flex flex-wrap items-center justify-between gap-3 pt-2 border-t border-slate-100 dark:border-slate-700">
            @if ($copies->isNotEmpty())
                <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                    <input type="checkbox" name="apply_to_copies" value="1" checked class="rounded border-slate-300">
                    Auch in {{ $copies->count() }} Kopie(n) übernehmen ({{ $copies->pluck('course.name')->filter()->implode(', ') }})
                </label>
            @else
                <span></span>
            @endif
            <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90">Speichern</button>
        </div>
    </form>
</x-superadmin-layout>

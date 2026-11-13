<x-superadmin-layout>
    <x-slot name="header">
        <div>
            <div class="text-xs text-slate-600 dark:text-slate-400"><a href="{{ route('superadmin.courses.show', $course) }}" class="hover:underline">{{ $course->name }}</a></div>
            <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Navigationsaufgaben</h1>
        </div>
    </x-slot>

    <div class="space-y-4">
        <div class="rounded-xl bg-amber-50 border border-amber-200 text-amber-800 dark:bg-amber-900/30 dark:border-amber-800 dark:text-amber-200 px-4 py-3 text-sm">
            Die Übungsseekarte 49 des BSH ist urheberrechtlich geschützt. Bitte nur Ausschnitte hochladen, für die eine Nutzungserlaubnis vorliegt,
            und Quelle/Lizenz in der Bildunterschrift nennen. Ohne Ausschnitt sehen Teilnehmer nur den Hinweis, dass sie die Karte im Fachhandel bekommen.
        </div>

        @foreach ($tasks as $task)
            <div id="aufgabe-{{ $task->task_number }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 scroll-mt-20">
                <div class="flex items-start justify-between gap-3 mb-2">
                    <h3 class="font-medium text-slate-700 dark:text-slate-200">Aufgabe {{ $task->task_number }}</h3>
                    <span class="flex items-center gap-3 shrink-0">
                        <span class="text-xs text-slate-600 dark:text-slate-400">{{ $task->questions_count }} Teilfragen</span>
                        <a href="{{ route('superadmin.navigation.tasks.edit', $task) }}" class="text-xs font-medium text-slate-700 dark:text-slate-200 underline hover:no-underline">Texte bearbeiten</a>
                    </span>
                </div>
                <p class="text-sm text-slate-600 dark:text-slate-300 mb-3 line-clamp-3">{{ $task->scenario_text }}</p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        @if ($task->chart_image_path)
                            <img src="{{ \App\Support\NavigationChart::url($task->chart_image_path) }}" alt="Kartenausschnitt zu Aufgabe {{ $task->task_number }}" class="w-full rounded-xl border border-slate-100 dark:border-slate-700">
                        @else
                            <div class="h-28 rounded-xl border border-dashed border-slate-300 dark:border-slate-600 flex items-center justify-center text-xs text-slate-600 dark:text-slate-400">Kein Kartenausschnitt</div>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('superadmin.navigation.update', $task) }}" enctype="multipart/form-data" class="md:col-span-2 space-y-3">
                        @csrf
                        @method('PATCH')
                        <div>
                            <x-input-label for="chart-{{ $task->id }}" value="{{ $task->chart_image_path ? 'Neues Bild (optional)' : 'Bild (PNG, JPG oder WebP, max. 8 MB)' }}" />
                            <input id="chart-{{ $task->id }}" name="chart" type="file" accept="image/png,image/jpeg,image/webp" class="mt-1 block w-full text-sm">
                        </div>
                        <div>
                            <x-input-label for="caption-{{ $task->id }}" value="Bildunterschrift (Quelle, Lizenz)" />
                            <x-text-input id="caption-{{ $task->id }}" name="chart_caption" type="text" maxlength="500" class="mt-1 block w-full" :value="$task->chart_caption" />
                        </div>
                        @if ($errors->any() && old('_task') === $task->id)
                            <p class="text-xs text-red-700 dark:text-red-300">{{ $errors->first() }}</p>
                        @endif
                        <input type="hidden" name="_task" value="{{ $task->id }}">
                        <div class="flex items-center gap-3">
                            <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90 transition">Speichern</button>
                        </div>
                    </form>
                </div>
                @if ($task->chart_image_path)
                    <form method="POST" action="{{ route('superadmin.navigation.destroy', $task) }}" class="mt-3" x-data="{}"
                          @submit="if (! confirm('Kartenausschnitt wirklich entfernen?')) $event.preventDefault()">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-xs text-rose-600 hover:underline">Kartenausschnitt entfernen</button>
                    </form>
                @endif
            </div>
        @endforeach
    </div>
</x-superadmin-layout>

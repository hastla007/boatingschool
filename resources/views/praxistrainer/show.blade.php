<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-xs text-slate-400 flex items-center gap-1">
                    <a href="{{ route('courses.show', $course) }}" class="hover:underline">{{ $course->name }}</a>
                    <x-icon name="chevron-right" class="w-3 h-3" />
                    <span>Praxistrainer</span>
                    @if ($kategorie)
                        <x-icon name="chevron-right" class="w-3 h-3" />
                        <span>{{ $kategorie }}</span>
                    @endif
                </div>
                <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">{{ $task->unterkategorie ?? $task->kategorie }}</h2>
            </div>
            <div class="text-sm text-slate-500">{{ $completedCount }} / {{ $totalCount }} Aufgaben &middot; {{ $percent }}%</div>
        </div>
    </x-slot>

    <div class="grid lg:grid-cols-[280px_1fr] gap-4 items-start">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-3 lg:sticky lg:top-4">
            <div class="flex items-center gap-2 px-1 mb-2">
                <a href="{{ route('courses.show', $course) }}" class="w-7 h-7 rounded-full flex items-center justify-center text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700 shrink-0">
                    <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
                </a>
                <span class="text-xs font-semibold text-slate-500 dark:text-slate-300 uppercase tracking-wide">{{ $kategorie ?? 'Praxistrainer' }}</span>
            </div>
            <div class="w-full bg-slate-100 dark:bg-slate-700 rounded-full h-1.5 mb-1 mx-1" style="width: calc(100% - 0.5rem)">
                <div class="h-1.5 rounded-full" style="width: {{ $percent }}%; background-color: var(--brand-primary, #005FD7)"></div>
            </div>
            <div class="text-right text-xs text-slate-400 px-1 mb-3">{{ $percent }}%</div>

            <div class="space-y-0.5 max-h-[65vh] overflow-y-auto pr-1">
                @foreach ($tasks as $item)
                    @php($isCompleted = $progress[$item->id]->completed ?? false)
                    @php($isCurrent = $item->id === $task->id)
                    <a href="{{ route('praxistrainer.show', array_filter(['course' => $course, 'task' => $item, 'kategorie' => $kategorie])) }}"
                       class="flex items-center gap-2 px-2 py-1.5 rounded-lg text-sm transition {{ $isCurrent ? 'bg-slate-100 dark:bg-slate-700 font-medium text-slate-800 dark:text-white' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700/50' }}">
                        @if ($isCompleted)
                            <x-icon name="check-circle" class="w-3.5 h-3.5 text-emerald-500 shrink-0" />
                        @else
                            <span class="w-3.5 h-3.5 rounded-full border-2 border-slate-300 dark:border-slate-600 shrink-0"></span>
                        @endif
                        <span class="flex-1 truncate">{{ $item->unterkategorie ?? $item->frage }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6" x-data="{ revealed: false }">
            <p class="text-lg text-slate-800 dark:text-white mb-4">{{ $task->frage }}</p>

            @if ($task->media && ! $task->imageBelongsToSolution())
                <img src="{{ $task->media->storage_path }}" alt="{{ $task->media->alt_text ?? $task->unterkategorie }}" class="rounded-xl mb-4 max-h-80 mx-auto">
            @endif

            <div x-show="!revealed">
                <button type="button" x-on:click="revealed = true" class="inline-flex items-center gap-1.5 px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                    Musterlösung anzeigen
                </button>
            </div>

            <div x-show="revealed" x-transition class="space-y-3">
                @if ($task->media && $task->imageBelongsToSolution())
                    <img src="{{ $task->media->storage_path }}" alt="{{ $task->media->alt_text ?? $task->unterkategorie }}" class="rounded-xl mb-1 max-h-80 mx-auto">
                @endif
                <div class="rounded-xl p-4 bg-emerald-50 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-200">
                    <div class="text-xs font-semibold uppercase tracking-wide mb-1">Musterlösung</div>
                    {{ $task->antwort }}
                </div>
                @if ($task->erklaerung)
                    <div class="rounded-xl p-4 bg-slate-50 dark:bg-slate-700/50 text-slate-600 dark:text-slate-300 text-sm">
                        {{ $task->erklaerung }}
                    </div>
                @endif
                @if ($task->quelle)
                    <p class="text-xs text-slate-400">Quelle: {{ $task->quelle }}</p>
                @endif

                <div class="flex items-center justify-end pt-2">
                    <form method="POST" action="{{ route('praxistrainer.complete', array_filter(['course' => $course, 'task' => $task, 'kategorie' => $kategorie])) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center gap-1.5 px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                            {{ $next ? 'Gemeistert & weiter' : 'Gemeistert & abschließen' }} <x-icon name="arrow-right" class="w-4 h-4" />
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

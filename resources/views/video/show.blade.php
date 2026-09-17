<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-xs text-slate-400 flex items-center gap-1">
                    <a href="{{ route('courses.show', $course) }}" class="hover:underline">{{ $course->name }}</a>
                    <x-icon name="chevron-right" class="w-3 h-3" />
                    <span>Videokurs</span>
                </div>
                <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">{{ $lesson->module->title }}</h2>
            </div>
            <div class="text-sm text-slate-500">{{ $completedCount }} / {{ $totalCount }} Lektionen &middot; {{ $percent }}%</div>
        </div>
    </x-slot>

    <div class="grid lg:grid-cols-[280px_1fr] gap-4 items-start">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-3 lg:sticky lg:top-4">
            <div class="w-full bg-slate-100 dark:bg-slate-700 rounded-full h-1.5 mb-3">
                <div class="h-1.5 rounded-full" style="width: {{ $percent }}%; background-color: var(--brand-primary, #005FD7)"></div>
            </div>
            <div class="space-y-4 max-h-[70vh] overflow-y-auto pr-1">
                @foreach ($lessons->groupBy(fn ($l) => $l->module->id) as $group)
                    @php($module = $group->first()->module)
                    <div>
                        <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide px-2 mb-1">{{ $module->title }}</div>
                        <div class="space-y-0.5">
                            @foreach ($group as $item)
                                @php($isCompleted = $progress[$item->id]->completed ?? false)
                                @php($isCurrent = $item->id === $lesson->id)
                                <a href="{{ route('video.show', ['course' => $course, 'lesson' => $item]) }}"
                                   class="flex items-center gap-2 px-2 py-1.5 rounded-lg text-sm transition {{ $isCurrent ? 'bg-slate-100 dark:bg-slate-700 font-medium text-slate-800 dark:text-white' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700/50' }}">
                                    @if ($isCompleted)
                                        <x-icon name="check-circle" class="w-4 h-4 text-emerald-500 shrink-0" />
                                    @else
                                        <span class="w-4 h-4 rounded-full border-2 border-slate-300 dark:border-slate-600 shrink-0"></span>
                                    @endif
                                    <span class="flex-1 truncate">{{ $item->title }}</span>
                                    @if ($item->duration_seconds)
                                        <span class="text-xs text-slate-400 shrink-0">{{ $item->formattedDuration() }}</span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
            <h3 class="font-medium text-slate-800 dark:text-white mb-3">{{ $lesson->title }}</h3>
            <div class="rounded-xl overflow-hidden bg-slate-900 aspect-video">
                <video controls class="w-full h-full" preload="metadata">
                    <source src="{{ $lesson->video_url }}" type="video/mp4">
                </video>
            </div>

            <div class="flex items-center justify-between mt-4">
                <span class="text-xs text-slate-400">
                    @if ($progress[$lesson->id]->completed ?? false)
                        <span class="inline-flex items-center gap-1 text-emerald-600"><x-icon name="check-circle" class="w-4 h-4" /> Abgeschlossen</span>
                    @else
                        Noch nicht abgeschlossen
                    @endif
                </span>
                <form method="POST" action="{{ route('video.complete', ['course' => $course, 'lesson' => $lesson]) }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-1.5 px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                        {{ $next ? 'Nächstes Video' : 'Kurs abschließen' }} <x-icon name="arrow-right" class="w-4 h-4" />
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>

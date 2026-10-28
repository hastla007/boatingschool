<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-1 text-sm text-slate-500 dark:text-slate-400">
            <a href="{{ route('courses.index') }}" class="hover:underline">Kurse</a>
            <x-icon name="chevron-right" class="w-3.5 h-3.5 text-slate-600 dark:text-slate-400" />
            <a href="{{ route('courses.show', $course) }}" class="hover:underline">{{ $course->name }}</a>
        </div>
    </x-slot>

    {{-- Bug-Review (Mobil-Overflow Video/Praxistrainer): ohne explizite
         grid-cols-1 blieb die Spaltenbreite unterhalb von lg: content-based
         (shrink-to-fit) -- das Poster-Platzhalterbild (800x450, siehe
         VideoCourseController::posterUri()) zog die Spalte und damit die
         ganze Seite bei 360px auf 800px+ breit, bevor das echte Video-
         Element seine Metadaten geladen hatte. Der U8-Fix oben betraf nur
         die beiden Flex-Zeilen, nicht diese Grid-Spaltenbreite. --}}
    <div class="grid grid-cols-1 lg:grid-cols-[280px_1fr] gap-4 items-start">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-3 lg:sticky lg:top-4">
            <div class="flex items-center gap-2 px-1 mb-2">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0" style="background-color: color-mix(in srgb, var(--brand-primary, #005FD7) 12%, white)">
                    <x-icon name="book-open" class="w-4 h-4" style="color: var(--brand-primary, #005FD7)" />
                </div>
                <span class="flex-1 font-semibold text-slate-800 dark:text-white truncate">{{ $sectionLabel }}</span>
                {{-- Bug-Review (Zurück-Pfeile ohne Beschriftung): nur ein Icon
                     ohne Text -- ohne aria-label hatte dieser Link keinen für
                     Screenreader erkennbaren Namen. --}}
                <a href="{{ route('courses.show', $course) }}" aria-label="Zurück zum Kurs" class="w-7 h-7 rounded-full flex items-center justify-center text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700 shrink-0">
                    <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
                </a>
            </div>
            <div class="w-full bg-slate-100 dark:bg-slate-700 rounded-full h-1.5 mb-1 mx-1" style="width: calc(100% - 0.5rem)">
                <div class="h-1.5 rounded-full" style="width: {{ $percent }}%; background-color: var(--brand-primary, #005FD7)"></div>
            </div>
            <div class="flex items-center justify-between text-xs text-slate-600 dark:text-slate-400 px-1 mb-3">
                <span>{{ $completedCount }} / {{ $totalCount }} Lektionen</span>
                <span>{{ $percent }}%</span>
            </div>

            <div class="space-y-1 max-h-[65vh] overflow-y-auto pr-1">
                @foreach ($lessons->groupBy(fn ($l) => $l->module->id) as $group)
                    @php($module = $group->first()->module)
                    @php($isCurrentModule = $group->contains('id', $lesson->id))
                    @php($moduleCompleted = $group->every(fn ($l) => $progress[$l->id]->completed ?? false))
                    <div x-data="{ open: {{ $isCurrentModule ? 'true' : 'false' }} }" class="rounded-lg">
                        <button type="button" x-on:click="open = !open"
                                class="w-full flex items-center gap-2 px-2 py-2 rounded-lg text-left hover:bg-slate-50 dark:hover:bg-slate-700/50 transition">
                            @if ($moduleCompleted)
                                <x-icon name="check-circle" class="w-4 h-4 text-emerald-500 shrink-0" />
                            @else
                                <span class="w-4 h-4 rounded-full border-2 border-slate-300 dark:border-slate-600 shrink-0"></span>
                            @endif
                            <span class="flex-1 text-sm font-medium text-slate-700 dark:text-slate-200 truncate">{{ $module->title }}</span>
                            <x-icon name="chevron-right" class="w-3.5 h-3.5 text-slate-600 dark:text-slate-400 shrink-0 transition-transform" x-bind:class="open ? 'rotate-90' : ''" />
                        </button>
                        <div x-show="open" x-transition class="space-y-0.5 pl-6 pb-1">
                            @foreach ($group as $item)
                                @php($isCompleted = $progress[$item->id]->completed ?? false)
                                @php($isCurrent = $item->id === $lesson->id)
                                <a href="{{ route('video.show', array_filter(['course' => $course, 'lesson' => $item, 'kapitel' => $moduleIds ? implode(',', $moduleIds) : null])) }}"
                                   class="flex items-center gap-2 px-2 py-1.5 rounded-lg text-sm transition {{ $isCurrent ? 'bg-slate-100 dark:bg-slate-700 font-medium text-slate-800 dark:text-white' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700/50' }}">
                                    @if ($isCompleted)
                                        <x-icon name="check-circle" class="w-3.5 h-3.5 text-emerald-500 shrink-0" />
                                    @else
                                        <span class="w-3.5 h-3.5 rounded-full border-2 border-slate-300 dark:border-slate-600 shrink-0"></span>
                                    @endif
                                    <span class="flex-1 truncate">{{ $item->title }}</span>
                                    @if ($item->duration_seconds)
                                        <span class="text-xs text-slate-600 dark:text-slate-400 shrink-0">{{ $item->formattedDuration() }}</span>
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
                <video controls class="w-full h-full" preload="metadata" poster="{{ $posterUri }}">
                    <source src="{{ $lesson->video_url }}" type="video/mp4">
                </video>
            </div>

            {{-- Bug-Review Oberfläche U8: flex items-center justify-between
                 ohne flex-wrap zwang den Status-Text und den CTA-Button auf
                 dieselbe Zeile -- bei 360px Breite reichte dafür bei
                 "Zu den Fragen (N)" der Platz nicht, die Seite lief
                 horizontal über. Unter sm:: stapelt der Button jetzt unter
                 den Status-Text statt danebenzudrängen. --}}
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mt-4">
                <span class="text-xs text-slate-600 dark:text-slate-400">
                    @if ($progress[$lesson->id]->completed ?? false)
                        <span class="inline-flex items-center gap-1 text-emerald-600"><x-icon name="check-circle" class="w-4 h-4" /> Abgeschlossen</span>
                    @else
                        Noch nicht abgeschlossen
                    @endif
                </span>
                @if ($lesson->questions->isNotEmpty())
                    <a href="{{ route('video.quiz.show', array_filter(['course' => $course, 'lesson' => $lesson, 'position' => 1, 'kapitel' => $moduleIds ? implode(',', $moduleIds) : null])) }}"
                       class="inline-flex items-center gap-1.5 px-5 py-2 rounded-full text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-secondary, #007A7A)">
                        Zu den Fragen ({{ $lesson->questions->count() }}) <x-icon name="arrow-right" class="w-4 h-4" />
                    </a>
                @else
                    <form method="POST" action="{{ route('video.complete', array_filter(['course' => $course, 'lesson' => $lesson, 'kapitel' => $moduleIds ? implode(',', $moduleIds) : null])) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center gap-1.5 px-5 py-2 rounded-full text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-secondary, #007A7A)">
                            {{ $next ? 'Nächstes Video' : 'Kurs abschließen' }} <x-icon name="arrow-right" class="w-4 h-4" />
                        </button>
                    </form>
                @endif
            </div>

            @if ($lesson->steps->isNotEmpty())
                <div class="mt-6 pt-6 border-t border-slate-100 dark:border-slate-700">
                    <h4 class="font-medium text-slate-800 dark:text-white mb-3">{{ $lesson->title }} &middot; Schritt für Schritt</h4>
                    <div class="flex gap-3 overflow-x-auto pb-1">
                        @foreach ($lesson->steps as $step)
                            <div class="shrink-0 w-32 rounded-xl bg-slate-50 dark:bg-slate-700/50 border border-slate-100 dark:border-slate-700 p-3 text-center">
                                <div class="w-full aspect-square rounded-lg bg-slate-100 dark:bg-slate-700 flex items-center justify-center mb-2 text-slate-600 dark:text-slate-400">
                                    <span class="text-lg font-semibold">{{ $loop->iteration }}</span>
                                </div>
                                <div class="text-xs text-slate-600 dark:text-slate-300 leading-snug">{{ $step->title }}</div>
                            </div>
                        @endforeach
                    </div>
                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-2">Platzhalter-Schritte ohne echtes Bildmaterial &mdash; vor Produktivbetrieb durch Aufnahmen der Bootsschule ersetzen.</p>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>

<x-superadmin-layout>
    <x-slot name="header">
        <div>
            <div class="text-xs text-slate-600 dark:text-slate-400 flex items-center gap-1">
                <a href="{{ route('superadmin.courses.index') }}" class="hover:underline">Kurse</a>
                <x-icon name="chevron-right" class="w-3 h-3" />
                <a href="{{ route('superadmin.courses.show', $lesson->module->course) }}" class="hover:underline">{{ $lesson->module->course->name }}</a>
                <x-icon name="chevron-right" class="w-3 h-3" />
                <span>{{ $lesson->module->title }}</span>
            </div>
            <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">{{ $lesson->title }}</h1>
        </div>
    </x-slot>

    <div class="grid lg:grid-cols-2 gap-4 mb-4">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
            <div class="flex items-center justify-between gap-3 mb-3">
                <h3 class="font-medium text-slate-700 dark:text-slate-200">Video</h3>
                @if ($lesson->isPlaceholder())
                    <span class="text-xs px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">Platzhaltervideo</span>
                @endif
            </div>
            <form method="POST" action="{{ route('superadmin.video-lessons.update', $lesson) }}" class="space-y-3">
                @csrf
                @method('PATCH')
                <div>
                    <x-input-label for="title" value="Titel" />
                    <x-text-input id="title" name="title" type="text" class="mt-1 block w-full" :value="old('title', $lesson->title)" required maxlength="200" />
                    <x-input-error :messages="$errors->get('title')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="video_url" value="Video-URL (https, direkt abspielbare Datei, z. B. MP4)" />
                    <x-text-input id="video_url" name="video_url" type="url" class="mt-1 block w-full" :value="old('video_url', $lesson->video_url)" required maxlength="2048" placeholder="https://" />
                    <x-input-error :messages="$errors->get('video_url')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="duration" value="Dauer (Minuten:Sekunden, optional)" />
                    <x-text-input id="duration" name="duration" type="text" class="mt-1 block w-32" :value="old('duration', $lesson->formattedDuration())" placeholder="4:30" />
                    <x-input-error :messages="$errors->get('duration')" class="mt-1" />
                </div>
                @if ($copies->isNotEmpty())
                    <div class="pt-2 border-t border-slate-100 dark:border-slate-700">
                        <label class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                            <input type="checkbox" name="apply_to_copies" value="1" @checked(old('apply_to_copies')) class="rounded border-slate-300 mt-0.5">
                            <span>Video-URL und Dauer auch in {{ $copies->count() }} {{ $copies->count() === 1 ? 'Kopie' : 'Kopien' }} dieser Lektion übernehmen</span>
                        </label>
                        <ul class="text-xs text-slate-600 dark:text-slate-400 mt-1 ml-6 list-disc">
                            @foreach ($copies as $copy)
                                <li>{{ $copy->module->course->name }} · {{ $copy->module->title }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90 transition">Speichern</button>
            </form>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Vorschau</h3>
            <div class="rounded-xl overflow-hidden bg-slate-900 aspect-video">
                <video controls class="w-full h-full" preload="metadata">
                    <source src="{{ $lesson->video_url }}" type="video/mp4">
                </video>
            </div>
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-2 break-all">{{ $lesson->video_url }}</p>
        </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-4">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Fragen zu diesem Video</h3>
            <p class="text-xs text-slate-600 dark:text-slate-400 mb-3">Diese Fragen erscheinen im Kurs unter "Zu den Fragen" nach dem Video.</p>

            <div class="space-y-2">
                @forelse ($lesson->questions as $question)
                    @php($revision = $question->revisions->firstWhere('editorial_status', 'published') ?? $question->revisions->first())
                    <div class="flex items-center justify-between gap-3 border border-slate-100 dark:border-slate-700 rounded-xl px-3 py-2 text-sm">
                        <span class="text-slate-700 dark:text-slate-200">{{ $revision?->question_text ?? $question->content_id }}</span>
                        <form method="POST" action="{{ route('superadmin.video-lessons.questions.detach', [$lesson, $question]) }}" class="shrink-0">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs text-rose-500 hover:underline">Entfernen</button>
                        </form>
                    </div>
                @empty
                    <p class="text-slate-500 text-sm">Noch keine Fragen zugeordnet.</p>
                @endforelse
            </div>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Frage aus der Fragenbasis hinzufügen</h3>
            <form method="GET" action="{{ route('superadmin.video-lessons.show', $lesson) }}" class="flex gap-2 mb-4">
                <label for="video_lesson_question_search" class="sr-only">Fragetext durchsuchen</label>
                <input type="text" id="video_lesson_question_search" name="q" value="{{ $query }}" placeholder="Fragetext durchsuchen &hellip;"
                       class="flex-1 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                <button type="submit" class="px-3 py-2 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 transition text-sm shrink-0">Suchen</button>
            </form>

            @if ($query !== '')
                <div class="space-y-2">
                    @forelse ($results as $revision)
                        <div class="flex items-center justify-between gap-3 border border-slate-100 dark:border-slate-700 rounded-xl px-3 py-2 text-sm">
                            <span class="text-slate-700 dark:text-slate-200">{{ $revision->question_text }}</span>
                            <form method="POST" action="{{ route('superadmin.video-lessons.questions.attach', $lesson) }}" class="shrink-0">
                                @csrf
                                <input type="hidden" name="question_id" value="{{ $revision->question_id }}">
                                <button type="submit" class="text-xs hover:underline" style="color: var(--brand-primary, #005FD7)">Hinzufügen</button>
                            </form>
                        </div>
                    @empty
                        <p class="text-slate-500 text-sm">Keine veröffentlichten Fragen gefunden.</p>
                    @endforelse
                </div>
            @endif
        </div>
    </div>
</x-superadmin-layout>

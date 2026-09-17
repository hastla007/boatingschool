<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <div class="text-xs text-slate-400 flex items-center gap-1">
                    <a href="{{ route('courses.show', $course) }}" class="hover:underline">{{ $course->name }}</a>
                    @if ($revision->topic)
                        <x-icon name="chevron-right" class="w-3 h-3" />
                        <span>{{ $revision->topic }}</span>
                    @endif
                </div>
                <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">{{ $revision->subtopic ?? $revision->topic ?? 'Frage' }}</h2>
            </div>
            <div class="flex flex-wrap gap-1 text-xs">
                @foreach (['smarttrainer' => ['Smarttrainer', 'bolt'], 'new' => ['Neu', 'star'], 'wrong' => ['Falsch', 'x-circle'], 'favorites' => ['Favoriten', 'star']] as $key => [$label, $icon])
                    <a href="{{ route('learning.show', $course) }}?mode={{ $key }}{{ $moduleId ? '&module='.$moduleId : '' }}"
                       class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg {{ $mode === $key ? 'text-white' : 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-200' }}"
                       @style(["background-color: var(--brand-primary, #005FD7)" => $mode === $key])>
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>
    </x-slot>

    <div class="max-w-2xl mx-auto bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6">
        @if ($reason)
            <div class="inline-flex items-center gap-1.5 text-xs text-slate-400 mb-3 bg-slate-50 dark:bg-slate-700/50 rounded-full px-3 py-1">
                <x-icon name="bolt" class="w-3.5 h-3.5" /> Ausgewählt vom Smarttrainer: {{ $reason }}
            </div>
        @endif

        @foreach ($revision->media as $media)
            <img src="{{ $media->storage_path }}" alt="{{ $media->alt_text }}" class="rounded-lg mb-4 max-h-64 mx-auto">
        @endforeach

        <h3 class="text-lg font-medium text-slate-800 dark:text-white mb-4">{{ $revision->question_text }}</h3>

        <form method="POST" action="{{ route('learning.attempts.store', ['course' => $course, 'topic' => $topic, 'module' => $moduleId]) }}" x-data="{ selected: {{ $selectedAnswerId ? "'{$selectedAnswerId}'" : 'null' }} }">
            @csrf
            <input type="hidden" name="revision_id" value="{{ $revision->id }}">
            <input type="hidden" name="mode" value="{{ $mode }}">
            <input type="hidden" name="response_time_ms" x-ref="responseTime" value="0">

            <div class="space-y-2">
                @foreach ($revision->answers as $answer)
                    @php
                        $state = 'border-slate-200 dark:border-slate-600 hover:border-slate-400';
                        $showCorrectIcon = false;
                        $showWrongIcon = false;
                        if ($answered) {
                            if ($answer->is_correct) {
                                $state = 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/30';
                                $showCorrectIcon = true;
                            } elseif ($answer->id === $selectedAnswerId) {
                                $state = 'border-rose-500 bg-rose-50 dark:bg-rose-900/30';
                                $showWrongIcon = true;
                            }
                        }
                    @endphp
                    <label class="flex items-center gap-3 border rounded-xl px-4 py-3 cursor-pointer transition {{ $state }}">
                        <input type="radio" name="answer_id" value="{{ $answer->id }}" x-model="selected"
                               @checked($answer->id === $selectedAnswerId) {{ $answered ? 'disabled' : '' }} class="shrink-0">
                        <span class="text-slate-700 dark:text-slate-200 flex-1">{{ $answer->answer_text }}</span>
                        @if ($showCorrectIcon)
                            <x-icon name="check-circle" class="w-5 h-5 text-emerald-500 shrink-0" />
                        @elseif ($showWrongIcon)
                            <x-icon name="x-circle" class="w-5 h-5 text-rose-500 shrink-0" />
                        @endif
                    </label>
                @endforeach
            </div>

            @if ($answered)
                <div class="mt-4 rounded-xl p-4 flex items-start gap-2 {{ $correct ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-rose-50 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300' }}">
                    <x-icon :name="$correct ? 'check-circle' : 'x-circle'" class="w-5 h-5 shrink-0 mt-0.5" />
                    <div>
                        <strong>{{ $correct ? 'Richtig!' : 'Leider falsch.' }}</strong>
                        @if (!$correct)
                            <div class="text-sm mt-1">Richtige Antwort: {{ $revision->correctAnswer()?->answer_text }}</div>
                        @endif
                    </div>
                </div>
                <div class="flex items-center justify-between mt-4">
                    <button type="button" onclick="toggleFavorite()" class="inline-flex items-center gap-1.5 text-sm {{ $isFavorite ? 'text-amber-500' : 'text-slate-500 hover:text-amber-500' }}">
                        <x-icon name="star" class="w-4 h-4" /> {{ $isFavorite ? 'Favorit' : 'Als Favorit speichern' }}
                    </button>
                    <a href="{{ route('learning.show', $course) }}?mode={{ $mode }}{{ $topic ? '&topic='.$topic : '' }}{{ $moduleId ? '&module='.$moduleId : '' }}"
                       class="inline-flex items-center gap-1.5 px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                        Nächste Frage <x-icon name="arrow-right" class="w-4 h-4" />
                    </a>
                </div>
            @else
                <div class="flex items-center justify-between mt-4">
                    <button type="button" onclick="toggleFavorite()" class="inline-flex items-center gap-1.5 text-sm {{ $isFavorite ? 'text-amber-500' : 'text-slate-500 hover:text-amber-500' }}">
                        <x-icon name="star" class="w-4 h-4" /> {{ $isFavorite ? 'Favorit' : 'Als Favorit speichern' }}
                    </button>
                    <button type="submit" x-bind:disabled="!selected"
                            x-on:click="$refs.responseTime.value = Date.now() - {{ $startedAt }}"
                            class="px-5 py-2 rounded-lg text-white text-sm font-medium disabled:opacity-40 hover:opacity-90 transition"
                            style="background-color: var(--brand-primary, #005FD7)">
                        Antworten
                    </button>
                </div>
            @endif
        </form>
    </div>

    <form id="favorite-form" method="POST" action="{{ route($isFavorite ? 'favorites.destroy' : 'favorites.store', $revision->question_id) }}" class="hidden">
        @csrf
        @if ($isFavorite) @method('DELETE') @endif
    </form>
    <script>
        function toggleFavorite() { document.getElementById('favorite-form').submit(); }
    </script>
</x-app-layout>

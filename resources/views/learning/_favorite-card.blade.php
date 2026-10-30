@php
    // Bug-Review (Langsame Favoriten-Seite): publishedRevision() fragt
    // IMMER frisch die Datenbank ab, auch wenn die revisions-Relation
    // bereits eager geladen ist (FavoriteController::renderFavorites() lädt
    // sie über with('question.revisions')/with('revisions')) -- bei vielen
    // Favoriten/Fehlern auf einer Seite (dieses @include läuft einmal pro
    // Karte) ergab das eine zusätzliche Query pro Karte. Ist die Relation
    // bereits geladen, wird die veröffentlichte Revision jetzt direkt
    // daraus bestimmt statt erneut nachzuladen.
    $revision = $question->relationLoaded('revisions')
        ? $question->revisions->where('editorial_status', 'published')->sortByDesc('revision_no')->first()
        : $question->publishedRevision();
@endphp
<div x-show="selectedCollection === null || {{ \Illuminate\Support\Js::from($assignedCollectionIds) }}.includes(selectedCollection)"
     class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 flex items-center gap-3">
    <div class="w-10 h-10 rounded-xl {{ $badgeBg }} flex items-center justify-center shrink-0 {{ $badgeColor }}">
        <x-icon :name="$badgeIcon" class="w-5 h-5" />
    </div>
    <div class="min-w-0 flex-1">
        <div class="text-slate-700 dark:text-slate-200 truncate">{{ $revision?->question_text }}</div>
        <div class="text-xs text-slate-600 dark:text-slate-400">{{ $revision?->topic }}</div>
    </div>
    <div class="flex items-center gap-1 shrink-0">
        <form method="POST" action="{{ route($isFavorite ? 'favorites.destroy' : 'favorites.store', $question->id) }}">
            @csrf
            @if ($isFavorite) @method('DELETE') @endif
            <input type="hidden" name="context" value="{{ $context }}">
            <input type="hidden" name="course_id" value="{{ $course->id }}">
            <button type="submit" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-slate-100 dark:hover:bg-slate-700 transition {{ $isFavorite ? 'text-amber-500' : 'text-slate-300 hover:text-amber-500' }}"
                    title="{{ $isFavorite ? 'Favorit entfernen' : 'Als Favorit speichern' }}">
                <x-icon name="star" class="w-4 h-4" />
            </button>
        </form>

        <x-dropdown align="right" width="w-60">
            <x-slot name="trigger">
                <button type="button" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-slate-100 dark:hover:bg-slate-700 transition text-slate-600 dark:text-slate-400"
                        title="Collections">
                    <x-icon name="bookmark" class="w-4 h-4" />
                </button>
            </x-slot>
            <x-slot name="content">
                <form method="POST" action="{{ route('favorite-collections.sync', [$course, $question]) }}" class="p-3 space-y-2" @click.stop>
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="context" value="{{ $context }}">
                    <div class="text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">In Collections einsortieren</div>
                    <div class="space-y-1 max-h-48 overflow-y-auto">
                        @foreach ($collections as $collection)
                            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                                <input type="checkbox" name="collection_ids[]" value="{{ $collection->id }}" @checked(in_array($collection->id, $assignedCollectionIds, true))
                                       class="rounded border-slate-300 dark:bg-slate-700 dark:border-slate-600">
                                {{ $collection->name }}
                            </label>
                        @endforeach
                    </div>
                    <button type="submit" class="w-full mt-1 px-3 py-1.5 rounded-lg text-white text-xs font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                        Speichern
                    </button>
                </form>
            </x-slot>
        </x-dropdown>
    </div>
</div>

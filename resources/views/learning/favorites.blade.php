<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('courses.show', $course) }}" class="w-8 h-8 rounded-full flex items-center justify-center text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700 shrink-0">
                <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
            </a>
            <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">{{ $course->name }} &middot; Favoriten &amp; Fehler</h2>
        </div>
    </x-slot>

    <div x-data="{ section: '{{ $activeTab }}', smartView: 'favorites', examView: 'favorites', selectedCollection: null, newCollectionOpen: false }">
        <div class="flex gap-1 mb-4 border-b border-slate-200 dark:border-slate-700">
            <button @click="section = 'smart'" :class="section === 'smart' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                    :style="section === 'smart' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm transition">Smart-Learning</button>
            <button @click="section = 'exam'" :class="section === 'exam' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                    :style="section === 'exam' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm transition">Prüfungsfragen</button>
        </div>

        {{-- Collections gelten kursweit für beide Bereiche gleichzeitig -- die Auswahl filtert also sowohl Smart-Learning als auch Prüfungsfragen. --}}
        <div class="mb-2 flex flex-wrap items-center gap-2">
            <button @click="selectedCollection = null"
                    :class="selectedCollection === null ? 'text-white' : 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-600'"
                    :style="selectedCollection === null ? 'background-color: var(--brand-primary, #005FD7)' : ''"
                    class="px-3 py-1 rounded-full text-xs font-medium transition">Alle</button>
            @foreach ($collections as $collection)
                <button @click="selectedCollection = (selectedCollection === '{{ $collection->id }}' ? null : '{{ $collection->id }}')"
                        :class="selectedCollection === '{{ $collection->id }}' ? 'text-white' : 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-600'"
                        :style="selectedCollection === '{{ $collection->id }}' ? 'background-color: var(--brand-primary, #005FD7)' : ''"
                        class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium transition">
                    @if ($collection->is_default)
                        <x-icon name="star" class="w-3 h-3" />
                    @endif
                    {{ $collection->name }}
                </button>
            @endforeach
            <button @click="newCollectionOpen = ! newCollectionOpen"
                    class="px-3 py-1 rounded-full text-xs font-medium border border-dashed border-slate-300 dark:border-slate-600 text-slate-500 hover:border-slate-400 dark:hover:border-slate-500 transition">
                + Neue Collection
            </button>
        </div>

        <div x-show="newCollectionOpen" style="display: none" class="mb-4">
            <form method="POST" action="{{ route('favorite-collections.store', $course) }}" class="flex flex-wrap items-center gap-2">
                @csrf
                <label for="new_collection_name" class="sr-only">Name der Collection</label>
                <input type="text" id="new_collection_name" name="name" placeholder="Name der Collection" required maxlength="255"
                       class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm w-56">
                <button type="submit" class="px-3 py-1.5 rounded-lg text-white text-xs font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                    Anlegen
                </button>
            </form>
            @error('name')
                <p class="text-rose-600 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        @foreach ($collections as $collection)
            {{-- Bug-Review R4-3: $collection->name floss hier direkt in einen
                 JS-String innerhalb von @submit="confirm('...')" ein. Blades
                 {{ }} escaped nur für den HTML-Attribut-Kontext (' wird zu
                 &#039;) -- der Browser DEKODIERT diese Entity aber beim
                 Parsen des Attributwerts wieder zurück zu einem echten ',
                 bevor Alpine den Text als JS auswertet. Ein Collection-Name
                 wie x'); alert(document.domain); // brach damit aus dem
                 JS-String-Literal aus (Self-XSS -- der Angreifer müsste sich
                 selbst eine so benannte Collection anlegen). Der Name kommt
                 jetzt als per json_encode() sauber maskierter JS-Wert über
                 x-data herein (wie schon bei coupons/index.blade.php) und
                 wird per JS-String-Konkatenation (+) statt Text-Interpolation
                 eingefügt -- beliebiger Inhalt bleibt dadurch ein reiner
                 String-Wert, kein auswertbarer Code. --}}
            <div x-show="selectedCollection === '{{ $collection->id }}'" style="display: none"
                 x-data="{ collectionName: {{ json_encode($collection->name, JSON_HEX_APOS | JSON_HEX_QUOT) }} }"
                 class="mb-4 flex flex-wrap items-center gap-3 text-sm bg-slate-50 dark:bg-slate-700/40 rounded-xl px-3 py-2">
                <form method="POST" action="{{ route('favorite-collections.update', $collection) }}" class="flex items-center gap-1.5">
                    @csrf
                    @method('PATCH')
                    <label for="collection_name_{{ $collection->id }}" class="sr-only">Name der Collection</label>
                    <input type="text" id="collection_name_{{ $collection->id }}" name="name" value="{{ $collection->name }}" maxlength="255"
                           class="text-sm rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 py-1">
                    <button type="submit" class="text-xs text-slate-500 hover:underline whitespace-nowrap">Umbenennen</button>
                </form>
                @unless ($collection->is_default)
                    <form method="POST" action="{{ route('favorite-collections.destroy', $collection) }}"
                          @submit="if (! confirm('Collection „' + collectionName + '“ wirklich löschen? Die enthaltenen Favoriten bleiben erhalten, nur die Einsortierung entfällt.')) $event.preventDefault()">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-xs text-rose-600 hover:underline whitespace-nowrap">Collection löschen</button>
                    </form>
                @else
                    <span class="text-xs text-slate-400">Standard-Collection -- kann nicht gelöscht werden</span>
                @endunless
            </div>
        @endforeach
        @error('collection')
            <p class="text-rose-600 text-xs mb-3">{{ $message }}</p>
        @enderror

        <div x-show="section === 'smart'">
            <div x-data="{}" class="mb-2">
                <div class="flex gap-1 mb-4 border-b border-slate-100 dark:border-slate-700">
                    <button @click="smartView = 'favorites'" :class="smartView === 'favorites' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                            :style="smartView === 'favorites' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm transition">Meine Favoriten</button>
                    <button @click="smartView = 'errors'" :class="smartView === 'errors' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                            :style="smartView === 'errors' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm transition">Meine Fehler</button>
                </div>
            </div>

            <div x-show="smartView === 'favorites'" class="space-y-2">
                @forelse ($smartFavorites as $favorite)
                    @include('learning._favorite-card', [
                        'question' => $favorite->question,
                        'context' => 'smart_learning',
                        'isFavorite' => true,
                        'badgeBg' => 'bg-amber-50 dark:bg-amber-900/30',
                        'badgeColor' => 'text-amber-500',
                        'badgeIcon' => 'star',
                        'assignedCollectionIds' => $smartItemCollections->get($favorite->question_id, []),
                    ])
                @empty
                    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 text-center text-slate-500 text-sm">
                        Noch keine Favoriten gespeichert.
                    </div>
                @endforelse
            </div>

            <div x-show="smartView === 'errors'" class="space-y-2" style="display: none">
                @forelse ($smartWrongQuestions as $question)
                    @include('learning._favorite-card', [
                        'question' => $question,
                        'context' => 'smart_learning',
                        'isFavorite' => $smartFavorites->contains('question_id', $question->id),
                        'badgeBg' => 'bg-rose-50 dark:bg-rose-900/30',
                        'badgeColor' => 'text-rose-500',
                        'badgeIcon' => 'x-circle',
                        'assignedCollectionIds' => $smartItemCollections->get($question->id, []),
                    ])
                @empty
                    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 text-center text-slate-500 text-sm">
                        Bisher keine falsch beantworteten Fragen.
                    </div>
                @endforelse
            </div>
        </div>

        <div x-show="section === 'exam'" style="display: none">
            <div class="mb-2">
                <div class="flex gap-1 mb-4 border-b border-slate-100 dark:border-slate-700">
                    <button @click="examView = 'favorites'" :class="examView === 'favorites' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                            :style="examView === 'favorites' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm transition">Meine Favoriten</button>
                    <button @click="examView = 'errors'" :class="examView === 'errors' ? 'border-b-2 font-medium text-slate-800 dark:text-white' : 'border-b-2 border-transparent text-slate-500'"
                            :style="examView === 'errors' ? 'border-color: var(--brand-primary, #005FD7)' : ''" class="px-4 py-2 text-sm transition">Meine Fehler</button>
                </div>
            </div>

            <div x-show="examView === 'favorites'" class="space-y-2">
                @forelse ($examFavorites as $favorite)
                    @include('learning._favorite-card', [
                        'question' => $favorite->question,
                        'context' => 'exam',
                        'isFavorite' => true,
                        'badgeBg' => 'bg-amber-50 dark:bg-amber-900/30',
                        'badgeColor' => 'text-amber-500',
                        'badgeIcon' => 'star',
                        'assignedCollectionIds' => $examItemCollections->get($favorite->question_id, []),
                    ])
                @empty
                    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 text-center text-slate-500 text-sm">
                        Noch keine Favoriten gespeichert.
                    </div>
                @endforelse
            </div>

            <div x-show="examView === 'errors'" class="space-y-2" style="display: none">
                @forelse ($examWrongQuestions as $question)
                    @include('learning._favorite-card', [
                        'question' => $question,
                        'context' => 'exam',
                        'isFavorite' => $examFavorites->contains('question_id', $question->id),
                        'badgeBg' => 'bg-rose-50 dark:bg-rose-900/30',
                        'badgeColor' => 'text-rose-500',
                        'badgeIcon' => 'x-circle',
                        'assignedCollectionIds' => $examItemCollections->get($question->id, []),
                    ])
                @empty
                    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 text-center text-slate-500 text-sm">
                        Bisher keine falsch beantworteten Fragen.
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>

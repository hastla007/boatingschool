<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Prüfungssimulation</h2>
            <div class="text-sm text-slate-500 flex items-center gap-1.5">
                <x-icon name="clipboard-document-check" class="w-4 h-4 text-slate-400" />
                Frage {{ $current->position }} von {{ $total }}
                @if (! is_null($remainingSeconds))
                    <span class="text-slate-300">&middot;</span>
                    <x-icon name="clock" class="w-4 h-4 text-slate-400" />
                    {{-- Bug-Review B23: gmdate('i:s', ...) interpretiert den
                         Wert als Unix-Timestamp und liefert nur die
                         Minute/Sekunde INNERHALB der aktuellen Stunde --
                         bei genau 60 Minuten (3600s) sprang die Anzeige
                         dadurch auf "00:00" statt "60:00" zu zeigen. --}}
                    <span id="exam-timer" data-remaining="{{ $remainingSeconds }}">{{ sprintf('%02d:%02d', intdiv($remainingSeconds, 60), $remainingSeconds % 60) }}</span>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="max-w-2xl mx-auto bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6">
        <div class="w-full bg-slate-100 dark:bg-slate-700 rounded-full h-1.5 mb-6">
            <div class="h-1.5 rounded-full transition-all" style="width: {{ $total ? ($answered / $total * 100) : 0 }}%; background-color: var(--brand-primary, #005FD7)"></div>
        </div>

        @foreach ($current->revision->media as $media)
            <img src="{{ $media->storage_path }}" alt="{{ $media->alt_text }}" class="rounded-lg mb-4 max-h-64 mx-auto">
        @endforeach

        <h3 class="text-lg font-medium text-slate-800 dark:text-white mb-4">{{ $current->revision->question_text }}</h3>

        @if ($current->revision->question_type === 'multi_choice')
            <form method="POST" action="{{ route('exam.answer', $examSession) }}" x-data="{ selected: [] }">
                @csrf
                <input type="hidden" name="position" value="{{ $current->position }}">

                <p class="text-xs text-slate-400 mb-2">Mehrfachauswahl &mdash; mindestens eine Antwort ist richtig.</p>
                <div class="space-y-2">
                    @foreach ($current->revision->answers as $answer)
                        <label class="flex items-center gap-3 border border-slate-200 dark:border-slate-600 hover:border-slate-400 rounded-xl px-4 py-3 cursor-pointer transition">
                            <input type="checkbox" name="answer_ids[]" value="{{ $answer->id }}" x-model="selected" class="shrink-0">
                            <span class="text-slate-700 dark:text-slate-200">{{ $answer->answer_text }}</span>
                        </label>
                    @endforeach
                </div>

                <div class="flex items-center justify-between mt-6">
                    <button type="button" @click="toggleFavorite($el)" data-favorite="{{ $isFavorite ? '1' : '0' }}" data-question-id="{{ $current->question_id }}" class="favorite-toggle inline-flex items-center gap-1.5 text-sm {{ $isFavorite ? 'text-amber-500' : 'text-slate-500 hover:text-amber-500' }}">
                        <x-icon name="star" class="w-4 h-4" /> <span class="favorite-label">{{ $isFavorite ? 'Favorit' : 'Als Favorit speichern' }}</span>
                    </button>
                    <button type="submit" x-bind:disabled="selected.length === 0" class="inline-flex items-center gap-1.5 px-5 py-2 rounded-lg text-white text-sm font-medium disabled:opacity-40 hover:opacity-90 transition"
                            style="background-color: var(--brand-primary, #005FD7)">
                        {{ $current->position === $total ? 'Abschließen' : 'Weiter' }} <x-icon name="arrow-right" class="w-4 h-4" />
                    </button>
                </div>
            </form>
        @elseif ($current->revision->question_type === 'open_text')
            <div x-data="{ revealed: false }">
                <p class="text-xs text-slate-400 mb-2">Offene Frage &mdash; keine Antwortoptionen. Schätze selbst ein, ob Du die Antwort wusstest.</p>

                <template x-if="!revealed">
                    <button type="button" @click="revealed = true" class="w-full px-5 py-3 rounded-xl border border-slate-200 dark:border-slate-600 text-sm font-medium text-slate-600 dark:text-slate-200 hover:border-slate-400 transition">
                        Musterantwort anzeigen
                    </button>
                </template>

                <template x-if="revealed">
                    <div>
                        <div class="rounded-xl bg-slate-50 dark:bg-slate-700/50 border border-slate-200 dark:border-slate-600 px-4 py-3 text-sm text-slate-700 dark:text-slate-200 whitespace-pre-line">
                            {{ $current->revision->correctAnswer()?->answer_text }}
                        </div>

                        <form method="POST" action="{{ route('exam.answer', $examSession) }}" class="flex items-center justify-between mt-6">
                            @csrf
                            <input type="hidden" name="position" value="{{ $current->position }}">
                            <button type="button" @click="toggleFavorite()" class="inline-flex items-center gap-1.5 text-sm {{ $isFavorite ? 'text-amber-500' : 'text-slate-500 hover:text-amber-500' }}">
                                <x-icon name="star" class="w-4 h-4" /> {{ $isFavorite ? 'Favorit' : 'Als Favorit speichern' }}
                            </button>
                            <div class="flex gap-2">
                                <button type="submit" name="known" value="0" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg border border-rose-300 text-rose-600 text-sm font-medium hover:bg-rose-50 dark:hover:bg-rose-900/20 transition">
                                    Nicht gewusst
                                </button>
                                <button type="submit" name="known" value="1" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                                    Gewusst
                                </button>
                            </div>
                        </form>
                    </div>
                </template>
            </div>
        @else
            <form method="POST" action="{{ route('exam.answer', $examSession) }}" x-data="{ selected: null }">
                @csrf
                <input type="hidden" name="position" value="{{ $current->position }}">

                <div class="space-y-2">
                    @foreach ($current->revision->answers as $answer)
                        <label class="flex items-center gap-3 border border-slate-200 dark:border-slate-600 hover:border-slate-400 rounded-xl px-4 py-3 cursor-pointer transition">
                            <input type="radio" name="answer_id" value="{{ $answer->id }}" x-model="selected" class="shrink-0">
                            <span class="text-slate-700 dark:text-slate-200">{{ $answer->answer_text }}</span>
                        </label>
                    @endforeach
                </div>

                <div class="flex items-center justify-between mt-6">
                    <button type="button" @click="toggleFavorite($el)" data-favorite="{{ $isFavorite ? '1' : '0' }}" data-question-id="{{ $current->question_id }}" class="favorite-toggle inline-flex items-center gap-1.5 text-sm {{ $isFavorite ? 'text-amber-500' : 'text-slate-500 hover:text-amber-500' }}">
                        <x-icon name="star" class="w-4 h-4" /> <span class="favorite-label">{{ $isFavorite ? 'Favorit' : 'Als Favorit speichern' }}</span>
                    </button>
                    <button type="submit" x-bind:disabled="!selected" class="inline-flex items-center gap-1.5 px-5 py-2 rounded-lg text-white text-sm font-medium disabled:opacity-40 hover:opacity-90 transition"
                            style="background-color: var(--brand-primary, #005FD7)">
                        {{ $current->position === $total ? 'Abschließen' : 'Weiter' }} <x-icon name="arrow-right" class="w-4 h-4" />
                    </button>
                </div>
            </form>
        @endif
    </div>

    <script nonce="{{ $cspNonce }}">
        // Bug-Review B29: ein Klick auf "Favorit" sendete bisher ein
        // verstecktes Formular ab und navigierte damit die gesamte Seite
        // neu -- die noch nicht abgeschickte Antwortauswahl im
        // Hauptformular ging dabei verloren. Per fetch() bleibt die Seite
        // (und die Auswahl) unverändert; nur der Button-Zustand wechselt.
        function toggleFavorite(btn) {
            const wasFavorite = btn.dataset.favorite === '1';
            const csrf = document.querySelector('meta[name="csrf-token"]').content;

            fetch('/favorites/' + btn.dataset.questionId, {
                method: wasFavorite ? 'DELETE' : 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ context: 'exam', course_id: '{{ $examSession->course_id }}' }),
            }).then(function (response) {
                if (! response.ok) {
                    return;
                }

                const nowFavorite = ! wasFavorite;
                btn.dataset.favorite = nowFavorite ? '1' : '0';
                btn.classList.toggle('text-amber-500', nowFavorite);
                btn.classList.toggle('text-slate-500', ! nowFavorite);
                btn.classList.toggle('hover:text-amber-500', ! nowFavorite);
                btn.querySelector('.favorite-label').textContent = nowFavorite ? 'Favorit' : 'Als Favorit speichern';
            });
        }
    </script>

    @if (! is_null($remainingSeconds))
        <script nonce="{{ $cspNonce }}">
            (function () {
                const el = document.getElementById('exam-timer');
                let remaining = parseInt(el.dataset.remaining, 10);
                setInterval(function () {
                    remaining = Math.max(0, remaining - 1);
                    const m = String(Math.floor(remaining / 60)).padStart(2, '0');
                    const s = String(remaining % 60).padStart(2, '0');
                    el.textContent = m + ':' + s;
                    if (remaining <= 0) window.location.reload();
                }, 1000);
            })();
        </script>
    @endif
</x-app-layout>

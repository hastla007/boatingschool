<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <div class="text-xs text-slate-400 flex items-center gap-1">
                    <a href="{{ route('courses.show', $course) }}" class="hover:underline">{{ $course->name }}</a>
                    @if ($revision->smartmodus_kategorie ?: $revision->topic)
                        <x-icon name="chevron-right" class="w-3 h-3" />
                        <span>{{ $revision->smartmodus_kategorie ?: $revision->topic }}</span>
                    @endif
                </div>
                <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">{{ $revision->subtopic ?? $revision->topic ?? 'Frage' }}</h2>
            </div>
            <div class="flex flex-wrap gap-1 text-xs">
                @foreach (['smarttrainer' => ['Smarttrainer', 'bolt'], 'new' => ['Neu', 'star'], 'wrong' => ['Falsch', 'x-circle'], 'favorites' => ['Favoriten', 'star']] as $key => [$label, $icon])
                    <a href="{{ route('learning.show', array_filter(['course' => $course, 'mode' => $key, 'module' => $moduleId])) }}"
                       class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg {{ $mode === $key ? 'text-white' : 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-200' }}"
                       @style(["background-color: var(--brand-primary, #005FD7)" => $mode === $key])>
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>
    </x-slot>

    <div class="max-w-2xl mx-auto bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6">
        <div class="flex items-center justify-between gap-2 mb-3">
            @if ($reason)
                <div class="inline-flex items-center gap-1.5 text-xs text-slate-400 bg-slate-50 dark:bg-slate-700/50 rounded-full px-3 py-1">
                    <x-icon name="bolt" class="w-3.5 h-3.5" /> Ausgewählt vom Smarttrainer: {{ $reason }}
                </div>
            @else
                <span></span>
            @endif

            <div class="inline-flex items-center gap-1.5 text-xs font-medium rounded-full px-3 py-1 shrink-0 {{ $mastered ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-slate-50 text-slate-500 dark:bg-slate-700/50 dark:text-slate-300' }}"
                  title="Die Serie zählt pro Frage. Beantworte diese Frage 3x hintereinander richtig, dann gilt sie als gelernt und dein Fortschritt steigt.">
                <x-icon :name="$mastered ? 'check-circle' : 'lock-closed'" class="w-3.5 h-3.5" />
                {{ min($streak, 3) }}/3
            </div>
        </div>

        @foreach ($revision->media as $media)
            <img src="{{ $media->storage_path }}" alt="{{ $media->alt_text }}" class="rounded-lg mb-4 max-h-64 mx-auto">
        @endforeach

        <h3 class="text-lg font-medium text-slate-800 dark:text-white mb-4">{{ $revision->question_text }}</h3>

        @if ($revision->question_type === 'multi_choice')
            <form method="POST" action="{{ route('learning.attempts.store', ['course' => $course, 'topic' => $topic, 'module' => $moduleId, 'questions' => $questionsParam]) }}" x-data="{ selected: {{ json_encode($selectedAnswerIds ?? []) }} }">
                @csrf
                <input type="hidden" name="revision_id" value="{{ $revision->id }}">
                <input type="hidden" name="mode" value="{{ $mode }}">
                {{-- Bug-Review B19: Antwortzeit wurde bisher client-seitig als
                     Date.now() - $startedAt berechnet -- bei einer
                     nachgehenden Geräteuhr konnte das negativ werden und
                     die gesamte Antwort wegen "response_time_ms >= 0"
                     ablehnen. started_at_ms trägt nur den unverändert vom
                     Server gerenderten Zeitpunkt weiter; die eigentliche
                     Dauer berechnet LearningController::storeAttempt() aus
                     der Differenz zur eigenen (Server-)Uhr bei Eingang. --}}
                <input type="hidden" name="started_at_ms" value="{{ $startedAt }}">

                @unless ($answered)
                    <p class="text-xs text-slate-400 mb-2">Mehrfachauswahl &mdash; mindestens eine Antwort ist richtig.</p>
                @endunless

                <div class="space-y-2">
                    @foreach ($revision->answers as $answer)
                        @php
                            $state = 'border-slate-200 dark:border-slate-600 hover:border-slate-400';
                            $showCorrectIcon = false;
                            $showWrongIcon = false;
                            $wasSelected = in_array($answer->id, $selectedAnswerIds ?? [], true);
                            if ($answered) {
                                if ($answer->is_correct) {
                                    $state = 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/30';
                                    $showCorrectIcon = true;
                                } elseif ($wasSelected) {
                                    $state = 'border-rose-500 bg-rose-50 dark:bg-rose-900/30';
                                    $showWrongIcon = true;
                                }
                            }
                        @endphp
                        <label class="flex items-center gap-3 border rounded-xl px-4 py-3 cursor-pointer transition {{ $state }}">
                            <input type="checkbox" name="answer_ids[]" value="{{ $answer->id }}" x-model="selected"
                                   @checked($wasSelected) {{ $answered ? 'disabled' : '' }} class="shrink-0">
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
                                <div class="text-sm mt-1">Richtige Antworten: {{ $revision->correctAnswers()->pluck('answer_text')->implode('; ') }}</div>
                            @endif
                            @php $feedback = $correct ? $revision->feedback_correct : $revision->feedback_incorrect; @endphp
                            @if ($feedback)
                                <div class="text-sm mt-2 whitespace-pre-line">{{ $feedback }}</div>
                            @endif
                        </div>
                    </div>
                    <div class="flex items-center justify-between mt-4">
                        <button type="button" @click="toggleFavorite($el)" data-favorite="{{ $isFavorite ? '1' : '0' }}" data-question-id="{{ $revision->question_id }}" class="favorite-toggle inline-flex items-center gap-1.5 text-sm {{ $isFavorite ? 'text-amber-500' : 'text-slate-500 hover:text-amber-500' }}">
                            <x-icon name="star" class="w-4 h-4" /> <span class="favorite-label">{{ $isFavorite ? 'Favorit' : 'Als Favorit speichern' }}</span>
                        </button>
                        <a href="{{ route('learning.show', array_filter(['course' => $course, 'mode' => $mode, 'topic' => $topic, 'module' => $moduleId, 'questions' => $questionsParam])) }}"
                           class="inline-flex items-center gap-1.5 px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                            Nächste Frage <x-icon name="arrow-right" class="w-4 h-4" />
                        </a>
                    </div>
                @else
                    <div class="flex items-center justify-between mt-4">
                        <button type="button" @click="toggleFavorite($el)" data-favorite="{{ $isFavorite ? '1' : '0' }}" data-question-id="{{ $revision->question_id }}" class="favorite-toggle inline-flex items-center gap-1.5 text-sm {{ $isFavorite ? 'text-amber-500' : 'text-slate-500 hover:text-amber-500' }}">
                            <x-icon name="star" class="w-4 h-4" /> <span class="favorite-label">{{ $isFavorite ? 'Favorit' : 'Als Favorit speichern' }}</span>
                        </button>
                        <button type="submit" x-bind:disabled="selected.length === 0"
                                class="px-5 py-2 rounded-lg text-white text-sm font-medium disabled:opacity-40 hover:opacity-90 transition"
                                style="background-color: var(--brand-primary, #005FD7)">
                            Antworten
                        </button>
                    </div>
                @endif
            </form>
        @elseif ($revision->question_type === 'open_text')
            <div x-data="{ revealed: {{ $answered ? 'true' : 'false' }} }">
                <p class="text-xs text-slate-400 mb-2">Offene Frage &mdash; keine Antwortoptionen. Schätze selbst ein, ob Du die Antwort wusstest.</p>

                <template x-if="!revealed">
                    <button type="button" @click="revealed = true" class="w-full px-5 py-3 rounded-xl border border-slate-200 dark:border-slate-600 text-sm font-medium text-slate-600 dark:text-slate-200 hover:border-slate-400 transition">
                        Musterantwort anzeigen
                    </button>
                </template>

                <template x-if="revealed">
                    <div>
                        <div class="rounded-xl bg-slate-50 dark:bg-slate-700/50 border border-slate-200 dark:border-slate-600 px-4 py-3 text-sm text-slate-700 dark:text-slate-200 whitespace-pre-line">
                            {{ $revision->correctAnswer()?->answer_text }}
                        </div>

                        @if ($answered)
                            <div class="mt-4 rounded-xl p-4 flex items-start gap-2 {{ $correct ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-rose-50 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300' }}">
                                <x-icon :name="$correct ? 'check-circle' : 'x-circle'" class="w-5 h-5 shrink-0 mt-0.5" />
                                <strong>{{ $correct ? 'Als gewusst markiert.' : 'Als nicht gewusst markiert.' }}</strong>
                            </div>
                            <div class="flex items-center justify-between mt-4">
                                <button type="button" @click="toggleFavorite($el)" data-favorite="{{ $isFavorite ? '1' : '0' }}" data-question-id="{{ $revision->question_id }}" class="favorite-toggle inline-flex items-center gap-1.5 text-sm {{ $isFavorite ? 'text-amber-500' : 'text-slate-500 hover:text-amber-500' }}">
                                    <x-icon name="star" class="w-4 h-4" /> <span class="favorite-label">{{ $isFavorite ? 'Favorit' : 'Als Favorit speichern' }}</span>
                                </button>
                                <a href="{{ route('learning.show', array_filter(['course' => $course, 'mode' => $mode, 'topic' => $topic, 'module' => $moduleId, 'questions' => $questionsParam])) }}"
                                   class="inline-flex items-center gap-1.5 px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                                    Nächste Frage <x-icon name="arrow-right" class="w-4 h-4" />
                                </a>
                            </div>
                        @else
                            <form method="POST" action="{{ route('learning.attempts.store', ['course' => $course, 'topic' => $topic, 'module' => $moduleId, 'questions' => $questionsParam]) }}" class="flex items-center justify-between mt-6" x-data="{}">
                                @csrf
                                <input type="hidden" name="revision_id" value="{{ $revision->id }}">
                                <input type="hidden" name="mode" value="{{ $mode }}">
                                <input type="hidden" name="started_at_ms" value="{{ $startedAt }}">
                                <button type="button" @click="toggleFavorite($el)" data-favorite="{{ $isFavorite ? '1' : '0' }}" data-question-id="{{ $revision->question_id }}" class="favorite-toggle inline-flex items-center gap-1.5 text-sm {{ $isFavorite ? 'text-amber-500' : 'text-slate-500 hover:text-amber-500' }}">
                                    <x-icon name="star" class="w-4 h-4" /> <span class="favorite-label">{{ $isFavorite ? 'Favorit' : 'Als Favorit speichern' }}</span>
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
                        @endif
                    </div>
                </template>
            </div>
        @else
            <form method="POST" action="{{ route('learning.attempts.store', ['course' => $course, 'topic' => $topic, 'module' => $moduleId, 'questions' => $questionsParam]) }}" x-data="{ selected: {{ $selectedAnswerId ? "'{$selectedAnswerId}'" : 'null' }} }">
                @csrf
                <input type="hidden" name="revision_id" value="{{ $revision->id }}">
                <input type="hidden" name="mode" value="{{ $mode }}">
                <input type="hidden" name="started_at_ms" value="{{ $startedAt }}">

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
                            @php $feedback = $correct ? $revision->feedback_correct : $revision->feedback_incorrect; @endphp
                            @if ($feedback)
                                <div class="text-sm mt-2 whitespace-pre-line">{{ $feedback }}</div>
                            @endif
                        </div>
                    </div>
                    <div class="flex items-center justify-between mt-4">
                        <button type="button" @click="toggleFavorite($el)" data-favorite="{{ $isFavorite ? '1' : '0' }}" data-question-id="{{ $revision->question_id }}" class="favorite-toggle inline-flex items-center gap-1.5 text-sm {{ $isFavorite ? 'text-amber-500' : 'text-slate-500 hover:text-amber-500' }}">
                            <x-icon name="star" class="w-4 h-4" /> <span class="favorite-label">{{ $isFavorite ? 'Favorit' : 'Als Favorit speichern' }}</span>
                        </button>
                        <a href="{{ route('learning.show', array_filter(['course' => $course, 'mode' => $mode, 'topic' => $topic, 'module' => $moduleId, 'questions' => $questionsParam])) }}"
                           class="inline-flex items-center gap-1.5 px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                            Nächste Frage <x-icon name="arrow-right" class="w-4 h-4" />
                        </a>
                    </div>
                @else
                    <div class="flex items-center justify-between mt-4">
                        <button type="button" @click="toggleFavorite($el)" data-favorite="{{ $isFavorite ? '1' : '0' }}" data-question-id="{{ $revision->question_id }}" class="favorite-toggle inline-flex items-center gap-1.5 text-sm {{ $isFavorite ? 'text-amber-500' : 'text-slate-500 hover:text-amber-500' }}">
                            <x-icon name="star" class="w-4 h-4" /> <span class="favorite-label">{{ $isFavorite ? 'Favorit' : 'Als Favorit speichern' }}</span>
                        </button>
                        <button type="submit" x-bind:disabled="!selected"
                                class="px-5 py-2 rounded-lg text-white text-sm font-medium disabled:opacity-40 hover:opacity-90 transition"
                                style="background-color: var(--brand-primary, #005FD7)">
                            Antworten
                        </button>
                    </div>
                @endif
            </form>
        @endif
    </div>

    <script nonce="{{ $cspNonce }}">
        // Bug-Review Oberfläche U2 (dasselbe Muster wie Bug-Review B29 in
        // exam/question.blade.php): ein Klick auf "Favorit" sendete bisher
        // ein verstecktes Formular ab und navigierte damit die gesamte
        // Seite neu -- die noch nicht abgeschickte Antwortauswahl im
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
                body: JSON.stringify({ context: 'smart_learning', course_id: '{{ $course->id }}' }),
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
</x-app-layout>

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Prüfungssimulation</h1>
            <div class="text-sm text-slate-500 dark:text-slate-400 flex items-center gap-1.5">
                <x-icon name="compass" class="w-4 h-4 text-slate-600 dark:text-slate-400" />
                Navigationsaufgabe {{ $task?->task_number }}
                @if ($step === 'answer' && ! is_null($remainingSeconds))
                    <span class="text-slate-300">&middot;</span>
                    <x-icon name="clock" class="w-4 h-4 text-slate-600 dark:text-slate-400" />
                    <span id="exam-timer" data-remaining="{{ $remainingSeconds }}" aria-label="Verbleibende Prüfungszeit">{{ sprintf('%02d:%02d', intdiv($remainingSeconds, 60), $remainingSeconds % 60) }}</span>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="max-w-3xl mx-auto space-y-4">
        @if ($step === 'answer')
            <div class="rounded-xl bg-sky-50 border border-sky-200 text-sky-900 dark:bg-sky-900/30 dark:border-sky-800 dark:text-sky-100 px-4 py-3 text-sm">
                Teil 2 der Prüfung: die Navigationsaufgabe. Bestanden ist sie mit mindestens {{ $rule['min_points'] }} von {{ $rule['of'] }} Punkten
                &ndash; unabhängig vom Fragebogen. Du brauchst die Übungsseekarte&nbsp;49 (BSH) mit Kursdreieck und Zirkel.
            </div>
        @endif

        @if ($task)
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
                <h2 class="font-medium text-slate-800 dark:text-white mb-2">Navigationsaufgabe {{ $task->task_number }}</h2>
                <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">{{ $task->scenario_text }}</p>
                {{-- Review-Runde 11: der Hinweis enthält teils Lösungswerte -- erst bei der Selbsteinschätzung zeigen. --}}
                @if ($task->hint && $step !== 'answer')
                    <p class="mt-2 text-xs text-slate-600 dark:text-slate-400">{{ $task->hint }}</p>
                @endif
            </div>
            @if ($step === 'answer' && $task->chart_image_path)
                <figure class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-3">
                    <img src="{{ \App\Support\NavigationChart::url($task->chart_image_path) }}" alt="Kartenausschnitt zu Aufgabe {{ $task->task_number }}" class="w-full rounded-xl" loading="lazy">
                    @if ($task->chart_caption)
                        <figcaption class="mt-2 text-xs text-slate-600 dark:text-slate-400">{{ $task->chart_caption }}</figcaption>
                    @endif
                </figure>
            @endif
        @endif

        @if ($step === 'answer')
            <form id="navigation-form" method="POST" action="{{ route('exam.navigation-part.submit', $examSession) }}" class="space-y-3"
                  x-data="{ busy: false }" @submit="if (busy || (! $el.dataset.auto && ! confirm('Navigationsaufgabe abgeben? Danach lassen sich die Antworten nicht mehr ändern.'))) { $event.preventDefault() } else { busy = true }">
                @csrf
                @foreach ($answers as $answer)
                    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4">
                        <label for="nav-{{ $answer->id }}" class="block text-sm text-slate-700 dark:text-slate-200 break-words">
                            <span class="font-medium">Frage {{ $answer->number() }}:</span> {{ $answer->questionText() }}
                        </label>
                        @if ($answer->isChartDrawing())
                            <p class="mt-2 text-xs text-slate-600 dark:text-slate-400">Diese Lösung trägst du in die Seekarte ein &ndash; hier musst du nichts eingeben. Nach der Abgabe vergleichst du sie selbst mit der Musterlösung.</p>
                            <input type="hidden" name="answers[{{ $answer->id }}]" value="">
                        @else
                            <input id="nav-{{ $answer->id }}" name="answers[{{ $answer->id }}]" type="text" maxlength="500"
                                   placeholder="{{ \App\Support\NavigationAnswer::placeholder($answer->modelAnswer()) }}"
                                   class="mt-2 w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100 text-sm">
                        @endif
                    </div>
                @endforeach
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-slate-600 dark:text-slate-400">Messwerte (Kurs, Peilung, Position, Distanz, Fahrt, Zeit) werden automatisch mit Toleranz geprüft; alles andere bewertest du nach der Abgabe selbst anhand der Musterlösung.</p>
                    <button type="submit" :disabled="busy" class="disabled:opacity-50 px-4 py-2 rounded-lg text-white text-sm font-medium" style="background-color: var(--brand-primary, #005FD7)">Navigationsaufgabe abgeben</button>
                </div>
            </form>
        @else
            <form method="POST" action="{{ route('exam.navigation-part.assess', $examSession) }}" class="space-y-3">
                @csrf
                @if ($examSession->deadline() && $examSession->navigation_submitted_at?->gte($examSession->deadline()))
                    {{-- Review-Runde 11 --}}
                    <p class="rounded-xl bg-amber-50 border border-amber-200 text-amber-900 dark:bg-amber-900/30 dark:border-amber-800 dark:text-amber-100 px-4 py-3 text-sm">Die Prüfungszeit ist abgelaufen &ndash; deine Eingaben wurden automatisch abgegeben.</p>
                @endif
                <p class="text-sm text-slate-600 dark:text-slate-300">Vergleiche deine Antworten mit der Musterlösung und bewerte ehrlich &ndash; danach wird die Prüfung ausgewertet. Die Zeit dafür zählt nicht zur Prüfungszeit.</p>
                @foreach ($toAssess as $answer)
                    @php($auto = $answer->autoResult())
                    <fieldset class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 min-w-0">
                        <legend class="sr-only">Frage {{ $answer->number() }}</legend>
                        <p class="text-sm text-slate-700 dark:text-slate-200 break-words"><span class="font-medium">Frage {{ $answer->number() }}:</span> {{ $answer->questionText() }}</p>
                        <p class="mt-2 text-sm break-all"><span class="text-slate-500 dark:text-slate-400">Deine Antwort:</span> {{ $answer->answer ?? ($answer->isChartDrawing() ? 'in die Karte eingetragen' : '—') }}</p>
                        <p class="mt-1 text-sm rounded-lg bg-slate-50 dark:bg-slate-700/50 p-2 break-words"><span class="font-medium">Musterlösung:</span> {{ $answer->modelAnswer() }}</p>
                        @if ($answer->answer === null && ! $answer->isChartDrawing())
                            <p class="mt-2 text-sm text-rose-700 dark:text-rose-300">Keine Antwort &ndash; zählt als falsch.</p>
                        @elseif ($auto === true)
                            <p class="mt-2 text-sm text-emerald-700 dark:text-emerald-300">Automatisch geprüft: richtig.</p>
                        @elseif ($auto === false)
                            <p class="mt-2 text-sm text-rose-700 dark:text-rose-300">Automatisch geprüft: weicht von der Musterlösung ab.</p>
                            <label class="mt-1 inline-flex items-center gap-2 text-sm"><input type="checkbox" name="known[{{ $answer->id }}]" value="1"> Meine Antwort ist trotzdem richtig (gleichwertig)</label>
                        @else
                            <div class="mt-2 flex flex-wrap gap-4 text-sm">
                                <label class="inline-flex items-center gap-2"><input type="radio" name="known[{{ $answer->id }}]" value="1" required> Richtig</label>
                                <label class="inline-flex items-center gap-2"><input type="radio" name="known[{{ $answer->id }}]" value="0"> Falsch</label>
                            </div>
                        @endif
                    </fieldset>
                @endforeach
                <div class="flex justify-end">
                    <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium" style="background-color: var(--brand-primary, #005FD7)">Prüfung auswerten</button>
                </div>
            </form>
        @endif

        {{-- Kein „vorzeitig abgeben“ in diesem Schritt: „Navigationsaufgabe abgeben“ gibt das Eingetragene ab
             (der frühere Link warf die Antworten weg). --}}
    </div>

    @if ($step === 'answer')
        <script nonce="{{ $cspNonce }}">
            // Review-Runde 10: Eingaben im Browser zwischenspeichern -- ein
            // Neuladen oder Absturz kostet sonst die ganze Navigationsaufgabe.
            (function () {
                const form = document.getElementById('navigation-form');
                const key = 'exam-nav-' + @js($examSession->id);
                let saved = {};
                try { saved = JSON.parse(localStorage.getItem(key) || '{}'); } catch (e) {}
                form.querySelectorAll('input[type=text]').forEach(function (input) {
                    if (saved[input.name] && ! input.value) input.value = saved[input.name];
                    input.addEventListener('input', function () {
                        saved[input.name] = input.value;
                        try { localStorage.setItem(key, JSON.stringify(saved)); } catch (e) {}
                    });
                });
                form.addEventListener('submit', function () {
                    try { localStorage.removeItem(key); } catch (e) {}
                });
            })();
        </script>
    @endif

    @if ($step === 'answer' && ! is_null($remainingSeconds))
        <script nonce="{{ $cspNonce }}">
            (function () {
                const el = document.getElementById('exam-timer');
                const form = document.getElementById('navigation-form');
                let remaining = parseInt(el.dataset.remaining, 10);
                let sent = false;
                setInterval(function () {
                    remaining = Math.max(0, remaining - 1);
                    el.textContent = String(Math.floor(remaining / 60)).padStart(2, '0') + ':' + String(remaining % 60).padStart(2, '0');
                    // Zeit abgelaufen: das Eingetragene wird abgegeben, nicht verworfen.
                    if (remaining <= 0 && ! sent) {
                        sent = true;
                        form.dataset.auto = '1';
                        form.requestSubmit();
                    }
                }, 1000);
            })();
        </script>
    @endif
</x-app-layout>

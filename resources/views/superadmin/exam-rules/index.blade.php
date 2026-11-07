<x-superadmin-layout>
    <x-slot name="header">
        <div>
            <div class="text-xs text-slate-600 dark:text-slate-400"><a href="{{ route('superadmin.courses.show', $course) }}" class="hover:underline">{{ $course->name }}</a></div>
            <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Prüfungsregeln</h1>
        </div>
    </x-slot>

    @php
        $input = 'mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm';
    @endphp
    <div class="max-w-4xl space-y-4">
        <div class="rounded-xl bg-amber-50 border border-amber-200 text-amber-800 dark:bg-amber-900/30 dark:border-amber-800 dark:text-amber-200 px-4 py-3 text-sm">
            Eine Prüfungssimulation lässt sich nur mit einem <strong>veröffentlichten und als geprüft markierten</strong> Regelwerk starten.
            Änderungen gelten für neu gestartete Prüfungen; laufende und abgeschlossene Versuche behalten die Regeln, unter denen sie begonnen wurden. Werte bitte mit der amtlichen Prüfungsordnung abgleichen.
        </div>
        @php
            $comboGroups = \App\Support\ComboExamGroups::GROUPS;
            $usedByCombos = collect($comboGroups)->filter(fn ($groups) => collect($groups)->contains('course', $course->code))
                ->map(fn ($groups) => collect($groups)->firstWhere('course', $course->code));
        @endphp
        @if (isset($comboGroups[$course->code]))
            <div class="rounded-xl bg-sky-50 border border-sky-200 text-sky-900 dark:bg-sky-900/30 dark:border-sky-800 dark:text-sky-100 px-4 py-3 text-sm">
                Kombikurs: Die Prüfungsbögen werden nach dem Regelwerk ihres Teilkurses bewertet
                ({{ collect($comboGroups[$course->code])->map(fn ($g) => $g['label'].' → '.$g['course'])->implode(', ') }}).
                Das Regelwerk hier gilt nur für zufällig zusammengestellte Prüfungen dieses Kurses.
            </div>
        @endif
        @if ($usedByCombos->isNotEmpty())
            <div class="rounded-xl bg-sky-50 border border-sky-200 text-sky-900 dark:bg-sky-900/30 dark:border-sky-800 dark:text-sky-100 px-4 py-3 text-sm">
                Dieses Regelwerk gilt auch für Bögen in Kombikursen:
                {{ $usedByCombos->map(fn ($g, $combo) => $combo.' (Bögen '.$g['min'].'–'.$g['max'].')')->implode(', ') }}.
                Wird es zurückgezogen oder nicht mehr als geprüft markiert, lassen sich dort diese Bögen nicht mehr starten.
            </div>
        @endif
        @if ($errors->any())
            <div class="rounded-xl bg-red-50 border border-red-200 text-red-800 dark:bg-red-900/30 dark:border-red-800 dark:text-red-200 px-4 py-3 text-sm">{{ $errors->first() }}</div>
        @endif

        @forelse ($ruleSets as $ruleSet)
            @php
                $rule = $ruleSet->passing_rule ?? [];
                $total = (int) $ruleSet->blueprints->sum('question_count') ?: 24;
                [$fractionMin, $fractionOf] = $ruleSet->passingFraction($total);
                $parts = array_values($rule['parts'] ?? []);
                $parts[] = ['label' => '', 'module' => '', 'min_correct' => '', 'of' => ''];
                $blueprintRows = $ruleSet->blueprints->map(fn ($b) => ['module_id' => $b->module_id, 'question_count' => $b->question_count])->all();
                $blueprintRows[] = ['module_id' => '', 'question_count' => ''];
            @endphp
            <form method="POST" action="{{ route('superadmin.exam-rules.update', $ruleSet) }}" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 space-y-5">
                @csrf
                @method('PATCH')
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="font-medium text-slate-700 dark:text-slate-200">Regelwerk {{ $ruleSet->version }}</h3>
                    @if ($ruleSet->id === $activeId)
                        <span class="text-xs rounded-full px-2 py-0.5 bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">aktiv</span>
                    @else
                        <span class="text-xs rounded-full px-2 py-0.5 bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-200">nicht aktiv</span>
                    @endif
                    @if ($ruleSet->verified_at)
                        <span class="text-xs text-slate-600 dark:text-slate-400">geprüft am {{ $ruleSet->verified_at->timezone('Europe/Berlin')->format('d.m.Y') }}</span>
                    @endif
                </div>

                <div class="grid sm:grid-cols-4 gap-4">
                    <div>
                        <x-input-label for="time-{{ $ruleSet->id }}" value="Zeitlimit (Minuten)" />
                        <input id="time-{{ $ruleSet->id }}" name="time_limit_minutes" type="number" min="1" max="600" required value="{{ old('time_limit_minutes', intdiv((int) $ruleSet->time_limit_seconds, 60)) }}" class="{{ $input }}">
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="min-{{ $ruleSet->id }}" value="Bestanden ab … richtigen Antworten" />
                        <div class="flex items-center gap-2">
                            <input id="min-{{ $ruleSet->id }}" name="min_correct" type="number" min="1" required value="{{ old('min_correct', $fractionMin) }}" class="{{ $input }}">
                            <span class="mt-1 text-sm text-slate-600 dark:text-slate-400">von</span>
                            <label for="of-{{ $ruleSet->id }}" class="sr-only">Fragen gesamt</label>
                            <input id="of-{{ $ruleSet->id }}" name="of" type="number" min="1" required value="{{ old('of', $fractionOf) }}" class="{{ $input }}">
                        </div>
                        <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">Wird als Quote gespeichert und gilt für jede Bogenlänge.</p>
                    </div>
                    <div>
                        <x-input-label for="status-{{ $ruleSet->id }}" value="Status" />
                        <select id="status-{{ $ruleSet->id }}" name="status" class="{{ $input }}">
                            <option value="draft" @selected($ruleSet->status !== 'published')>Entwurf</option>
                            <option value="published" @selected($ruleSet->status === 'published')>Veröffentlicht</option>
                        </select>
                    </div>
                </div>

                <fieldset class="min-w-0">
                    <legend class="text-sm font-medium text-slate-700 dark:text-slate-200 mb-1">Fragenmix (zufällige Prüfung, Fragen je Modul)</legend>
                    <div class="space-y-2">
                        @foreach ($blueprintRows as $i => $row)
                            <div class="flex gap-2">
                                <label for="bp-module-{{ $ruleSet->id }}-{{ $i }}" class="sr-only">Modul</label>
                                <select id="bp-module-{{ $ruleSet->id }}-{{ $i }}" name="blueprints[{{ $i }}][module_id]" class="flex-1 min-w-0 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                                    <option value="">{{ $row['module_id'] ? '— entfernen —' : '— Modul hinzufügen —' }}</option>
                                    @foreach ($modules as $module)
                                        <option value="{{ $module->id }}" @selected($row['module_id'] === $module->id)>{{ $module->name }}</option>
                                    @endforeach
                                </select>
                                <label for="bp-count-{{ $ruleSet->id }}-{{ $i }}" class="sr-only">Anzahl Fragen</label>
                                <input id="bp-count-{{ $ruleSet->id }}-{{ $i }}" name="blueprints[{{ $i }}][question_count]" type="number" min="1" value="{{ $row['question_count'] }}" placeholder="Anzahl" class="w-24 shrink-0 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                            </div>
                        @endforeach
                    </div>
                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Feste Prüfungsbögen behalten ihre eigenen Fragen; der Mix gilt für zufällig zusammengestellte Prüfungen.</p>
                </fieldset>

                <fieldset class="min-w-0">
                    <legend class="text-sm font-medium text-slate-700 dark:text-slate-200 mb-1">Prüfungsteile mit eigener Grenze (optional, z. B. Basisfragen 5 von 7)</legend>
                    <div class="space-y-2">
                        @foreach ($parts as $i => $part)
                            <div class="grid grid-cols-2 sm:grid-cols-6 gap-2">
                                <label for="part-label-{{ $ruleSet->id }}-{{ $i }}" class="sr-only">Bezeichnung</label>
                                <input id="part-label-{{ $ruleSet->id }}-{{ $i }}" name="parts[{{ $i }}][label]" value="{{ $part['label'] }}" placeholder="Bezeichnung" class="col-span-2 min-w-0 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                                <label for="part-module-{{ $ruleSet->id }}-{{ $i }}" class="sr-only">Modul</label>
                                <select id="part-module-{{ $ruleSet->id }}-{{ $i }}" name="parts[{{ $i }}][module]" class="col-span-2 min-w-0 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                                    <option value="">— Modul —</option>
                                    @foreach ($modules as $module)
                                        <option value="{{ $module->code }}" @selected($part['module'] === $module->code)>{{ $module->name }}</option>
                                    @endforeach
                                </select>
                                <label for="part-min-{{ $ruleSet->id }}-{{ $i }}" class="sr-only">mindestens richtig</label>
                                <input id="part-min-{{ $ruleSet->id }}-{{ $i }}" name="parts[{{ $i }}][min_correct]" type="number" min="0" value="{{ $part['min_correct'] }}" placeholder="x" class="min-w-0 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                                <label for="part-of-{{ $ruleSet->id }}-{{ $i }}" class="sr-only">von</label>
                                <input id="part-of-{{ $ruleSet->id }}-{{ $i }}" name="parts[{{ $i }}][of]" type="number" min="1" value="{{ $part['of'] }}" placeholder="von y" class="min-w-0 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                            </div>
                        @endforeach
                    </div>
                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Bezeichnung leeren, um einen Teil zu entfernen.</p>
                </fieldset>

                <div>
                    <x-input-label for="note-{{ $ruleSet->id }}" value="Hinweis für Teilnehmer (optional)" />
                    <textarea id="note-{{ $ruleSet->id }}" name="note" rows="2" maxlength="1000" class="{{ $input }}">{{ $rule['note'] ?? '' }}</textarea>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 pt-2 border-t border-slate-100 dark:border-slate-700">
                    <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <input type="checkbox" name="verified" value="1" @checked($ruleSet->verified_at) class="rounded border-slate-300">
                        Fachlich geprüft (mit amtlicher Prüfungsordnung abgeglichen)
                    </label>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:opacity-90">Speichern</button>
                </div>
            </form>
        @empty
            <p class="text-sm text-slate-600 dark:text-slate-400">Für diesen Kurs ist noch kein Regelwerk angelegt.</p>
        @endforelse
    </div>
</x-superadmin-layout>

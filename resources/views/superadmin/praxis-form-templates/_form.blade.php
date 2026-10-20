@php
    $template = $template ?? null;
    $initialFields = old('fields', $template?->fields ?? []);
    if (is_array($initialFields)) {
        $initialFields = collect($initialFields)->map(fn ($f) => [
            'label' => $f['label'] ?? '',
            'type' => $f['type'] ?? 'text',
            'required' => ! empty($f['required']),
            'options' => is_array($f['options'] ?? null) ? implode("\n", $f['options']) : ($f['options'] ?? ''),
            'help_text' => $f['help_text'] ?? '',
        ])->values()->all();
    }
    $selectedCourseIds = old('course_ids', $template?->course_ids ?? []);
@endphp

<div x-data="{ fields: {{ json_encode($initialFields, JSON_HEX_APOS | JSON_HEX_QUOT) }} }" class="space-y-5">
    <div class="grid sm:grid-cols-2 gap-4">
        <div>
            <x-input-label for="title" value="Titel (intern)" />
            <x-text-input id="title" name="title" type="text" class="mt-1 block w-full" :value="old('title', $template?->title)" required />
            <x-input-error :messages="$errors->get('title')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="min_progress_percent" value="Buchbar ab % Kursfortschritt" />
            <x-text-input id="min_progress_percent" name="min_progress_percent" type="number" min="0" max="100"
                          class="mt-1 block w-full" :value="old('min_progress_percent', $template?->min_progress_percent ?? 50)" required />
            <p class="text-xs text-slate-400 mt-1">Vor Erreichen dieses Fortschritts sieht der Lerner nur eine Hürden-Meldung statt des Formulars.</p>
            <x-input-error :messages="$errors->get('min_progress_percent')" class="mt-2" />
        </div>
    </div>

    <div>
        <x-input-label value="Zugewiesene Kurse" />
        <p class="text-xs text-slate-400 mt-1 mb-2">Ein Kurs kann nur einem aktiven Formular zugewiesen sein. Bereits vergebene Kurse sind ausgegraut.</p>
        <div class="grid sm:grid-cols-2 gap-x-4 gap-y-1.5 max-h-56 overflow-y-auto border border-slate-200 dark:border-slate-600 rounded-lg p-3">
            @foreach ($courses as $course)
                @php($isTaken = in_array($course->id, $assignedCourseIds, true))
                <label class="inline-flex items-center gap-2 text-sm {{ $isTaken ? 'text-slate-300 dark:text-slate-600' : 'text-slate-600 dark:text-slate-300' }}">
                    <input type="checkbox" name="course_ids[]" value="{{ $course->id }}"
                           @checked(in_array($course->id, $selectedCourseIds, true)) @disabled($isTaken)
                           class="rounded border-slate-300 dark:bg-slate-700 dark:border-slate-600">
                    {{ $course->name }}
                    @if ($isTaken)
                        <span class="text-[11px]">(vergeben)</span>
                    @endif
                </label>
            @endforeach
        </div>
        <x-input-error :messages="$errors->get('course_ids')" class="mt-2" />
    </div>

    <div>
        <x-input-label value="Formularfelder" />
        <p class="text-xs text-slate-400 mt-1 mb-2">Diese Felder füllt der Lerner aus, sobald er die Fortschritts-Hürde erreicht hat.</p>

        <div class="space-y-3">
            <template x-for="(field, index) in fields" :key="index">
                <div class="border border-slate-200 dark:border-slate-600 rounded-lg p-3 space-y-3">
                    <div class="grid sm:grid-cols-[1fr_160px_auto] gap-3 items-start">
                        <div>
                            <label class="text-xs text-slate-500 dark:text-slate-400">Beschriftung</label>
                            <input type="text" :name="'fields['+index+'][label]'" x-model="field.label" placeholder="z. B. Telefonnummer"
                                   class="mt-0.5 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                        </div>
                        <div>
                            <label class="text-xs text-slate-500 dark:text-slate-400">Typ</label>
                            <select :name="'fields['+index+'][type]'" x-model="field.type"
                                    class="mt-0.5 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                                <option value="text">Text</option>
                                <option value="textarea">Mehrzeiliger Text</option>
                                <option value="date">Datum</option>
                                <option value="select">Dropdown-Auswahl</option>
                                <option value="radio">Auswahl (Radio)</option>
                                <option value="checkbox">Checkbox (Ja/Nein)</option>
                                <option value="location">Ort (von der Bootsschule verwaltet)</option>
                            </select>
                        </div>
                        <div class="flex items-end h-full pb-1">
                            <button type="button" @click="fields.splice(index, 1)" class="text-xs text-red-500 hover:underline whitespace-nowrap">Entfernen</button>
                        </div>
                    </div>

                    <div x-show="field.type === 'select' || field.type === 'radio'" style="display: none">
                        <label class="text-xs text-slate-500 dark:text-slate-400">Optionen (eine Zeile pro Option)</label>
                        <textarea :name="'fields['+index+'][options]'" x-model="field.options" rows="3"
                                  class="mt-0.5 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm"></textarea>
                    </div>

                    <p x-show="field.type === 'location'" style="display: none" class="text-xs text-slate-400">
                        Die Auswahlmöglichkeiten pflegt jede Bootsschule selbst im Tab „Orte&quot; ihres Admin-Bereichs
                        (Praxis- und gekoppelter Prüfungsort).
                    </p>

                    <div>
                        <label class="text-xs text-slate-500 dark:text-slate-400">Hilfetext (optional)</label>
                        <input type="text" :name="'fields['+index+'][help_text]'" x-model="field.help_text"
                               class="mt-0.5 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                    </div>

                    <label class="inline-flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                        <input type="hidden" :name="'fields['+index+'][required]'" value="0">
                        <input type="checkbox" :name="'fields['+index+'][required]'" value="1" x-model="field.required"
                               class="rounded border-slate-300 dark:bg-slate-700 dark:border-slate-600">
                        Pflichtfeld
                    </label>
                </div>
            </template>
        </div>

        <button type="button"
                @click="fields.push({ label: '', type: 'text', required: false, options: '', help_text: '' })"
                class="mt-3 px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 text-sm font-medium hover:bg-slate-50 dark:hover:bg-slate-700 transition">
            + Feld hinzufügen
        </button>
        <x-input-error :messages="$errors->get('fields')" class="mt-2" />
    </div>
</div>

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Praxis-Anmeldung bearbeiten</h2>
    </x-slot>

    <a href="{{ route('admin.praxis-form-submissions.show', $submission) }}" class="text-sm text-slate-500 hover:underline inline-block mb-4">&larr; Zurück zur Anmeldung</a>

    <div class="max-w-2xl bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-8">
        <h3 class="font-semibold text-lg text-slate-800 dark:text-white mb-1">{{ $submission->template->title }}</h3>
        <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">
            {{ $submission->user->display_name ?? $submission->user->email }} &mdash; {{ $submission->course->name ?? '—' }}
        </p>

        <form method="POST" action="{{ route('admin.praxis-form-submissions.update', $submission) }}" class="space-y-5">
            @csrf
            @method('PATCH')
            @foreach ($submission->template->fields as $field)
                @continue($field['type'] === 'location' && $submissionLocations->isEmpty())
                @php($fieldId = 'field-'.$field['key'])
                @php($current = old($field['key'], $field['type'] === 'location' ? ($submission->data[$field['key'].'_location_id'] ?? null) : ($submission->data[$field['key']] ?? null)))
                <div>
                    @if ($field['type'] !== 'checkbox')
                        <x-input-label :for="$fieldId" :value="$field['label'].($field['required'] ? ' *' : '')" />
                    @endif

                    @switch ($field['type'])
                        @case('textarea')
                            <textarea id="{{ $fieldId }}" name="{{ $field['key'] }}" rows="3" @if ($field['required']) required @endif
                                      class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ $current }}</textarea>
                            @break
                        @case('date')
                            <x-text-input :id="$fieldId" :name="$field['key']" type="date" class="mt-1 block w-full" :value="$current" :required="$field['required']" />
                            @break
                        @case('select')
                            <select id="{{ $fieldId }}" name="{{ $field['key'] }}" @if ($field['required']) required @endif
                                    class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                                <option value="" disabled @selected(! $current)>Bitte wählen…</option>
                                @foreach ($field['options'] as $option)
                                    <option value="{{ $option }}" @selected($current === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                            @break
                        @case('radio')
                            <div class="mt-1 flex flex-wrap gap-4">
                                @foreach ($field['options'] as $option)
                                    <label class="inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                                        <input type="radio" name="{{ $field['key'] }}" value="{{ $option }}" @checked($current === $option) @if ($field['required']) required @endif
                                               class="border-slate-300 dark:bg-slate-700 dark:border-slate-600">
                                        {{ $option }}
                                    </label>
                                @endforeach
                            </div>
                            @break
                        @case('location')
                            <select id="{{ $fieldId }}" name="{{ $field['key'] }}" @if ($field['required']) required @endif
                                    class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                                <option value="" disabled @selected(! $current)>Bitte wählen…</option>
                                @foreach ($submissionLocations as $location)
                                    <option value="{{ $location->id }}" @selected($current === $location->id)>
                                        {{ $location->praxis_ort }}
                                        @if ($location->pruefung_ort !== $location->praxis_ort)
                                            (Prüfung: {{ $location->pruefung_ort }})
                                        @endif
                                        @if ($location->hasTheorieOrt())
                                            (Theorie: {{ $location->theorie_ort }})
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                            @break
                        @case('checkbox')
                            <label class="inline-flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                                <input type="hidden" name="{{ $field['key'] }}" value="0">
                                <input type="checkbox" id="{{ $fieldId }}" name="{{ $field['key'] }}" value="1" @checked($current) @if ($field['required']) required @endif
                                       class="mt-0.5 rounded border-slate-300 dark:bg-slate-700 dark:border-slate-600">
                                <span>{{ $field['label'] }}{{ $field['required'] ? ' *' : '' }}</span>
                            </label>
                            @break
                        @default
                            <x-text-input :id="$fieldId" :name="$field['key']" type="text" class="mt-1 block w-full" :value="$current" :required="$field['required']" />
                    @endswitch

                    @if (! empty($field['help_text']))
                        <p class="text-xs text-slate-400 mt-1">{{ $field['help_text'] }}</p>
                    @endif
                    <x-input-error :messages="$errors->get($field['key'])" class="mt-2" />
                </div>
            @endforeach

            <button type="submit" class="w-full inline-flex items-center justify-center px-5 py-2.5 rounded-lg text-white text-sm font-semibold hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                Änderungen speichern
            </button>
        </form>

        <form method="POST" action="{{ route('admin.praxis-form-submissions.destroy', $submission) }}"
              onsubmit="return confirm('Diese Anmeldung wirklich unwiderruflich löschen? Alle dazu vorgeschlagenen Termine gehen dabei ebenfalls verloren.');"
              class="mt-6 pt-6 border-t border-slate-100 dark:border-slate-700">
            @csrf
            @method('DELETE')
            <button type="submit" class="text-sm text-rose-600 hover:underline">Anmeldung löschen</button>
        </form>
    </div>
</x-app-layout>

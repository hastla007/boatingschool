{{--
    Eine einzelne Praxis-Anmeldung (Formulardaten + Prüfungstermin-Verwaltung)
    -- gemeinsam genutzt von der Teilnehmer-Detailseite (mehrere Anmeldungen
    untereinander) und der eigenständigen Anmeldungs-Detailseite (genau eine).
    Erwartet $submission und $submissionLocations (Orte, die für den Kurs der
    Anmeldung hinterlegt sind, siehe TenantCourseLocation).
--}}
<div class="border-b border-slate-100 dark:border-slate-700 py-3 last:border-0">
    <div class="flex items-start justify-between gap-4 mb-2">
        <div>
            <span class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $submission->course->name ?? '—' }}</span>
            <span class="block text-xs text-slate-600 dark:text-slate-400">{{ $submission->template->title ?? 'Formular gelöscht' }}</span>
        </div>
        <span class="text-xs text-slate-600 dark:text-slate-400 whitespace-nowrap">{{ \App\Support\GermanTime::format($submission->created_at, 'd.m.Y H:i') }}</span>
    </div>
    @if ($submission->template && ! empty($submission->template->fields))
        <dl class="divide-y divide-slate-100 dark:divide-slate-700 border-t border-slate-100 dark:border-slate-700 text-sm">
            @foreach ($submission->template->fields as $field)
                <div class="py-2 grid grid-cols-2 gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">{{ $field['label'] }}</dt>
                    <dd class="text-slate-800 dark:text-slate-200 font-medium">
                        @php($value = $submission->data[$field['key']] ?? null)
                        @if ($field['type'] === 'checkbox')
                            {{ $value ? 'Ja' : 'Nein' }}
                        @else
                            {{ $value ?: '—' }}
                        @endif
                    </dd>
                </div>
                @if ($field['type'] === 'location')
                    @if (! empty($submission->data[$field['key'].'_adresse']))
                        <div class="py-2 grid grid-cols-2 gap-4">
                            <dt class="text-slate-500 dark:text-slate-400">Anschrift Praxisort</dt>
                            <dd class="text-slate-800 dark:text-slate-200 font-medium">{{ $submission->data[$field['key'].'_adresse'] }}</dd>
                        </div>
                    @endif
                    @if (! empty($submission->data[$field['key'].'_pruefungsort']))
                        <div class="py-2 grid grid-cols-2 gap-4">
                            <dt class="text-slate-500 dark:text-slate-400">Prüfungsort</dt>
                            <dd class="text-slate-800 dark:text-slate-200 font-medium">{{ $submission->data[$field['key'].'_pruefungsort'] }}</dd>
                        </div>
                    @endif
                    @if (! empty($submission->data[$field['key'].'_pruefungsort_adresse']))
                        <div class="py-2 grid grid-cols-2 gap-4">
                            <dt class="text-slate-500 dark:text-slate-400">Anschrift Prüfungsort</dt>
                            <dd class="text-slate-800 dark:text-slate-200 font-medium">{{ $submission->data[$field['key'].'_pruefungsort_adresse'] }}</dd>
                        </div>
                    @endif
                    @if (! empty($submission->data[$field['key'].'_pruefungsausschuss']))
                        <div class="py-2 grid grid-cols-2 gap-4">
                            <dt class="text-slate-500 dark:text-slate-400">Prüfungskommission</dt>
                            <dd class="text-slate-800 dark:text-slate-200 font-medium">
                                {{ $submission->data[$field['key'].'_pruefungsausschuss'] }}
                                @if (! empty($submission->data[$field['key'].'_pruefungsausschuss_adresse']))
                                    <span class="block text-xs text-slate-600 dark:text-slate-400 font-normal">{{ $submission->data[$field['key'].'_pruefungsausschuss_adresse'] }}</span>
                                @endif
                                @if (! empty($submission->data[$field['key'].'_pruefungsausschuss_kurse']))
                                    <span class="flex flex-wrap items-center gap-1 mt-1 text-xs text-slate-600 dark:text-slate-400 font-normal">
                                        Zuständig für:
                                        @foreach ($submission->data[$field['key'].'_pruefungsausschuss_kurse'] as $kursName)
                                            <span @class([
                                                'inline-flex items-center px-1.5 py-0.5 rounded-full text-[11px] font-medium',
                                                'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' => $kursName === ($submission->course->name ?? null),
                                                'bg-slate-100 text-slate-500 dark:bg-slate-700/50 dark:text-slate-400' => $kursName !== ($submission->course->name ?? null),
                                            ])>{{ $kursName }}</span>
                                        @endforeach
                                    </span>
                                @endif
                            </dd>
                        </div>
                    @endif
                    @if (! empty($submission->data[$field['key'].'_theorieort']))
                        <div class="py-2 grid grid-cols-2 gap-4">
                            <dt class="text-slate-500 dark:text-slate-400">Theorieort</dt>
                            <dd class="text-slate-800 dark:text-slate-200 font-medium">{{ $submission->data[$field['key'].'_theorieort'] }}</dd>
                        </div>
                    @endif
                    @if (! empty($submission->data[$field['key'].'_theorieort_adresse']))
                        <div class="py-2 grid grid-cols-2 gap-4">
                            <dt class="text-slate-500 dark:text-slate-400">Anschrift Theorieort</dt>
                            <dd class="text-slate-800 dark:text-slate-200 font-medium">{{ $submission->data[$field['key'].'_theorieort_adresse'] }}</dd>
                        </div>
                    @endif
                @endif
            @endforeach
        </dl>
    @endif

    <x-admin.praxis-appointment-slot :submission="$submission" :appointment-slot="0" label="Prüfungstermin" :locations="$submissionLocations" />

    @if ($submission->isExamDateConfirmed())
        @foreach ($submission->practiceSlotNumbers() as $practiceSlot)
            <x-admin.praxis-appointment-slot
                :submission="$submission"
                :appointment-slot="$practiceSlot"
                :label="$submission->labelForSlot($practiceSlot)"
                :locations="$submissionLocations"
            />
        @endforeach

        <div class="mt-3 pt-3 border-t border-slate-100 dark:border-slate-700">
            <h4 class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-2">Weiteren Termin anlegen</h4>
            <p class="text-xs text-slate-600 dark:text-slate-400 mb-2">
                Frei benannt und terminiert -- z. B. "Kleine Praxisstunde (1h)", "Große Praxisstunde (4h)", "Navigationsstunde" oder "Onlinekurs".
                So viele wie nötig, keine feste Anzahl.
            </p>
            <form method="POST" action="{{ route('admin.praxis-form-submissions.exam-appointments.store', $submission) }}" class="flex flex-wrap items-end gap-2" x-data="{ location: '' }">
                @csrf
                <div>
                    <label for="praxis_label_{{ $submission->id }}" class="block text-xs text-slate-500 mb-1">Bezeichnung</label>
                    <input type="text" id="praxis_label_{{ $submission->id }}" name="label" placeholder="z. B. Navigationsstunde" required maxlength="255" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm w-56">
                </div>
                <div>
                    <label for="praxis_exam_date_{{ $submission->id }}" class="block text-xs text-slate-500 mb-1">Beginn</label>
                    <input type="datetime-local" id="praxis_exam_date_{{ $submission->id }}" name="exam_date" required class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                </div>
                <div>
                    <label for="praxis_ends_at_{{ $submission->id }}" class="block text-xs text-slate-500 mb-1">Ende (optional, z. B. 16:00-18:00 Uhr)</label>
                    <input type="datetime-local" id="praxis_ends_at_{{ $submission->id }}" name="ends_at" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                </div>
                <div>
                    <label for="praxis_location_{{ $submission->id }}" class="block text-xs text-slate-500 mb-1">Ort</label>
                    <select id="praxis_location_{{ $submission->id }}" name="location" x-model="location" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                        <option value="">&mdash;</option>
                        @foreach ($submissionLocations as $location)
                            <option value="{{ $location->id }}">
                                {{ $location->praxis_ort }}
                                @if ($location->pruefung_ort !== $location->praxis_ort)
                                    (Prüfung: {{ $location->pruefung_ort }})
                                @endif
                                @if ($location->hasTheorieOrt())
                                    (Theorie: {{ $location->theorie_ort }})
                                @endif
                            </option>
                        @endforeach
                        <option value="online">Online</option>
                    </select>
                </div>
                <div x-show="location === 'online'" style="display: none">
                    <label for="praxis_online_meeting_url_{{ $submission->id }}" class="block text-xs text-slate-500 mb-1">Link Onlinemeeting</label>
                    <input type="url" id="praxis_online_meeting_url_{{ $submission->id }}" name="online_meeting_url" placeholder="https://..." class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm w-56">
                </div>
                <div class="w-full">
                    <label for="praxis_description_{{ $submission->id }}" class="block text-xs text-slate-500 mb-1">Beschreibung (optional, für den Teilnehmer sichtbar)</label>
                    <textarea id="praxis_description_{{ $submission->id }}" name="description" rows="2" maxlength="2000" placeholder="z. B. Treffpunkt, Mitzubringendes, Ablauf ..." class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm"></textarea>
                </div>
                <button type="submit" class="px-3 py-1.5 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                    Vorschlagen
                </button>
            </form>
            @error('exam_date')
                <p class="text-rose-600 text-xs mt-2">{{ $message }}</p>
            @enderror
        </div>
    @else
        <p class="mt-3 pt-3 border-t border-slate-100 dark:border-slate-700 text-xs text-slate-600 dark:text-slate-400">
            Weitere Termine (z. B. Praxisstunden, Navigationsstunde, Onlinekurs) können frei angelegt werden, sobald der Prüfungstermin fixiert ist.
        </p>
    @endif
</div>

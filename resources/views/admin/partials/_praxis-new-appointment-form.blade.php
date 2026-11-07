{{-- Formular „Weiteren Termin anlegen“ (Praxisstunde o. ä.). Erwartet $action, $key, $locations, $courseId (nur ohne Anmeldung). --}}
    <form method="POST" action="{{ $action }}" class="flex flex-wrap items-end gap-2" x-data="{ location: '' }">
        @csrf
        @if ($courseId)
            <input type="hidden" name="course_id" value="{{ $courseId }}">
        @endif
        <div>
            <label for="praxis_label_{{ $key }}" class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Bezeichnung</label>
            <input type="text" id="praxis_label_{{ $key }}" name="label" placeholder="z. B. Navigationsstunde" required maxlength="255" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm w-56">
        </div>
        <div>
            <label for="praxis_exam_date_{{ $key }}" class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Beginn</label>
            <input type="datetime-local" id="praxis_exam_date_{{ $key }}" name="exam_date" required class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
        </div>
        <div>
            <label for="praxis_ends_at_{{ $key }}" class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Ende (optional, z. B. 16:00-18:00 Uhr)</label>
            <input type="datetime-local" id="praxis_ends_at_{{ $key }}" name="ends_at" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
        </div>
        <div>
            <label for="praxis_location_{{ $key }}" class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Ort</label>
            <select id="praxis_location_{{ $key }}" name="location" x-model="location" class="w-full max-w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                <option value="">&mdash;</option>
                @foreach ($locations as $location)
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
            <label for="praxis_online_meeting_url_{{ $key }}" class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Link Onlinemeeting</label>
            <input type="url" id="praxis_online_meeting_url_{{ $key }}" name="online_meeting_url" placeholder="https://..." class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm w-56">
        </div>
        <div class="w-full">
            <label for="praxis_description_{{ $key }}" class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Beschreibung (optional, für den Teilnehmer sichtbar)</label>
            <textarea id="praxis_description_{{ $key }}" name="description" rows="2" maxlength="2000" placeholder="z. B. Treffpunkt, Mitzubringendes, Ablauf ..." class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm"></textarea>
        </div>
        <button type="submit" class="px-3 py-1.5 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
            Vorschlagen
        </button>
    </form>

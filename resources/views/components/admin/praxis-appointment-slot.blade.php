@props(['submission', 'appointmentSlot', 'label', 'locations' => null])
@php
    $appointmentsForSlot = $submission->appointmentsForSlot($appointmentSlot);
    $latestAppointment = $appointmentsForSlot->last();
    $confirmedAppointment = $appointmentsForSlot->firstWhere('response', 'accepted');
    $locations ??= collect();
@endphp

{{-- Bug-Review (confirm() ohne Alpine-Scope): diese Komponente wird auf der
     Teilnehmer-Detailseite und auf praxis-form-submissions/show außerhalb jedes
     x-data-Bereichs gerendert -- die @submit-Rückfragen bei "Absagen" und
     "Zurückziehen" wurden dort nie initialisiert. Eigener Scope hier, damit sie
     unabhängig von der einbindenden Seite immer greifen. --}}
<div class="mt-3 pt-3 border-t border-slate-100 dark:border-slate-700" x-data="{}">
    <h4 class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-2">{{ $label }}</h4>

    @if ($confirmedAppointment)
        <div class="text-sm text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/30 rounded-lg px-3 py-2 mb-2">
            <div class="flex items-center gap-2">
                <x-icon name="check-circle" class="w-4 h-4 shrink-0" />
                Fix: {{ $confirmedAppointment->formattedRange() }} Uhr
                @if ($confirmedAppointment->locationLabel())
                    &middot; {{ $confirmedAppointment->locationLabel() }}
                @endif
            </div>
            @if ($confirmedAppointment->is_online && $confirmedAppointment->online_meeting_url)
                <a href="{{ $confirmedAppointment->online_meeting_url }}" target="_blank" rel="noopener" class="block mt-1 text-xs underline break-all">{{ $confirmedAppointment->online_meeting_url }}</a>
            @endif
            @if ($confirmedAppointment->description)
                <p class="mt-1 text-xs text-emerald-800 dark:text-emerald-300">{{ $confirmedAppointment->description }}</p>
            @endif
            {{-- Bug-Review F4: ein bereits fixierter Termin ließ sich bisher
                 nicht mehr absagen, z. B. wenn der Prüfungsausschuss ihn doch
                 nicht wahrnehmen kann. --}}
            <form method="POST" action="{{ route('admin.praxis-form-submissions.exam-appointments.cancel', [$submission, $confirmedAppointment]) }}" class="mt-2"
                  @submit="if (! confirm('Diesen Termin wirklich absagen?')) $event.preventDefault()">
                @csrf
                @method('PATCH')
                <button type="submit" class="text-xs text-red-600 dark:text-red-400 hover:underline">Absagen</button>
            </form>
        </div>
    @else
        @if ($latestAppointment && $latestAppointment->isPending())
            <div class="text-sm text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 rounded-lg px-3 py-2 mb-2">
                <div>
                    Vorschlag Runde {{ $latestAppointment->round }} ({{ $latestAppointment->formattedRange() }} Uhr) wartet auf Rückmeldung des Teilnehmers.
                    @if ($latestAppointment->locationLabel())
                        &middot; {{ $latestAppointment->locationLabel() }}
                    @endif
                </div>
                @if ($latestAppointment->is_online && $latestAppointment->online_meeting_url)
                    <a href="{{ $latestAppointment->online_meeting_url }}" target="_blank" rel="noopener" class="block mt-1 text-xs underline break-all">{{ $latestAppointment->online_meeting_url }}</a>
                @endif
                @if ($latestAppointment->description)
                    <p class="mt-1 text-xs text-amber-800 dark:text-amber-300">{{ $latestAppointment->description }}</p>
                @endif
                {{-- Bug-Review F4: ein eigener, noch offener Vorschlag ließ
                     sich bisher nicht zurückziehen (z. B. bei einem
                     Tippfehler im Datum). --}}
                <form method="POST" action="{{ route('admin.praxis-form-submissions.exam-appointments.withdraw', [$submission, $latestAppointment]) }}" class="mt-2"
                      @submit="if (! confirm('Diesen Terminvorschlag wirklich zurückziehen?')) $event.preventDefault()">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="text-xs text-amber-800 dark:text-amber-300 hover:underline">Zurückziehen</button>
                </form>
            </div>
        @else
            <form method="POST" action="{{ route('admin.praxis-form-submissions.exam-appointments.store', $submission) }}"
                  class="flex flex-wrap items-end gap-2 mb-2" x-data="{ location: '' }">
                @csrf
                <input type="hidden" name="slot" value="{{ $appointmentSlot }}">
                <div>
                    <label for="slot_exam_date_{{ $submission->id }}_{{ $appointmentSlot }}" class="block text-xs text-slate-500 dark:text-slate-400 mb-1">
                        {{ $latestAppointment ? 'Neuen Termin vorschlagen (Runde '.($latestAppointment->round + 1).')' : 'Termin vorschlagen' }}
                    </label>
                    <input type="datetime-local" id="slot_exam_date_{{ $submission->id }}_{{ $appointmentSlot }}" name="exam_date" required class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                </div>
                <div>
                    <label for="slot_ends_at_{{ $submission->id }}_{{ $appointmentSlot }}" class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Ende (optional, z. B. für 16:00-18:00 Uhr)</label>
                    <input type="datetime-local" id="slot_ends_at_{{ $submission->id }}_{{ $appointmentSlot }}" name="ends_at" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                </div>
                <div>
                    <label for="slot_location_{{ $submission->id }}_{{ $appointmentSlot }}" class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Ort</label>
                    <select id="slot_location_{{ $submission->id }}_{{ $appointmentSlot }}" name="location" x-model="location" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
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
                    <label for="slot_online_meeting_url_{{ $submission->id }}_{{ $appointmentSlot }}" class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Link Onlinemeeting</label>
                    <input type="url" id="slot_online_meeting_url_{{ $submission->id }}_{{ $appointmentSlot }}" name="online_meeting_url" placeholder="https://..." class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm w-56">
                </div>
                <div class="w-full">
                    <label for="slot_description_{{ $submission->id }}_{{ $appointmentSlot }}" class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Beschreibung (optional, für den Teilnehmer sichtbar)</label>
                    <textarea id="slot_description_{{ $submission->id }}_{{ $appointmentSlot }}" name="description" rows="2" maxlength="2000" placeholder="z. B. Treffpunkt, Mitzubringendes, Ablauf ..." class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm"></textarea>
                </div>
                <button type="submit" class="px-3 py-1.5 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                    Vorschlagen
                </button>
            </form>
            @error('exam_date')
                <p class="text-rose-600 text-xs mb-2">{{ $message }}</p>
            @enderror
        @endif
    @endif

    @if ($appointmentsForSlot->isNotEmpty())
        <div class="space-y-1">
            @foreach ($appointmentsForSlot as $appointment)
                <div class="text-xs text-slate-600 dark:text-slate-400">
                    Runde {{ $appointment->round }}: {{ $appointment->formattedRange() }} Uhr
                    <span class="inline-block px-1.5 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400">
                        {{ ['pending' => 'offen', 'accepted' => 'angenommen', 'declined' => 'abgelehnt', 'withdrawn' => 'zurückgezogen', 'cancelled' => 'abgesagt'][$appointment->response] ?? $appointment->response }}
                    </span>
                    @if ($appointment->locationLabel())
                        &middot; {{ $appointment->locationLabel() }}
                    @endif
                    @if ($appointment->response_comment)
                        <span class="italic">&ndash; &bdquo;{{ $appointment->response_comment }}&ldquo;</span>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>

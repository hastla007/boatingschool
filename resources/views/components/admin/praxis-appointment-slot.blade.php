@props(['submission', 'appointmentSlot', 'label', 'locations' => null])
@php
    $appointmentsForSlot = $submission->appointmentsForSlot($appointmentSlot);
    $latestAppointment = $appointmentsForSlot->last();
    $confirmedAppointment = $appointmentsForSlot->firstWhere('response', 'accepted');
    $locations ??= collect();
@endphp

<div class="mt-3 pt-3 border-t border-slate-100 dark:border-slate-700">
    <h4 class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-2">{{ $label }}</h4>

    @if ($confirmedAppointment)
        <div class="flex items-center gap-2 text-sm text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/30 rounded-lg px-3 py-2 mb-2">
            <x-icon name="check-circle" class="w-4 h-4 shrink-0" />
            Fix: {{ $confirmedAppointment->formattedRange() }} Uhr
            @if ($confirmedAppointment->location)
                &middot; {{ $confirmedAppointment->location->praxis_ort }}
            @endif
        </div>
    @else
        @if ($latestAppointment && $latestAppointment->isPending())
            <div class="text-sm text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 rounded-lg px-3 py-2 mb-2">
                Vorschlag Runde {{ $latestAppointment->round }} ({{ $latestAppointment->formattedRange() }} Uhr) wartet auf Rückmeldung des Teilnehmers.
                @if ($latestAppointment->location)
                    &middot; {{ $latestAppointment->location->praxis_ort }}
                @endif
            </div>
        @else
            <form method="POST" action="{{ route('admin.praxis-form-submissions.exam-appointments.store', $submission) }}" class="flex flex-wrap items-end gap-2 mb-2">
                @csrf
                <input type="hidden" name="slot" value="{{ $appointmentSlot }}">
                <div>
                    <label class="block text-xs text-slate-500 mb-1">
                        {{ $latestAppointment ? 'Neuen Termin vorschlagen (Runde '.($latestAppointment->round + 1).')' : 'Termin vorschlagen' }}
                    </label>
                    <input type="datetime-local" name="exam_date" required class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-slate-500 mb-1">Ende (optional, z. B. für 16:00-18:00 Uhr)</label>
                    <input type="datetime-local" name="ends_at" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                </div>
                @if ($locations->isNotEmpty())
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Ort</label>
                        <select name="tenant_course_location_id" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
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
                        </select>
                    </div>
                @endif
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
                <div class="text-xs text-slate-400">
                    Runde {{ $appointment->round }}: {{ $appointment->formattedRange() }} Uhr
                    <span class="inline-block px-1.5 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-500">
                        {{ ['pending' => 'offen', 'accepted' => 'angenommen', 'declined' => 'abgelehnt'][$appointment->response] }}
                    </span>
                    @if ($appointment->location)
                        &middot; {{ $appointment->location->praxis_ort }}
                    @endif
                    @if ($appointment->response_comment)
                        <span class="italic">&ndash; &bdquo;{{ $appointment->response_comment }}&ldquo;</span>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>

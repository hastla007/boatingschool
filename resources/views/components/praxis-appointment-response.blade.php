@props(['submission', 'course', 'appointmentSlot', 'label'])
@php
    $appointmentsForSlot = $submission->appointmentsForSlot($appointmentSlot);
    $latestAppointment = $appointmentsForSlot->last();
    $confirmedAppointment = $appointmentsForSlot->firstWhere('response', 'accepted');
    // Beim Prüfungstermin (slot 0) ist die Bezeichnung immer gleich und
    // grammatisch bekannt ("deinen Prüfungstermin") -- bei frei benannten
    // Terminen (Kleine Praxisstunde, Navigationsstunde, ...) ist das
    // grammatische Geschlecht unbekannt, daher wird dort in Anführungszeichen
    // auf die Bezeichnung verwiesen statt sie in den Satz einzubauen.
    $isExamSlot = $appointmentSlot === 0;
@endphp

@if ($confirmedAppointment)
    <div class="mt-6 flex items-center gap-2 bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400 rounded-xl p-4 text-sm">
        <x-icon name="check-circle" class="w-5 h-5 shrink-0" />
        @if ($isExamSlot)
            Dein {{ $label }} ist fix: {{ $confirmedAppointment->formattedRange() }} Uhr.
        @else
            &bdquo;{{ $label }}&ldquo; ist fix: {{ $confirmedAppointment->formattedRange() }} Uhr.
        @endif
    </div>
@elseif ($latestAppointment && $latestAppointment->isPending())
    <div class="mt-6 bg-amber-50 dark:bg-amber-900/30 rounded-xl p-4" x-data="{ declining: false }">
        <p class="text-sm font-medium text-slate-800 dark:text-white mb-1">
            @if ($isExamSlot)
                Vorschlag für deinen {{ $label }}
            @else
                Vorschlag für &bdquo;{{ $label }}&ldquo;
            @endif
        </p>
        <p class="text-sm text-slate-600 dark:text-slate-300 mb-3">{{ $latestAppointment->formattedRange() }} Uhr &ndash; passt dir dieser Termin?</p>

        <div x-show="! declining" class="flex gap-2">
            <form method="POST" action="{{ route('praxis-pruefung.exam-appointments.respond', [$course, $latestAppointment]) }}">
                @csrf
                <input type="hidden" name="decision" value="accepted">
                <button type="submit" class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:opacity-90 transition">Passt mir</button>
            </form>
            <button type="button" @click="declining = true" class="px-4 py-2 rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200 text-sm font-medium hover:bg-slate-200 dark:hover:bg-slate-600 transition">Passt mir nicht</button>
        </div>

        <form x-show="declining" style="display: none" method="POST" action="{{ route('praxis-pruefung.exam-appointments.respond', [$course, $latestAppointment]) }}" class="space-y-2">
            @csrf
            <input type="hidden" name="decision" value="declined">
            <textarea name="comment" rows="2" maxlength="2000" placeholder="Grund oder Terminvorschlag (optional)" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm"></textarea>
            <div class="flex gap-2">
                <button type="submit" class="px-4 py-2 rounded-lg bg-rose-600 text-white text-sm font-medium hover:opacity-90 transition">Termin passt nicht</button>
                <button type="button" @click="declining = false" class="px-4 py-2 rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200 text-sm font-medium hover:bg-slate-200 dark:hover:bg-slate-600 transition">Abbrechen</button>
            </div>
        </form>
        @error('decision')
            <p class="text-rose-600 text-xs mt-2">{{ $message }}</p>
        @enderror
    </div>
@elseif ($latestAppointment && $latestAppointment->response === 'declined')
    <div class="mt-6 bg-slate-50 dark:bg-slate-700/40 rounded-xl p-4 text-sm text-slate-600 dark:text-slate-300">
        @if ($isExamSlot)
            Du hast den letzten Vorschlag ({{ $latestAppointment->formattedRange() }} Uhr) abgelehnt. Deine Bootsschule schlägt dir in Kürze einen neuen Termin vor.
        @else
            Du hast den letzten Vorschlag für &bdquo;{{ $label }}&ldquo; ({{ $latestAppointment->formattedRange() }} Uhr) abgelehnt. Deine Bootsschule schlägt dir in Kürze einen neuen Termin vor.
        @endif
    </div>
@else
    <div class="mt-6 bg-slate-50 dark:bg-slate-700/40 rounded-xl p-4 text-sm text-slate-600 dark:text-slate-300">
        @if ($isExamSlot)
            Deine Bootsschule schlägt dir in Kürze einen Prüfungstermin vor.
        @else
            Deine Bootsschule schlägt dir in Kürze einen Termin für &bdquo;{{ $label }}&ldquo; vor.
        @endif
    </div>
@endif

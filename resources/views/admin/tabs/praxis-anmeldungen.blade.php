<p class="text-slate-500 mb-6">
    Anmeldungen zu „Praxis &amp; Prüfung&quot;, die Lerner über ein ihnen zugewiesenes Praxisformular abgeschickt haben.
    Diese Daten dienen dir als Grundlage für die Anmeldung bei der Prüfungskommission. Klick auf eine Anmeldung für die Details.
</p>

<div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
    <form method="GET" action="{{ route('admin.dashboard') }}" class="flex flex-wrap gap-2 p-4 border-b border-slate-100 dark:border-slate-700">
        <input type="hidden" name="tab" value="praxis-anmeldungen">
        <input type="text" name="anmeldung_search" value="{{ $anmeldungFilters['anmeldung_search'] ?? '' }}"
               placeholder="Name, E-Mail oder Kurs suchen…"
               class="flex-1 min-w-[220px] rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
        <select name="anmeldung_course" onchange="this.form.submit()" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            <option value="">Alle Kurse</option>
            @foreach ($courses as $course)
                <option value="{{ $course->id }}" @selected(($anmeldungFilters['anmeldung_course'] ?? '') === $course->id)>{{ $course->name }}</option>
            @endforeach
        </select>
        <div class="flex items-center gap-1.5">
            <label for="anmeldung_from" class="text-xs text-slate-500 dark:text-slate-400 whitespace-nowrap">Eingereicht von</label>
            <input type="date" id="anmeldung_from" name="anmeldung_from" value="{{ $anmeldungFilters['anmeldung_from'] ?? '' }}"
                   class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
        </div>
        <div class="flex items-center gap-1.5">
            <label for="anmeldung_to" class="text-xs text-slate-500 dark:text-slate-400 whitespace-nowrap">bis</label>
            <input type="date" id="anmeldung_to" name="anmeldung_to" value="{{ $anmeldungFilters['anmeldung_to'] ?? '' }}"
                   class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
        </div>
        <button type="submit" class="px-4 py-2 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 transition text-sm">Suchen</button>
        @if (($anmeldungFilters['anmeldung_search'] ?? '') !== '' || ($anmeldungFilters['anmeldung_course'] ?? '') !== '' || ($anmeldungFilters['anmeldung_from'] ?? '') !== '' || ($anmeldungFilters['anmeldung_to'] ?? '') !== '')
            <a href="{{ route('admin.dashboard', ['tab' => 'praxis-anmeldungen']) }}" class="text-sm text-slate-500 hover:underline self-center">Filter zurücksetzen</a>
        @endif
    </form>
    <table class="w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-700 text-slate-500 dark:text-slate-300 text-left">
            <tr>
                <th class="px-4 py-2">Teilnehmer</th>
                <th class="px-4 py-2">Kurs</th>
                <th class="px-4 py-2">Nächster Schritt</th>
                <th class="px-4 py-2">Eingereicht am</th>
                <th class="px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($praxisFormSubmissions as $submission)
                <tr class="border-t border-slate-100 dark:border-slate-700">
                    <td class="px-4 py-2">
                        <a href="{{ route('admin.praxis-form-submissions.show', $submission) }}" class="flex items-center gap-2 hover:underline">
                            <div class="w-7 h-7 rounded-full flex items-center justify-center text-white text-xs font-semibold shrink-0" style="background-color: var(--brand-primary, #005FD7)">
                                {{ strtoupper(substr($submission->user->display_name ?? $submission->user->email, 0, 1)) }}
                            </div>
                            <span class="text-slate-700 dark:text-slate-200">{{ $submission->user->display_name ?? $submission->user->email }}</span>
                        </a>
                    </td>
                    <td class="px-4 py-2 text-slate-600 dark:text-slate-300">{{ $submission->course->name ?? '—' }}</td>
                    <td class="px-4 py-2">
                        @php($nextStep = $submission->nextStep())
                        <span @class([
                            'inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full',
                            'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' => $nextStep['action_needed'],
                            'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' => ! $nextStep['action_needed'] && str_starts_with($nextStep['label'], 'Prüfungstermin fix'),
                            'bg-slate-100 text-slate-500 dark:bg-slate-700/50 dark:text-slate-400' => ! $nextStep['action_needed'] && ! str_starts_with($nextStep['label'], 'Prüfungstermin fix'),
                        ])>{{ $nextStep['label'] }}</span>
                    </td>
                    <td class="px-4 py-2 text-slate-500 text-xs whitespace-nowrap">{{ $submission->created_at->format('d.m.Y H:i') }}</td>
                    <td class="px-4 py-2 text-right whitespace-nowrap">
                        <a href="{{ route('admin.praxis-form-submissions.show', $submission) }}" class="text-xs hover:underline" style="color: var(--brand-primary, #005FD7)">Details &rarr;</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-3 text-slate-500">
                    @if (array_filter($anmeldungFilters))
                        Keine Anmeldungen gefunden, die zu diesem Filter passen.
                    @else
                        Noch keine Anmeldungen eingegangen.
                    @endif
                </td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="p-4">{{ $praxisFormSubmissions->links() }}</div>
</div>

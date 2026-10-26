<x-superadmin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Praxisformulare</h2>
            <a href="{{ route('superadmin.praxis-form-templates.create') }}" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: #005FD7">
                Neues Formular
            </a>
        </div>
    </x-slot>

    <p class="text-slate-500 mb-6 max-w-3xl">
        Formulare für die Anmeldung zu „Praxis &amp; Prüfung&quot;. Jeder zugewiesene Kurs zeigt seinen Lernern dieses
        Formular, sobald der konfigurierte Kursfortschritt erreicht ist &mdash; vorher erscheint nur eine Hürden-Meldung.
        Ein Kurs kann nur einem aktiven Formular gleichzeitig zugewiesen sein.
    </p>

    {{-- Bug-Review N10 (Folgefehler): destroy() lehnte ein Löschen bei
         vorhandenen Anmeldungen bereits mit withErrors(['template' => ...])
         ab, aber diese Seite zeigte den Fehler nirgends an -- der Klick auf
         "Löschen" schien dadurch wirkungslos zu verpuffen. --}}
    @error('template')
        <div class="mb-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 text-sm dark:bg-rose-900/30 dark:border-rose-800 dark:text-rose-300">
            {{ $message }}
        </div>
    @enderror

    {{-- Bug-Review R4-2: @submit="...confirm(...)..." beim Formular löschen
         griff ohne einen umschließenden x-data-Bereich nicht -- Alpine
         initialisiert Direktiven nur innerhalb eines x-data-Teilbaums,
         das Formular wurde also ohne Rückfrage sofort abgeschickt. --}}
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden" x-data="{}">
        <table class="w-full text-sm">
            <tbody>
                @forelse ($templates as $template)
                    <tr class="border-t border-slate-100 dark:border-slate-700 first:border-t-0 {{ $template->active ? '' : 'opacity-50' }}">
                        <td class="px-4 py-3 font-medium text-slate-700 dark:text-slate-200">
                            {{ $template->title }}
                            <div class="text-xs text-slate-400">
                                Ab {{ $template->min_progress_percent }}% Kursfortschritt &middot; {{ count($template->fields) }} Feld(er)
                            </div>
                            @if (! empty($template->course_ids))
                                <div class="text-xs text-slate-400 mt-0.5">
                                    {{ collect($template->course_ids)->map(fn ($id) => $courses->get($id)?->name)->filter()->implode(', ') }}
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if ($template->active)
                                <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700">aktiv</span>
                            @else
                                <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">deaktiviert</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('superadmin.praxis-form-templates.edit', $template) }}" class="text-xs text-slate-500 hover:underline">Bearbeiten</a>
                            <form method="POST" action="{{ route('superadmin.praxis-form-templates.toggle-active', $template) }}" class="inline">
                                @csrf
                                <button type="submit" class="ml-3 text-xs text-slate-500 hover:underline">{{ $template->active ? 'Deaktivieren' : 'Aktivieren' }}</button>
                            </form>
                            <form method="POST" action="{{ route('superadmin.praxis-form-templates.destroy', $template) }}" class="inline" @submit="if (! confirm('Dieses Formular wirklich löschen?')) $event.preventDefault()">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="ml-3 text-xs text-red-500 hover:underline">Löschen</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-3 text-slate-400">Noch keine Praxisformulare angelegt.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-superadmin-layout>

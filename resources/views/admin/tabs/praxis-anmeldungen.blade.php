<p class="text-slate-500 mb-6">
    Anmeldungen zu „Praxis &amp; Prüfung&quot;, die Lerner über ein ihnen zugewiesenes Praxisformular abgeschickt haben.
    Diese Daten dienen dir als Grundlage für die Anmeldung bei der Prüfungskommission.
</p>

@if ($praxisFormSubmissions->isEmpty())
    <div class="max-w-2xl bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 text-slate-500">
        Noch keine Anmeldungen eingegangen.
    </div>
@else
    <div class="space-y-4">
        @foreach ($praxisFormSubmissions as $submission)
            <div class="max-w-2xl bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
                <div class="flex items-start justify-between gap-4 mb-3">
                    <div>
                        <div class="font-medium text-slate-700 dark:text-slate-200">{{ $submission->user->display_name ?? $submission->user->email }}</div>
                        <div class="text-xs text-slate-400">{{ $submission->course->name }} &middot; {{ $submission->template->title ?? 'Formular gelöscht' }}</div>
                    </div>
                    <div class="text-xs text-slate-400 whitespace-nowrap">{{ $submission->created_at->format('d.m.Y H:i') }}</div>
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
                            @endif
                        @endforeach
                    </dl>
                @endif
            </div>
        @endforeach
    </div>
@endif

<p class="text-slate-500 mb-6">
    Anmeldungen zu „Praxis &amp; Prüfung&quot;, die Lerner über ein ihnen zugewiesenes Praxisformular abgeschickt haben.
    Diese Daten dienen dir als Grundlage für die Anmeldung bei der Prüfungskommission.
</p>

@if ($praxisFormSubmissions->isEmpty())
    <div class="max-w-2xl bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 text-slate-500">
        Noch keine Anmeldungen eingegangen.
    </div>
@else
    <div class="max-w-2xl space-y-3">
        @foreach ($praxisFormSubmissions as $submission)
            <div x-data="{ open: false }" class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm overflow-hidden">
                <button type="button" x-on:click="open = !open"
                        class="w-full flex items-start justify-between gap-4 px-5 py-4 text-left hover:bg-slate-50 dark:hover:bg-slate-700/50 transition">
                    <div>
                        <div class="font-medium text-slate-700 dark:text-slate-200">{{ $submission->user->display_name ?? $submission->user->email }}</div>
                        <div class="text-xs text-slate-400">{{ $submission->course->name }} &middot; {{ $submission->template->title ?? 'Formular gelöscht' }}</div>
                    </div>
                    <div class="flex items-center gap-3 shrink-0">
                        <div class="text-xs text-slate-400 whitespace-nowrap">{{ $submission->created_at->format('d.m.Y H:i') }}</div>
                        <x-icon name="chevron-right" class="w-4 h-4 text-slate-400 shrink-0 transition-transform" x-bind:class="open ? 'rotate-90' : ''" />
                    </div>
                </button>
                <div x-show="open" x-transition class="px-5 pb-5">
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
            </div>
        @endforeach
    </div>
@endif

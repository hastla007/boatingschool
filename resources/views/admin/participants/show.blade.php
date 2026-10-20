<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Teilnehmer</h2>
    </x-slot>

    <a href="{{ route('admin.dashboard', ['tab' => 'participants']) }}" class="text-sm text-slate-500 hover:underline inline-block mb-4">&larr; Zurück zu Teilnehmer</a>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 mb-6 flex items-center gap-4">
        <div class="w-12 h-12 rounded-full flex items-center justify-center text-white text-lg font-semibold shrink-0" style="background-color: var(--brand-primary, #005FD7)">
            {{ strtoupper(substr($participant->name, 0, 1)) }}
        </div>
        <div>
            <div class="text-lg font-semibold text-slate-800 dark:text-white">{{ $participant->name }}</div>
            <div class="text-sm text-slate-500">{{ $participant->email }}</div>
        </div>
        <span class="ms-auto inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-500">
            {{ $participant->status }}
        </span>
    </div>

    <div class="grid lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
                <x-icon name="map-pin" class="w-4 h-4 text-slate-400" /> Adresse &amp; Geburtsdatum
            </h3>
            @if ($participant->street || $participant->postal_code || $participant->city || $participant->country)
                <dl class="text-sm space-y-1.5">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Straße</dt>
                        <dd class="text-slate-800 dark:text-slate-200 text-end">{{ $participant->street ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">PLZ / Stadt</dt>
                        <dd class="text-slate-800 dark:text-slate-200 text-end">{{ trim(($participant->postal_code ?? '').' '.($participant->city ?? '')) ?: '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Land</dt>
                        <dd class="text-slate-800 dark:text-slate-200 text-end">{{ $participant->country ?? '—' }}</dd>
                    </div>
                </dl>
            @else
                <p class="text-sm text-slate-400">Noch keine Adresse hinterlegt.</p>
            @endif
            <div class="flex justify-between gap-4 text-sm mt-3 pt-3 border-t border-slate-100 dark:border-slate-700">
                <dt class="text-slate-500">Geburtsdatum</dt>
                <dd class="text-slate-800 dark:text-slate-200">{{ $participant->birthdate?->format('d.m.Y') ?? 'noch nicht angegeben' }}</dd>
            </div>
            @unless ($participant->hasCompleteRegistrationProfile())
                <p class="text-xs text-amber-600 mt-2">Profil noch unvollständig -- der Teilnehmer muss fehlende Angaben selbst im eigenen Profil ergänzen.</p>
            @endunless
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
                <x-icon name="clipboard-document-check" class="w-4 h-4 text-slate-400" /> Gebuchte Kurse &amp; Produkte
            </h3>
            <div class="space-y-2">
                @forelse ($bookings as $booking)
                    <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-700 pb-2 last:border-0 last:pb-0">
                        <div>
                            <span class="text-sm text-slate-700 dark:text-slate-200">{{ $booking['label'] }}</span>
                            @if ($booking['status'])
                                <span class="inline-block ms-1 px-1.5 py-0.5 rounded-full text-[10px] bg-slate-100 dark:bg-slate-700 text-slate-500">{{ $booking['status'] }}</span>
                            @endif
                            <span class="block text-xs text-slate-400">
                                {{ $booking['source_type'] === 'coupon' ? 'Code '.$booking['source_reference'] : 'Manuell freigeschaltet' }}
                            </span>
                            @if ($booking['options']->isNotEmpty())
                                <span class="block text-xs text-indigo-600 dark:text-indigo-400 mt-0.5">+ {{ $booking['options']->implode(', ') }}</span>
                            @endif
                        </div>
                        <span class="text-xs text-slate-400 whitespace-nowrap">{{ $booking['date']?->format('d.m.Y') }}</span>
                    </div>
                @empty
                    <p class="text-sm text-slate-400">Noch keine Buchungen.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
        <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
            <x-icon name="academic-cap" class="w-4 h-4 text-slate-400" /> Praxis-Anmeldungen
        </h3>
        @forelse ($praxisFormSubmissions as $submission)
            <div class="border-b border-slate-100 dark:border-slate-700 py-3 last:border-0">
                <div class="flex items-start justify-between gap-4 mb-2">
                    <div>
                        <span class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $submission->course->name ?? '—' }}</span>
                        <span class="block text-xs text-slate-400">{{ $submission->template->title ?? 'Formular gelöscht' }}</span>
                    </div>
                    <span class="text-xs text-slate-400 whitespace-nowrap">{{ $submission->created_at->format('d.m.Y H:i') }}</span>
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
        @empty
            <p class="text-sm text-slate-400">Noch keine Praxis-Anmeldung eingegangen.</p>
        @endforelse
    </div>
</x-app-layout>

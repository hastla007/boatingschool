<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-1 text-xs text-slate-400 mb-1">
            <a href="{{ route('courses.show', $course) }}" class="hover:underline">{{ $course->name }}</a>
            <x-icon name="chevron-right" class="w-3 h-3" />
            <span>Praxis &amp; Prüfung</span>
        </div>
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Praxis &amp; Prüfung</h2>
    </x-slot>

    @if ($state === 'unavailable')
        <div class="max-w-2xl mx-auto bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-10 text-center">
            <x-icon name="anchor" class="w-10 h-10 mx-auto text-slate-300 mb-3" />
            <p class="text-slate-500 dark:text-slate-400">Diese Seite wird in Kürze verfügbar sein.</p>
        </div>
    @elseif ($state === 'locked')
        <div class="max-w-2xl mx-auto bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-8 text-center">
            <x-icon name="lock-closed" class="w-10 h-10 mx-auto text-slate-300 mb-4" />
            <h3 class="font-semibold text-lg text-slate-800 dark:text-white mb-2">Du erfüllst noch nicht alle Voraussetzungen zur Prüfungsbuchung.</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Aktuell hast du <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $percent }}% Kursfortschritt</span>.
                Für die Buchung benötigst du mindestens <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $threshold }}% Kursfortschritt</span>.
            </p>
            <div class="mt-6 w-full bg-slate-100 dark:bg-slate-700 rounded-full h-2">
                <div class="h-2 rounded-full" style="width: {{ min($percent, 100) }}%; background-color: var(--brand-secondary, #00A8A8)"></div>
            </div>
            <a href="{{ route('courses.show', $course) }}" class="inline-flex items-center justify-center mt-6 px-5 py-2 rounded-lg text-white text-sm font-semibold hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                Weiterlernen
            </a>
        </div>
    @elseif ($state === 'incomplete-profile')
        <div class="max-w-2xl mx-auto bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-8 text-center">
            <x-icon name="map-pin" class="w-10 h-10 mx-auto text-slate-300 mb-4" />
            <h3 class="font-semibold text-lg text-slate-800 dark:text-white mb-2">Vervollständige zuerst dein Profil.</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Für die Anmeldung zu Praxis &amp; Prüfung benötigen wir einmalig deine Adresse und dein Geburtsdatum &mdash;
                beides trägt deine Bootsschule bei der Prüfungskommission ein.
            </p>
            <a href="{{ route('profile.edit', ['return_to' => '/'.request()->path()]) }}" class="inline-flex items-center justify-center mt-6 px-5 py-2 rounded-lg text-white text-sm font-semibold hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                Profil vervollständigen
            </a>
        </div>
    @elseif ($state === 'submitted')
        <div class="max-w-2xl mx-auto bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-8">
            <div class="text-center mb-6">
                <x-icon name="check-circle" class="w-10 h-10 mx-auto text-emerald-500 mb-3" />
                <h3 class="font-semibold text-lg text-slate-800 dark:text-white">Deine Anmeldung ist eingegangen.</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Deine Bootsschule hat deine Angaben erhalten und meldet dich bei der Prüfungskommission an.</p>
            </div>
            @if (! empty($template->fields))
                <dl class="divide-y divide-slate-100 dark:divide-slate-700 border-t border-b border-slate-100 dark:border-slate-700">
                    @foreach ($template->fields as $field)
                        <div class="py-2.5 grid grid-cols-2 gap-4 text-sm">
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
                                <div class="py-2.5 grid grid-cols-2 gap-4 text-sm">
                                    <dt class="text-slate-500 dark:text-slate-400">Anschrift Praxisort</dt>
                                    <dd class="text-slate-800 dark:text-slate-200 font-medium">{{ $submission->data[$field['key'].'_adresse'] }}</dd>
                                </div>
                            @endif
                            @if (! empty($submission->data[$field['key'].'_pruefungsort']))
                                <div class="py-2.5 grid grid-cols-2 gap-4 text-sm">
                                    <dt class="text-slate-500 dark:text-slate-400">Prüfungsort</dt>
                                    <dd class="text-slate-800 dark:text-slate-200 font-medium">{{ $submission->data[$field['key'].'_pruefungsort'] }}</dd>
                                </div>
                            @endif
                            @if (! empty($submission->data[$field['key'].'_pruefungsort_adresse']))
                                <div class="py-2.5 grid grid-cols-2 gap-4 text-sm">
                                    <dt class="text-slate-500 dark:text-slate-400">Anschrift Prüfungsort</dt>
                                    <dd class="text-slate-800 dark:text-slate-200 font-medium">{{ $submission->data[$field['key'].'_pruefungsort_adresse'] }}</dd>
                                </div>
                            @endif
                            @if (! empty($submission->data[$field['key'].'_pruefungsausschuss']))
                                <div class="py-2.5 grid grid-cols-2 gap-4 text-sm">
                                    <dt class="text-slate-500 dark:text-slate-400">Prüfungskommission</dt>
                                    <dd class="text-slate-800 dark:text-slate-200 font-medium">
                                        {{ $submission->data[$field['key'].'_pruefungsausschuss'] }}
                                        @if (! empty($submission->data[$field['key'].'_pruefungsausschuss_adresse']))
                                            <span class="block text-xs text-slate-400 font-normal">{{ $submission->data[$field['key'].'_pruefungsausschuss_adresse'] }}</span>
                                        @endif
                                        @if (! empty($submission->data[$field['key'].'_pruefungsausschuss_kurse']))
                                            <span class="block text-xs text-slate-400 font-normal">Zuständig für: {{ $submission->data[$field['key'].'_pruefungsausschuss_kurse'] }}</span>
                                        @endif
                                    </dd>
                                </div>
                            @endif
                            @if (! empty($submission->data[$field['key'].'_theorieort']))
                                <div class="py-2.5 grid grid-cols-2 gap-4 text-sm">
                                    <dt class="text-slate-500 dark:text-slate-400">Theorieort</dt>
                                    <dd class="text-slate-800 dark:text-slate-200 font-medium">{{ $submission->data[$field['key'].'_theorieort'] }}</dd>
                                </div>
                            @endif
                            @if (! empty($submission->data[$field['key'].'_theorieort_adresse']))
                                <div class="py-2.5 grid grid-cols-2 gap-4 text-sm">
                                    <dt class="text-slate-500 dark:text-slate-400">Anschrift Theorieort</dt>
                                    <dd class="text-slate-800 dark:text-slate-200 font-medium">{{ $submission->data[$field['key'].'_theorieort_adresse'] }}</dd>
                                </div>
                            @endif
                        @endif
                    @endforeach
                </dl>
            @endif

            <x-praxis-appointment-response :submission="$submission" :course="$course" :appointment-slot="0" label="Prüfungstermin" />

            @if ($submission->isExamDateConfirmed())
                @foreach ($submission->practiceSlotNumbers() as $practiceSlot)
                    <x-praxis-appointment-response
                        :submission="$submission"
                        :course="$course"
                        :appointment-slot="$practiceSlot"
                        :label="$submission->labelForSlot($practiceSlot)"
                    />
                @endforeach
            @endif
        </div>
    @elseif ($state === 'form')
        @php($locationField = collect($template->fields)->firstWhere('type', 'location'))
        @php($locationsWithCoords = $locationField ? $locations->filter->hasPruefungCoordinates()->values() : collect())
        @php($locationFieldId = $locationField ? 'field-'.$locationField['key'] : null)
        @php($pruefungsorteForMap = $locationsWithCoords->map(fn ($location) => [
            'id' => $location->id,
            'name' => $location->pruefung_ort,
            'address' => $location->pruefungAdresse(),
            'lat' => $location->pruefung_lat,
            'lng' => $location->pruefung_lng,
        ]))

        <div class="max-w-5xl mx-auto {{ $locationsWithCoords->isNotEmpty() ? 'grid lg:grid-cols-2 gap-6 items-start' : '' }}">
            @if ($locationsWithCoords->isNotEmpty())
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 lg:sticky lg:top-6">
                    <h4 class="text-sm font-medium text-slate-600 dark:text-slate-300 px-2 pb-3">Prüfungsorte in deiner Nähe</h4>
                    <div id="pruefungsort-map" style="height: 420px; border-radius: 0.75rem;"></div>
                    <p class="text-xs text-slate-400 px-2 pt-3">Klicke auf einen Marker, um den Ort im Formular auszuwählen.</p>
                </div>
            @endif

            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-8">
            <h3 class="font-semibold text-lg text-slate-800 dark:text-white mb-1">{{ $template->title }}</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">Bitte fülle die folgenden Angaben aus &mdash; deine Bootsschule meldet dich damit zur Prüfung an.</p>

            @if ($recommendedAddon)
                @php($addonTemplate = $recommendedAddon->addonTemplate)
                @php($addonDiscountedPrice = $recommendedAddon->discountedPriceLabel())
                <div class="rounded-xl p-4 mb-5 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800">
                    <div class="flex items-start gap-2.5">
                        <x-icon name="star" class="w-5 h-5 shrink-0 text-amber-500 mt-0.5" />
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">{{ $addonTemplate->headline }}</p>
                            @if (! empty($recommendedAddon->effectiveBullets()))
                                <ul class="mt-1.5 space-y-0.5">
                                    @foreach ($recommendedAddon->effectiveBullets() as $bullet)
                                        <li class="text-xs text-amber-800 dark:text-amber-300">&bull; {{ $bullet }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            <div class="mt-3 flex flex-wrap items-center gap-3">
                                @if ($recommendedAddon->price_label)
                                    <span class="text-xs text-amber-800 dark:text-amber-300">
                                        @if ($addonDiscountedPrice)
                                            <span class="line-through">{{ $recommendedAddon->price_label }}</span>
                                            <span class="font-semibold">{{ $addonDiscountedPrice }}</span>
                                        @else
                                            <span class="font-semibold">{{ $recommendedAddon->price_label }}</span>
                                        @endif
                                    </span>
                                @endif
                                @if ($recommendedAddon->offer_url)
                                    <a href="{{ $recommendedAddon->offer_url }}" target="_blank" rel="noopener"
                                       class="inline-flex items-center justify-center px-3 py-1.5 rounded-lg text-white text-xs font-semibold hover:opacity-90 transition"
                                       style="background-color: var(--brand-primary, #005FD7)">
                                        {{ $addonTemplate->cta_label }}
                                    </a>
                                @endif
                            </div>
                            <p class="text-xs text-amber-700 dark:text-amber-400 mt-2">
                                Du kannst dich auch ohne dieses Angebot anmelden &mdash; diese Empfehlung passt einfach gut zu deinem aktuellen Kurs.
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            <form method="POST" action="{{ route('praxis-pruefung.store', $course) }}" class="space-y-5">
                @csrf
                @php($hasTauglichkeitField = collect($template->fields)->contains(fn ($f) => str_contains($f['key'], 'tauglichkeit')))
                @foreach ($template->fields as $field)
                    @continue($field['type'] === 'location' && $locations->isEmpty())
                    @php($fieldId = 'field-'.$field['key'])
                    <div>
                        @if ($field['type'] !== 'checkbox')
                            <x-input-label :for="$fieldId" :value="$field['label'].($field['required'] ? ' *' : '')" />
                        @endif

                        @switch ($field['type'])
                            @case('textarea')
                                <textarea id="{{ $fieldId }}" name="{{ $field['key'] }}" rows="3" @if ($field['required']) required @endif
                                          class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ old($field['key']) }}</textarea>
                                @break
                            @case('date')
                                <x-text-input :id="$fieldId" :name="$field['key']" type="date" class="mt-1 block w-full" :value="old($field['key'])" :required="$field['required']" />
                                @break
                            @case('select')
                                <select id="{{ $fieldId }}" name="{{ $field['key'] }}" @if ($field['required']) required @endif
                                        class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                                    <option value="" disabled @selected(! old($field['key']))>Bitte wählen…</option>
                                    @foreach ($field['options'] as $option)
                                        <option value="{{ $option }}" @selected(old($field['key']) === $option)>{{ $option }}</option>
                                    @endforeach
                                </select>
                                @break
                            @case('radio')
                                <div class="mt-1 flex flex-wrap gap-4">
                                    @foreach ($field['options'] as $option)
                                        <label class="inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                                            <input type="radio" name="{{ $field['key'] }}" value="{{ $option }}" @checked(old($field['key']) === $option) @if ($field['required']) required @endif
                                                   class="border-slate-300 dark:bg-slate-700 dark:border-slate-600">
                                            {{ $option }}
                                        </label>
                                    @endforeach
                                </div>
                                @break
                            @case('location')
                                <select id="{{ $fieldId }}" name="{{ $field['key'] }}" @if ($field['required']) required @endif
                                        class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                                    <option value="" disabled @selected(! old($field['key']))>Bitte wählen…</option>
                                    @foreach ($locations as $location)
                                        <option value="{{ $location->id }}" @selected(old($field['key']) === $location->id)>
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
                                @break
                            @case('checkbox')
                                <label class="inline-flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                                    <input type="hidden" name="{{ $field['key'] }}" value="0">
                                    <input type="checkbox" id="{{ $fieldId }}" name="{{ $field['key'] }}" value="1" @checked(old($field['key'])) @if ($field['required']) required @endif
                                           class="mt-0.5 rounded border-slate-300 dark:bg-slate-700 dark:border-slate-600">
                                    <span>{{ $field['label'] }}{{ $field['required'] ? ' *' : '' }}</span>
                                </label>
                                @break
                            @default
                                <x-text-input :id="$fieldId" :name="$field['key']" type="text" class="mt-1 block w-full" :value="old($field['key'])" :required="$field['required']" />
                        @endswitch

                        @if (! empty($field['help_text']))
                            <p class="text-xs text-slate-400 mt-1">{{ $field['help_text'] }}</p>
                        @endif
                        <x-input-error :messages="$errors->get($field['key'])" class="mt-2" />
                    </div>

                    @if ($template->requires_tauglichkeit && str_contains($field['key'], 'tauglichkeit'))
                        @include('praxis-pruefung._tauglichkeit-hint')
                    @endif
                @endforeach

                {{-- Fallback, falls das Formular keine eigene Tauglichkeits-Checkbox hat --}}
                @if ($template->requires_tauglichkeit && ! $hasTauglichkeitField)
                    @include('praxis-pruefung._tauglichkeit-hint')
                @endif

                <button type="submit" class="w-full inline-flex items-center justify-center px-5 py-2.5 rounded-lg text-white text-sm font-semibold hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                    Jetzt anmelden
                </button>
            </form>
            </div>
        </div>

        @if ($locationsWithCoords->isNotEmpty())
            <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
            <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var mapEl = document.getElementById('pruefungsort-map');
                    if (! mapEl || typeof L === 'undefined') {
                        return;
                    }

                    var pruefungsorte = @json($pruefungsorteForMap);

                    var map = L.map(mapEl);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>-Mitwirkende',
                        maxZoom: 18,
                    }).addTo(map);

                    var select = @js($locationFieldId) ? document.getElementById(@js($locationFieldId)) : null;
                    var markers = [];

                    pruefungsorte.forEach(function (ort) {
                        var marker = L.marker([ort.lat, ort.lng]).addTo(map);
                        var popup = '<strong>' + ort.name + '</strong>' + (ort.address ? '<br>' + ort.address : '');
                        marker.bindPopup(popup);
                        marker.on('click', function () {
                            if (select) {
                                select.value = ort.id;
                                select.dispatchEvent(new Event('change'));
                            }
                        });
                        markers.push(marker);
                    });

                    if (markers.length > 1) {
                        map.fitBounds(L.featureGroup(markers).getBounds().pad(0.25));
                    } else {
                        map.setView([pruefungsorte[0].lat, pruefungsorte[0].lng], 13);
                    }
                });
            </script>
        @endif
    @endif
</x-app-layout>

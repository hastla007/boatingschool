<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Teilnehmer</h2>
    </x-slot>

    <a href="{{ route('admin.dashboard', ['tab' => 'participants']) }}" class="text-sm text-slate-500 dark:text-slate-400 hover:underline inline-block mb-4">&larr; Zurück zu Teilnehmer</a>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 mb-6 flex items-center gap-4">
        <div class="w-12 h-12 rounded-full flex items-center justify-center text-white text-lg font-semibold shrink-0" style="background-color: var(--brand-primary, #005FD7)">
            {{-- Bug-Review B28: substr/strtoupper schneiden bei einem mit
                 einem Umlaut beginnenden Namen nur das erste Byte der
                 UTF-8-Sequenz ab bzw. lassen Nicht-ASCII-Buchstaben
                 unverändert -- mb_substr/mb_strtoupper behandeln das
                 korrekt als ein Zeichen. --}}
            {{ mb_strtoupper(mb_substr($participant->name, 0, 1)) }}
        </div>
        <div>
            <div class="text-lg font-semibold text-slate-800 dark:text-white">{{ $participant->name }}</div>
            <div class="text-sm text-slate-500 dark:text-slate-400">{{ $participant->email }}</div>
        </div>
        <span class="ms-auto inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400">
            {{ \App\Support\StatusLabels::userStatus($participant->status) }}
        </span>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 mb-6">
        <div class="flex flex-wrap items-baseline justify-between gap-2 mb-3">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 flex items-center gap-1.5">
                <x-icon name="chart-bar" class="w-4 h-4 text-slate-600 dark:text-slate-400" /> Lernstand
            </h3>
            <span class="text-xs text-slate-600 dark:text-slate-400">
                Zuletzt gelernt: {{ $learning['last_activity'] ? $learning['last_activity']->timezone('Europe/Berlin')->format('d.m.Y H:i') . ' (' . $learning['last_activity']->locale('de')->diffForHumans() . ')' : 'noch nie' }}
            </span>
        </div>
        @forelse ($learning['courses'] as $row)
            <div class="py-2 border-b border-slate-100 dark:border-slate-700 last:border-0">
                <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                    <span class="text-slate-700 dark:text-slate-200">{{ $row['course']->name }}</span>
                    <span class="flex items-center gap-2">
                        <span class="font-medium text-slate-700 dark:text-slate-200">{{ $row['percent'] }} %</span>
                        @if ($row['ready'])
                            <span class="text-xs rounded-full px-2 py-0.5 bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">prüfungsreif</span>
                        @else
                            <span class="text-xs rounded-full px-2 py-0.5 bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-200">prüfungsreif ab {{ $row['threshold'] }} %</span>
                        @endif
                    </span>
                </div>
                <div class="h-1.5 rounded-full bg-slate-100 dark:bg-slate-700 mt-1.5" role="progressbar" aria-valuenow="{{ $row['percent'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Fortschritt {{ $row['course']->name }}">
                    <div class="h-1.5 rounded-full" style="width: {{ $row['percent'] }}%; background-color: var(--brand-primary, #005FD7)"></div>
                </div>
                @if ($row['valid_until'])
                    <div class="text-xs text-slate-600 dark:text-slate-400 mt-1">Zugang bis {{ $row['valid_until']->timezone('Europe/Berlin')->format('d.m.Y') }} · {{ \App\Support\StatusLabels::entitlementStatus($row['status']) }}</div>
                @endif
            </div>
        @empty
            <p class="text-sm text-slate-600 dark:text-slate-400">Noch kein Kurs freigeschaltet.</p>
        @endforelse

        <h4 class="font-medium text-sm text-slate-700 dark:text-slate-200 mt-4 mb-2">Prüfungssimulation</h4>
        @forelse ($learning['exams'] as $exam)
            <div class="flex flex-wrap items-center justify-between gap-2 text-sm py-1">
                <span class="text-slate-600 dark:text-slate-300">{{ $exam->submitted_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') }} · {{ $exam->course?->name }}</span>
                <span class="flex items-center gap-2">
                    <span class="text-slate-700 dark:text-slate-200">{{ (int) round($exam->score * 100) }} %</span>
                    <span class="text-xs rounded-full px-2 py-0.5 {{ $exam->passed ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' : 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200' }}">{{ $exam->passed ? 'bestanden' : 'nicht bestanden' }}</span>
                </span>
            </div>
        @empty
            <p class="text-sm text-slate-600 dark:text-slate-400">Noch keine Prüfungssimulation abgeschlossen.</p>
        @endforelse
    </div>

    {{-- Bug-Review (Willkommensbox -- Tab läuft auf dem Handy über): ohne
         grid-cols-1 bleibt die Spaltenbreite unterhalb von lg content-based
         (shrink-to-fit) statt zu stapeln -- die beiden Karten liefen bei
         360px nebeneinander über den Viewport hinaus (derselbe Fehler wie
         Bug-Review P8 bei Video/Praxistrainer). --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        @php($hasAddressData = $membership->street || $membership->postal_code || $membership->city || $membership->country || $membership->birthdate)
        @php($startInEditMode = ! $hasAddressData || $errors->hasAny(['street', 'postal_code', 'city', 'country', 'birthdate']))
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5" x-data="{ editing: {{ $startInEditMode ? 'true' : 'false' }} }">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
                <x-icon name="map-pin" class="w-4 h-4 text-slate-600 dark:text-slate-400" /> Adresse &amp; Geburtsdatum
            </h3>

            <div x-show="! editing" style="display: none">
                <dl class="text-sm space-y-1.5">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400">Straße</dt>
                        <dd class="text-slate-800 dark:text-slate-200 text-end">{{ $membership->street ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400">PLZ / Stadt</dt>
                        <dd class="text-slate-800 dark:text-slate-200 text-end">{{ trim(($membership->postal_code ?? '').' '.($membership->city ?? '')) ?: '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400">Land</dt>
                        <dd class="text-slate-800 dark:text-slate-200 text-end">{{ $membership->country ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400">Geburtsdatum</dt>
                        <dd class="text-slate-800 dark:text-slate-200 text-end">{{ $membership->birthdate?->format('d.m.Y') ?? 'noch nicht angegeben' }}</dd>
                    </div>
                </dl>
                <button type="button" @click="editing = true" class="mt-3 px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200 text-sm font-medium hover:bg-slate-200 dark:hover:bg-slate-600 transition">
                    Bearbeiten
                </button>
                @unless ($hasAddressData && $membership->street && $membership->postal_code && $membership->city && $membership->country && $membership->birthdate)
                    <p class="text-xs text-amber-600 mt-2">Profil noch unvollständig.</p>
                @endunless
            </div>

            <form x-show="editing" style="display: none" method="POST" action="{{ route('admin.participants.update', $participant) }}" class="space-y-2">
                @csrf
                @method('PATCH')
                <div>
                    <label for="participant_street" class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Straße &amp; Hausnummer</label>
                    <input type="text" id="participant_street" name="street" value="{{ old('street', $membership->street) }}" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label for="participant_postal_code" class="block text-xs text-slate-500 dark:text-slate-400 mb-1">PLZ</label>
                        <input type="text" id="participant_postal_code" name="postal_code" value="{{ old('postal_code', $membership->postal_code) }}" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                    </div>
                    <div>
                        <label for="participant_city" class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Wohnort</label>
                        <input type="text" id="participant_city" name="city" value="{{ old('city', $membership->city) }}" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                    </div>
                </div>
                <div>
                    <label for="participant_country" class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Land</label>
                    <select id="participant_country" name="country" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                        <option value="">Land wählen&hellip;</option>
                        @foreach (\App\Support\Countries::OPTIONS as $countryOption)
                            <option value="{{ $countryOption }}" @selected(old('country', $membership->country) === $countryOption)>{{ $countryOption }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="participant_birthdate" class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Geburtsdatum</label>
                    <input type="date" id="participant_birthdate" name="birthdate" value="{{ old('birthdate', $membership->birthdate?->format('Y-m-d')) }}" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                </div>
                <x-input-error :messages="$errors->get('street')" class="mt-1" />
                <x-input-error :messages="$errors->get('postal_code')" class="mt-1" />
                <x-input-error :messages="$errors->get('city')" class="mt-1" />
                <x-input-error :messages="$errors->get('country')" class="mt-1" />
                <x-input-error :messages="$errors->get('birthdate')" class="mt-1" />
                <div class="flex gap-2">
                    <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                        Speichern
                    </button>
                    @if ($hasAddressData)
                        <button type="button" @click="editing = false" class="px-4 py-2 rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200 text-sm font-medium hover:bg-slate-200 dark:hover:bg-slate-600 transition">
                            Abbrechen
                        </button>
                    @endif
                </div>
            </form>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
                <x-icon name="flag" class="w-4 h-4 text-slate-600 dark:text-slate-400" /> Benachrichtigungen
            </h3>
            @forelse ($actionItems as $item)
                <div class="flex items-start gap-2 text-sm text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 rounded-lg px-3 py-2 mb-2 last:mb-0">
                    <x-icon name="bolt" class="w-4 h-4 shrink-0 mt-0.5" />
                    <div>
                        <span class="font-medium">{{ $item['course'] }}</span> &ndash; {{ $item['label'] }}: {{ $item['reason'] }}
                    </div>
                </div>
            @empty
                <p class="text-sm text-slate-600 dark:text-slate-400">Keine offenen Aktionen.</p>
            @endforelse
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
            <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
                <x-icon name="clipboard-document-check" class="w-4 h-4 text-slate-600 dark:text-slate-400" /> Gebuchte Kurse &amp; Produkte
            </h3>
            <div class="space-y-2">
                @forelse ($bookings as $booking)
                    <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-700 pb-2 last:border-0 last:pb-0">
                        <div>
                            <span class="text-sm text-slate-700 dark:text-slate-200">{{ $booking['label'] }}</span>
                            {{-- Bug-Review (Teilnehmer-Detail zeigt englischen Status): $booking['status'] bleibt
                                 für die Formular-Logik unten der rohe Enumwert ("active"), angezeigt wird das
                                 deutsche Label (siehe StatusLabels). --}}
                            @if ($booking['status'])
                                <span class="inline-block ms-1 px-1.5 py-0.5 rounded-full text-[10px] bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400">{{ \App\Support\StatusLabels::entitlementStatus($booking['status']) }}</span>
                            @endif
                            <span class="block text-xs text-slate-600 dark:text-slate-400">
                                {{ in_array($booking['source_type'], ['coupon', 'coupon_renewal'], true) && $booking['source_reference'] ? 'Code '.$booking['source_reference'] : 'Manuell freigeschaltet' }}
                                @if ($booking['entitlement_id'] ?? null)
                                    &middot; {{ $booking['valid_until'] ? 'gültig bis '.$booking['valid_until']->timezone('Europe/Berlin')->format('d.m.Y') : 'unbefristet' }}
                                @endif
                            </span>
                            @if ($booking['options']->isNotEmpty())
                                <span class="block text-xs text-indigo-600 dark:text-indigo-400 mt-0.5">+ {{ $booking['options']->implode(', ') }}</span>
                            @endif
                        </div>
                        <div class="text-end">
                            {{-- Bug-Review (Admin-Datumsangaben in UTC): valid_from/created_at sind UTC-Zeitstempel -- kurz nach Mitternacht deutscher Zeit stand hier sonst noch der Vortag. --}}
                            <span class="text-xs text-slate-600 dark:text-slate-400 whitespace-nowrap">{{ \App\Support\GermanTime::format($booking['date'], 'd.m.Y') }}</span>
                            @if ($canManageTenant && $booking['entitlement_id'])
                                {{-- Bug-Review B14: Verlängern/Sperren gab es bisher nur im
                                     Controller (EntitlementController::update), ohne jede
                                     Oberfläche dafür. --}}
                                {{-- Bug-Review (confirm() ohne Alpine-Scope): der einzige x-data-Bereich
                                     dieser Seite (Adresse bearbeiten) endet weiter oben -- ohne eigenes
                                     x-data griffen die @submit-Rückfragen für Sperren/Widerrufen nie,
                                     der Zugang wurde beim Klick sofort gesperrt bzw. widerrufen. --}}
                                <div class="mt-1" x-data="{}">
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                        @if (in_array($booking['status'], ['active', 'suspended'], true))
                                            <form method="POST" action="{{ route('admin.entitlements.update', $booking['entitlement_id']) }}"
                                                  @submit="if ({{ $booking['status'] === 'active' ? 'true' : 'false' }} && ! confirm('Diesen Zugang wirklich sperren?')) $event.preventDefault()">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="action" value="{{ $booking['status'] === 'active' ? 'suspend' : 'reactivate' }}">
                                                <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 transition text-xs whitespace-nowrap">
                                                    {{ $booking['status'] === 'active' ? 'Sperren' : 'Entsperren' }}
                                                </button>
                                            </form>
                                            {{-- Bug-Review F1: es gab bisher keine Oberfläche für
                                                 "Widerrufen", obwohl EntitlementController::update()
                                                 die Aktion schon lange unterstützt -- die einzige
                                                 bewusst HÄRTERE Sperr-Stufe (z. B. bei Betrug/
                                                 Rückbuchung) war dadurch über die UI gar nicht
                                                 auslösbar. --}}
                                            <form method="POST" action="{{ route('admin.entitlements.update', $booking['entitlement_id']) }}"
                                                  @submit="if (! confirm('Diesen Zugang wirklich widerrufen? Anders als Sperren lässt er sich danach nicht mehr einfach per neuem Code wiederherstellen.')) $event.preventDefault()">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="action" value="revoke">
                                                <button type="submit" class="px-3 py-1.5 rounded-lg bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 hover:bg-red-100 dark:hover:bg-red-900/50 transition text-xs whitespace-nowrap">
                                                    Widerrufen
                                                </button>
                                            </form>
                                        @elseif ($booking['status'] === 'revoked')
                                            {{-- Bug-Review (Kostenlose Umgehungen -- Widerruf
                                                 aufheben): stellte bisher den vollen Zugang ohne
                                                 jeden Code wieder her -- zusammen mit revoke()
                                                 kostenlos, was die bewusst härtere
                                                 Widerrufen-Entscheidung im Ergebnis bedeutungslos
                                                 machte. --}}
                                            <form method="POST" action="{{ route('admin.entitlements.update', $booking['entitlement_id']) }}"
                                                  title="Verbraucht einen eigenen Code für diesen Kurs."
                                                  @submit="if (! confirm('Widerruf aufheben? Dafür wird ein freier Code dieses Kurses verbraucht.')) $event.preventDefault()">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="action" value="reactivate">
                                                <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 transition text-xs whitespace-nowrap">
                                                    Widerruf aufheben
                                                </button>
                                            </form>
                                        @endif
                                        {{-- Bug-Review (Kostenlose Umgehungen -- Verlängern): ein
                                             frei wählbares Datum (auch beliebig weit in der Zukunft,
                                             z. B. 2099) umging die eigens dafür verkauften
                                             Verlängerungscodes vollständig. "Verlängern" verbraucht
                                             jetzt wie jede andere Freischaltung einen Code und
                                             erweitert die Laufzeit um die übliche Gültigkeitsdauer --
                                             kein frei wählbares Datum mehr.

                                             Bug-Review T5: bei einem widerrufenen Zugang blendet
                                             EntitlementController::extend() die Aktion jetzt serverseitig
                                             ab (verbrauchte sonst einen Verlängerungscode wirkungslos,
                                             der Zugang blieb trotzdem gesperrt) -- der Button hier
                                             verschwindet für status=revoked zusätzlich auch in der
                                             Oberfläche, "Widerruf aufheben" oben ist dort die einzig
                                             sinnvolle Aktion. --}}
                                        @if ($booking['status'] !== 'revoked' && $booking['valid_until'] === null)
                                            {{-- Unbefristet: nichts zu verlängern. --}}
                                        @elseif ($booking['status'] !== 'revoked' && ($booking['free_renewal_codes'] ?? 1) < 1)
                                            <span class="text-xs text-slate-600 dark:text-slate-400" title="Für diesen Kurs ist kein freier Verlängerungscode vorhanden.">Verlängern (keine Verlängerungscodes)</span>
                                        @elseif ($booking['status'] !== 'revoked')
                                            {{-- Doppelklick-Schutz: Knopf nach dem Absenden gesperrt, und der Server
                                                 verlängert nur, wenn das Laufzeitende noch dem angezeigten entspricht. --}}
                                            <form method="POST" action="{{ route('admin.entitlements.update', $booking['entitlement_id']) }}"
                                                  title="Verbraucht einen eigenen Verlängerungscode für diesen Kurs."
                                                  x-data="{ busy: false }" @submit="if (busy || ! confirm('Zugang verlängern? Dafür wird ein freier Verlängerungscode verbraucht.')) { $event.preventDefault() } else { busy = true }">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="action" value="extend">
                                                <input type="hidden" name="seen_valid_until" value="{{ $booking['valid_until']?->timestamp ?? '' }}">
                                                <button type="submit" :disabled="busy" class="disabled:opacity-50 px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 transition text-xs whitespace-nowrap">
                                                    Verlängern
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-600 dark:text-slate-400">Noch keine Buchungen.</p>
                @endforelse
            </div>
        </div>

        @if ($willkommensboxOrder)
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
                <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
                    <x-icon name="cube" class="w-4 h-4 text-slate-600 dark:text-slate-400" /> Willkommensbox
                </h3>
                <dl class="text-sm space-y-1.5 mb-3">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400">Straße</dt>
                        <dd class="text-slate-800 dark:text-slate-200 text-end">{{ $willkommensboxOrder->street }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400">PLZ / Stadt</dt>
                        <dd class="text-slate-800 dark:text-slate-200 text-end">{{ $willkommensboxOrder->postal_code }} {{ $willkommensboxOrder->city }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400">Land</dt>
                        <dd class="text-slate-800 dark:text-slate-200 text-end">{{ $willkommensboxOrder->country }}</dd>
                    </div>
                    @if ($willkommensboxOrder->note)
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-500 dark:text-slate-400">Notiz</dt>
                            <dd class="text-slate-800 dark:text-slate-200 text-end">{{ $willkommensboxOrder->note }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400">Bestellt am</dt>
                        <dd class="text-slate-800 dark:text-slate-200 text-end">{{ \App\Support\GermanTime::format($willkommensboxOrder->created_at, 'd.m.Y') }}</dd>
                    </div>
                </dl>

                @if (! empty($willkommensboxLabelSizes))
                    <div class="flex flex-wrap items-center gap-2 mb-3">
                        <span class="text-xs text-slate-500 dark:text-slate-400">Versandlabel:</span>
                        @foreach ($willkommensboxLabelSizes as $size)
                            <a href="{{ route('admin.willkommensbox-orders.label', [$willkommensboxOrder, $size]) }}"
                               class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg border border-slate-300 dark:border-slate-600 text-xs font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition">
                                <x-icon name="download" class="w-3.5 h-3.5" />
                                {{ strtoupper($size) }}
                            </a>
                        @endforeach
                    </div>
                @endif

                @if ($willkommensboxOrder->isShipped())
                    <div class="flex items-center gap-2 text-sm text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/30 rounded-lg px-3 py-2">
                        <x-icon name="check-circle" class="w-4 h-4 shrink-0" />
                        Versendet am {{ \App\Support\GermanTime::format($willkommensboxOrder->shipped_at, 'd.m.Y') }}
                        @if ($willkommensboxOrder->tracking_number)
                            &middot; Tracking: {{ $willkommensboxOrder->tracking_number }}
                        @endif
                    </div>
                @else
                    <div class="flex items-center gap-2 text-sm text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 rounded-lg px-3 py-2 mb-3">
                        <x-icon name="bolt" class="w-4 h-4 shrink-0" />
                        Bestellung eingegangen &ndash; bitte Willkommensbox versenden.
                    </div>
                    <form method="POST" action="{{ route('admin.willkommensbox-orders.ship', $willkommensboxOrder) }}" class="flex flex-wrap items-end gap-2">
                        @csrf
                        @method('PATCH')
                        <div>
                            <label for="wb_tracking_number" class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Trackingnummer (optional)</label>
                            <input type="text" id="wb_tracking_number" name="tracking_number" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                        </div>
                        <button type="submit" class="px-3 py-1.5 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">
                            Als versendet markieren
                        </button>
                    </form>
                @endif
            </div>
        @elseif ($hasWillkommensboxAccess)
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
                <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
                    <x-icon name="cube" class="w-4 h-4 text-slate-600 dark:text-slate-400" /> Willkommensbox
                </h3>
                <p class="text-sm text-slate-600 dark:text-slate-400">Zugang vorhanden, aber noch nicht bestellt.</p>
            </div>
        @endif
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
        <h3 class="font-medium text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-1.5">
            <x-icon name="academic-cap" class="w-4 h-4 text-slate-600 dark:text-slate-400" /> Praxis-Anmeldungen
        </h3>
        @forelse ($praxisFormSubmissions as $submission)
            @include('admin.partials._praxis-submission', [
                'submission' => $submission,
                'submissionLocations' => $locationsByCourse[$submission->course_id] ?? collect(),
            ])
        @empty
            @if ($selfBookingCourses->isEmpty())
                <p class="text-sm text-slate-600 dark:text-slate-400">Noch keine Praxis-Anmeldung eingegangen.</p>
            @endif
        @endforelse
        {{-- Selbst-Anmeldung zur Prüfung: Praxisstunden schlägt die Schule trotzdem vor. --}}
        @foreach ($selfBookingCourses as $selfCourse)
            <div class="border-b border-slate-100 dark:border-slate-700 py-3 last:border-0">
                <span class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $selfCourse->name }}</span>
                <span class="block text-xs text-slate-600 dark:text-slate-400 mb-2">Zur Prüfung meldet sich der Teilnehmer selbst an. Praxisstunden können Sie hier vorschlagen.</span>
                @include('admin.partials._praxis-new-appointment-form', [
                    'action' => route('admin.participants.practice-appointments.store', $participant),
                    'key' => 'self-'.$selfCourse->id,
                    'locations' => $locationsByCourse[$selfCourse->id] ?? collect(),
                    'courseId' => $selfCourse->id,
                ])
            </div>
        @endforeach
        @error('exam_date')
            @if ($selfBookingCourses->isNotEmpty())
                <p class="text-rose-600 text-xs mt-2">{{ $message }}</p>
            @endif
        @enderror
    </div>

    @if ($canManageTenant && ($membership->role ?? null) === 'learner')
        {{-- Ins Team holen (Review-Runde 10: die Team-Einladung führte für Teilnehmer ins Leere). --}}
        <form method="POST" action="{{ route('admin.team.role.update', $participant) }}" class="mt-4 bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5 flex flex-wrap items-end gap-2" x-data="{}"
              @submit="if (! confirm('Diese Person ins Team holen? Sie sieht danach den Admin-Bereich.')) $event.preventDefault()">
            @csrf
            @method('PATCH')
            <div>
                <label for="promote-role" class="block text-sm font-medium text-slate-700 dark:text-slate-200 mb-1">Ins Team holen</label>
                <select id="promote-role" name="role" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                    <option value="instructor">als Ausbilder</option>
                    @if (auth()->user()->roleForTenant($currentTenant) === 'owner')
                        <option value="admin">als Admin</option>
                    @endif
                </select>
            </div>
            <button type="submit" class="px-3 py-2 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 text-sm">Rolle ändern</button>
        </form>
        <details class="mt-4 bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5" @if ($errors->has('confirm')) open @endif>
            <summary class="cursor-pointer text-sm font-medium text-rose-700 dark:text-rose-300">Teilnehmer und alle Daten löschen</summary>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">
                Entfernt die Person aus Ihrer Bootsschule und löscht Lernstand, Prüfungsversuche, Praxis-Anmeldungen, Termine und Bestellungen bei Ihnen.
                Das lässt sich nicht rückgängig machen. Hat die Person keine weitere Bootsschule, wird auch ihr Konto gelöscht.
            </p>
            <form method="POST" action="{{ route('admin.participants.destroy-data', $participant ?? $user) }}" class="mt-3 flex flex-wrap items-end gap-2">
                @csrf
                @method('DELETE')
                <div>
                    <label for="confirm-delete" class="block text-xs text-slate-600 dark:text-slate-400 mb-1">Zur Bestätigung LÖSCHEN eintippen</label>
                    <input id="confirm-delete" name="confirm" type="text" autocomplete="off" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                </div>
                <button type="submit" class="px-3 py-2 rounded-lg bg-rose-600 text-white text-sm font-medium hover:bg-rose-700">Endgültig löschen</button>
            </form>
            <x-input-error :messages="$errors->get('confirm')" class="mt-1" />
        </details>
    @endif
</x-app-layout>

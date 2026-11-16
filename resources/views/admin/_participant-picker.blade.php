{{--
    Teilnehmer-Suchfeld (Combobox nach WAI-ARIA APG): statt einer Liste
    aller Teilnehmer im HTML sucht das Feld mit JavaScript über einen
    JSON-Endpunkt ($url, höchstens $limit Treffer, Antwort
    {data: [{id, name, email}]}) und setzt die gewählte ID ins versteckte
    Feld $idName; Pfeiltasten wählen in der Trefferliste
    (aria-activedescendant), Enter übernimmt, Escape schließt. Die Logik
    steht in resources/js/participant-picker.js (Alpine.data('participantPicker')).
    Ohne JavaScript ist es ein normales Textfeld $name: die eingetippte
    E-Mail-Adresse löst der Server auf. Die Combobox-Rollen setzt deshalb
    erst Alpine.

    Genutzt von: Verwalter einer Lerngruppe (learning-groups/_manager-picker)
    und „Zuweisen“ im Reiter Zugangscodes (tabs/coupons, eines je Code).

    Parameter:
      $id           ID des Textfelds (Basis für -hint, -error, -listbox, -option-N)
      $name         Name des Textfelds (E-Mail-Adresse)
      $idName       Name des versteckten Felds (gewählte Nutzer-ID)
      $label        Beschriftung; $labelSrOnly: nur für Screenreader
      $url, $limit  Suchendpunkt und dessen Trefferhöchstzahl
      $selected     bisher gewählte Person (User) oder null
      $useOld       alte Eingaben (old()) übernehmen, Standard: ja
      $messages     Fehlermeldungen dieses Felds
      $hint         eigener Hinweistext (null: keiner), $describedBy: weitere IDs
      $emptyMessage Ansage bei null Treffern
      $compact      kleine Variante für Tabellenzeilen: Trefferliste im
                    Textfluss statt darüber (kein Abschneiden in einer
                    seitlich scrollbaren Tabelle)
--}}
@php
    $selected = $selected ?? null;
    $useOld = $useOld ?? true;
    $messages = $messages ?? [];
    $hint = $hint ?? null;
    $compact = $compact ?? false;
    $currentId = (string) ($useOld ? old($idName, $selected?->id ?? '') : ($selected?->id ?? ''));
    $currentEmail = (string) ($useOld ? old($name, $selected?->email ?? '') : ($selected?->email ?? ''));
    $currentLabel = $selected && $currentId === $selected->id ? $selected->name.' ('.$selected->email.')' : '';
    $describedByIds = implode(' ', array_filter([
        $hint !== null ? $id.'-hint' : null,
        $describedBy ?? null,
        $messages ? $id.'-error' : null,
    ]));
    $pickerConfig = [
        'url' => $url,
        'limit' => $limit,
        'id' => $currentId,
        'email' => $currentEmail,
        'label' => $currentLabel,
        'optionPrefix' => $id.'-option-',
        'emptyMessage' => $emptyMessage ?? 'Keine passenden Teilnehmer gefunden.',
    ];
@endphp
<div @class(['relative' => ! $compact]) x-data="participantPicker(@js($pickerConfig))" @click.outside="close()">
    <x-input-label :for="$id" :value="$label" :class="($labelSrOnly ?? false) ? 'sr-only' : ''" />
    <input type="hidden" name="{{ $idName }}" value="{{ $currentId }}" x-bind:value="id">
    <input id="{{ $id }}" name="{{ $name }}" type="text" inputmode="email" autocomplete="off" spellcheck="false" required
           value="{{ $currentEmail }}" placeholder="{{ $placeholder ?? 'E-Mail-Adresse oder Name' }}"
           @if ($describedByIds !== '') aria-describedby="{{ $describedByIds }}" @endif
           @if ($messages) aria-invalid="true" @endif
           x-bind:role="'combobox'" x-bind:aria-autocomplete="'list'" x-bind:aria-controls="@js($id.'-listbox')"
           x-bind:aria-expanded="open ? 'true' : 'false'"
           x-bind:aria-activedescendant="active >= 0 ? @js($id.'-option-') + active : null"
           x-model="query" @input.debounce.250ms="search()"
           @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
           @keydown.enter="if (open && active >= 0) { $event.preventDefault(); choose(results[active]); }"
           @keydown.escape="if (open) { $event.preventDefault(); close(); }" @keydown.tab="close()"
           @class([
               'block w-full border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 focus:border-blue-500 dark:focus:border-blue-500 focus:ring-blue-500 rounded-lg shadow-sm',
               'mt-1 text-sm' => ! $compact,
               'text-xs min-w-[14rem]' => $compact,
           ])>
    <ul id="{{ $id }}-listbox" role="listbox" aria-label="Gefundene Teilnehmer" x-show="open" style="display: none"
        @class([
            'mt-1 w-full max-h-64 overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200',
            'absolute z-20 shadow-lg text-sm' => ! $compact,
            'text-xs' => $compact,
        ])>
        <template x-for="(user, index) in results" :key="user.id">
            <li x-bind:id="@js($id.'-option-') + index" role="option" x-bind:aria-selected="index === active ? 'true' : 'false'"
                @mousedown.prevent="choose(user)" @mousemove="active = index"
                x-bind:class="index === active ? 'bg-blue-50 dark:bg-slate-700' : ''"
                class="px-3 py-2 cursor-pointer">
                <span x-text="user.name"></span>
                {{-- Kontrast ≥ 4.5:1 auch auf dem markierten Treffer (bg-blue-50 / dark:bg-slate-700). --}}
                <span class="block text-xs text-slate-600 dark:text-slate-300" x-text="user.email"></span>
            </li>
        </template>
    </ul>
    @if ($hint !== null)
        <p id="{{ $id }}-hint" class="text-xs text-slate-600 dark:text-slate-400 mt-1">
            {{ $hint }}
            @if ($currentLabel !== '')
                <span class="block">Aktuell: {{ $currentLabel }}</span>
            @endif
        </p>
    @endif
    {{-- WCAG 4.1.3: Trefferzahl und Auswahl werden angesagt. --}}
    <p class="text-xs text-slate-600 dark:text-slate-400 mt-1" role="status" aria-live="polite" x-text="message" data-participant-search-status></p>
    <x-input-error :for="$id" :messages="$messages" class="mt-1" />
</div>

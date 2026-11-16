{{--
    Schulauswahl im Superadmin-Bereich als Suchfeld (Combobox nach WAI-ARIA
    APG) statt einer Auswahlliste aller Bootsschulen. Mit JavaScript
    (resources/js/tenant-picker.js) sucht das Feld über
    superadmin.tenants.search (höchstens TenantPicker::SEARCH_LIMIT Treffer)
    und setzt die gewählte ID im versteckten Feld $name; Pfeiltasten wählen
    (aria-activedescendant), Enter übernimmt, Escape schließt, Pfeil nach
    unten im leeren Feld zeigt die ersten Schulen nach Name. Ohne JavaScript
    ist es ein normales Textfeld (Begleitfeld, z. B. tenant_slug): Kürzel
    (Slug) oder genauen Namen löst der Server auf
    (App\Support\TenantPicker::resolve()). Die Combobox-Rollen setzt deshalb
    erst Alpine.

    $selected: bisher gewählte Schule (Tenant) oder null.
    $useOld / $showErrors: aus, wenn mehrere gleichnamige Felder auf einer
    Seite stehen (Shopkonten), damit nicht alle die alte Eingabe bzw. den
    Fehler eines Formulars zeigen.
    $submitOnChoose: Formular nach der Auswahl absenden (Filter).
--}}
@props([
    'name' => 'tenant_id',
    'selected' => null,
    'id' => null,
    'label' => 'Bootsschule',
    'labelClass' => null,
    'required' => false,
    'placeholder' => 'Name oder Kürzel (Slug)',
    'hint' => null,
    'submitOnChoose' => false,
    'useOld' => true,
    'showErrors' => true,
    'inputClass' => 'mt-1 text-sm',
])
@php
    $companion = \App\Support\TenantPicker::companion($name);
    $inputId = $id ?? $name;
    $useOldInput = $useOld && old($companion) !== null;
    $pickerId = $useOldInput ? (string) old($name, '') : (string) ($selected?->id ?? '');
    $pickerText = $useOldInput ? (string) old($companion) : (string) ($selected?->name ?? '');
    $pickerErrors = $showErrors ? array_merge($errors->get($name), $errors->get($companion)) : [];
    $describedBy = $inputId.'-hint'.($pickerErrors ? ' '.$inputId.'-error' : '');
@endphp
<div {{ $attributes->merge(['class' => 'relative']) }}
     x-data="tenantPicker(@js(['url' => route('superadmin.tenants.search'), 'inputId' => $inputId, 'limit' => \App\Support\TenantPicker::SEARCH_LIMIT, 'submitOnChoose' => (bool) $submitOnChoose, 'id' => $pickerId, 'query' => $pickerText]))"
     @click.outside="close()" data-tenant-picker>
    @if ($labelClass === null)
        <x-input-label :for="$inputId" :value="$label" />
    @else
        <label for="{{ $inputId }}" class="{{ $labelClass }}">{{ $label }}</label>
    @endif
    <input type="hidden" name="{{ $name }}" value="{{ $pickerId }}" x-bind:value="id">
    <input id="{{ $inputId }}" name="{{ $companion }}" type="text" autocomplete="off" spellcheck="false" @required($required)
           value="{{ $pickerText }}" placeholder="{{ $placeholder }}"
           aria-describedby="{{ $describedBy }}" @if ($pickerErrors) aria-invalid="true" @endif
           x-bind:role="'combobox'" x-bind:aria-autocomplete="'list'" x-bind:aria-controls="@js($inputId.'-listbox')"
           x-bind:aria-expanded="open ? 'true' : 'false'"
           x-bind:aria-activedescendant="active >= 0 ? optionId(active) : null"
           x-model="query" @input.debounce.250ms="search()"
           @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
           @keydown.enter="if (open && active >= 0) { $event.preventDefault(); choose(results[active]); }"
           @keydown.escape="if (open) { $event.preventDefault(); close(); }" @keydown.tab="close()"
           class="{{ $inputClass }} block w-full border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 focus:border-blue-500 dark:focus:border-blue-500 focus:ring-blue-500 rounded-lg shadow-sm">
    <ul id="{{ $inputId }}-listbox" role="listbox" aria-label="Gefundene Bootsschulen" x-show="open" style="display: none"
        class="absolute z-20 mt-1 w-full min-w-64 max-h-64 overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 shadow-lg text-sm">
        <template x-for="(tenant, index) in results" :key="tenant.id">
            <li x-bind:id="optionId(index)" role="option" x-bind:aria-selected="index === active ? 'true' : 'false'"
                @mousedown.prevent="choose(tenant)" @mousemove="active = index"
                x-bind:class="index === active ? 'bg-blue-50 dark:bg-slate-700' : ''"
                class="px-3 py-2 cursor-pointer text-slate-700 dark:text-slate-200">
                <span x-text="tenant.name"></span>
                {{-- Kontrast ≥ 4.5:1 auch auf dem markierten Treffer (dark:bg-slate-700). --}}
                <span class="block text-xs text-slate-600 dark:text-slate-300">
                    <span class="font-mono" x-text="tenant.slug"></span>
                    <span x-show="tenant.status !== 'active'" x-text="' · ' + tenant.status_label"></span>
                </span>
            </li>
        </template>
    </ul>
    <p id="{{ $inputId }}-hint" class="text-xs text-slate-600 dark:text-slate-400 mt-1">
        Name oder Kürzel eingeben und einen Treffer wählen.
        {{ $hint }}
        @if ($selected && ! $useOldInput)
            <span class="block">Aktuell: {{ $selected->name }} ({{ $selected->slug }})</span>
        @endif
    </p>
    {{-- WCAG 4.1.3: Trefferzahl und Auswahl werden angesagt. --}}
    <p class="text-xs text-slate-600 dark:text-slate-400 mt-1" role="status" aria-live="polite" x-text="message" data-tenant-search-status></p>
    @if ($showErrors)
        <x-input-error :for="$inputId" :messages="$pickerErrors" class="mt-1" />
    @endif
</div>

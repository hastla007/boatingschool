{{--
    Verwalterauswahl als Suchfeld (Combobox nach WAI-ARIA APG): statt einer
    Liste aller aktiven Teilnehmer sucht das Feld mit JavaScript über
    admin.learning-groups.manager-candidates (höchstens 20 Treffer) und setzt
    die gewählte owner_user_id; Pfeiltasten wählen in der Trefferliste
    (aria-activedescendant), Enter übernimmt, Escape schließt. Ohne
    JavaScript ist es ein normales Textfeld: die eingetippte E-Mail-Adresse
    löst der Server auf (LearningGroupController::resolveOwnerId()). Die
    Combobox-Rollen setzt deshalb erst Alpine.

    $selected: bisheriger Verwalter (Detailseite) oder null.
--}}
@php
    $ownerErrors = array_merge($errors->get('owner_user_id'), $errors->get('owner_email'));
    $pickerState = [
        'url' => route('admin.learning-groups.manager-candidates'),
        'id' => (string) old('owner_user_id', $selected?->id ?? ''),
        'email' => (string) old('owner_email', $selected?->email ?? ''),
        'label' => '',
    ];
    if ($selected && $pickerState['id'] === $selected->id) {
        $pickerState['label'] = $selected->name.' ('.$selected->email.')';
    }
@endphp
<div class="relative" x-data="{
        url: @js($pickerState['url']),
        id: @js($pickerState['id']),
        query: @js($pickerState['email']),
        chosenEmail: @js($pickerState['email']),
        chosenLabel: @js($pickerState['label']),
        results: [],
        open: false,
        active: -1,
        message: '',
        controller: null,
        async search() {
            if (this.query.trim().toLowerCase() !== this.chosenEmail.toLowerCase()) {
                this.id = '';
                this.chosenLabel = '';
            }
            const q = this.query.trim();
            if (q.length < 2) { this.close(); this.message = ''; return; }
            this.controller?.abort();
            this.controller = new AbortController();
            try {
                const response = await fetch(this.url + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, signal: this.controller.signal });
                if (! response.ok) throw new Error(response.status);
                this.results = (await response.json()).data;
            } catch (error) {
                if (error.name === 'AbortError') return;
                this.results = [];
                this.message = 'Die Suche ist gerade nicht möglich. Sie können die E-Mail-Adresse auch vollständig eingeben.';
                this.close();
                return;
            }
            this.active = -1;
            this.open = this.results.length > 0;
            this.message = this.results.length === 0
                ? 'Keine aktiven Teilnehmer gefunden.'
                : this.results.length + ' Treffer' + (this.results.length >= {{ \App\Http\Controllers\Admin\LearningGroupController::MANAGER_SEARCH_LIMIT }} ? ' (weitere Treffer: bitte genauer suchen)' : '') + ', mit Pfeiltasten auswählen.';
        },
        move(step) {
            if (! this.open) { if (this.results.length) { this.open = true; } else { return; } }
            this.active = (this.active + step + this.results.length) % this.results.length;
            this.$nextTick(() => document.getElementById('owner_email-option-' + this.active)?.scrollIntoView({ block: 'nearest' }));
        },
        choose(user) {
            this.id = user.id;
            this.query = user.email;
            this.chosenEmail = user.email;
            this.chosenLabel = user.name + ' (' + user.email + ')';
            this.message = 'Ausgewählt: ' + this.chosenLabel;
            this.close();
        },
        close() { this.open = false; this.active = -1; },
    }" @click.outside="close()">
    <x-input-label for="owner_email" value="Verwalter (aktiver Teilnehmer)" />
    <input type="hidden" name="owner_user_id" value="{{ $pickerState['id'] }}" x-bind:value="id">
    <input id="owner_email" name="owner_email" type="text" inputmode="email" autocomplete="off" spellcheck="false" required
           value="{{ $pickerState['email'] }}" placeholder="E-Mail-Adresse oder Name"
           aria-describedby="owner_email-hint{{ $ownerErrors ? ' owner_email-error' : '' }}"
           @if ($ownerErrors) aria-invalid="true" @endif
           x-bind:role="'combobox'" x-bind:aria-autocomplete="'list'" x-bind:aria-controls="'owner_email-listbox'"
           x-bind:aria-expanded="open ? 'true' : 'false'"
           x-bind:aria-activedescendant="active >= 0 ? 'owner_email-option-' + active : null"
           x-model="query" @input.debounce.250ms="search()"
           @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
           @keydown.enter="if (open && active >= 0) { $event.preventDefault(); choose(results[active]); }"
           @keydown.escape="if (open) { $event.preventDefault(); close(); }" @keydown.tab="close()"
           class="mt-1 block w-full border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 focus:border-blue-500 dark:focus:border-blue-500 focus:ring-blue-500 rounded-lg shadow-sm text-sm">
    <ul id="owner_email-listbox" role="listbox" aria-label="Gefundene Teilnehmer" x-show="open" style="display: none"
        class="absolute z-20 mt-1 w-full max-h-64 overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 shadow-lg text-sm">
        <template x-for="(user, index) in results" :key="user.id">
            <li x-bind:id="'owner_email-option-' + index" role="option" x-bind:aria-selected="index === active ? 'true' : 'false'"
                @mousedown.prevent="choose(user)" @mousemove="active = index"
                x-bind:class="index === active ? 'bg-blue-50 dark:bg-slate-700' : ''"
                class="px-3 py-2 cursor-pointer text-slate-700 dark:text-slate-200">
                <span x-text="user.name"></span>
                <span class="block text-xs text-slate-500 dark:text-slate-400" x-text="user.email"></span>
            </li>
        </template>
    </ul>
    <p id="owner_email-hint" class="text-xs text-slate-600 dark:text-slate-400 mt-1">
        E-Mail-Adresse des Teilnehmers eingeben oder nach dem Namen suchen und einen Treffer wählen.
        @if ($pickerState['label'] !== '')
            <span class="block">Aktuell: {{ $pickerState['label'] }}</span>
        @endif
    </p>
    {{-- WCAG 4.1.3: Trefferzahl und Auswahl werden angesagt. --}}
    <p class="text-xs text-slate-600 dark:text-slate-400 mt-1" role="status" aria-live="polite" x-text="message" data-manager-search-status></p>
    <x-input-error for="owner_email" :messages="$ownerErrors" class="mt-1" />
</div>

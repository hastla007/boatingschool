import Alpine from 'alpinejs';
import tenantPicker from './tenant-picker';

window.Alpine = Alpine;

Alpine.data('tenantPicker', tenantPicker);
/*
 * Teilnehmer-Suchfeld (Combobox nach WAI-ARIA APG), Markup in
 * resources/views/admin/_participant-picker.blade.php: sucht über einen
 * JSON-Endpunkt (höchstens `limit` Treffer, ab zwei Zeichen) und setzt die
 * gewählte ID ins versteckte Feld. Als Alpine.data, damit eine Seite mit
 * vielen Suchfeldern (Reiter Zugangscodes: eines je Code) die Logik nicht
 * in jedes Feld kopiert.
 */
Alpine.data('participantPicker', (config) => ({
    url: config.url,
    id: config.id,
    query: config.email,
    chosenEmail: config.email,
    chosenLabel: config.label,
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
        if (q.length < 2) {
            this.close();
            this.message = '';
            return;
        }
        this.controller?.abort();
        this.controller = new AbortController();
        try {
            const response = await fetch(this.url + '?q=' + encodeURIComponent(q), {
                headers: { Accept: 'application/json' },
                signal: this.controller.signal,
            });
            if (! response.ok) {
                throw new Error(response.status);
            }
            this.results = (await response.json()).data;
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            this.results = [];
            this.message = 'Die Suche ist gerade nicht möglich. Sie können die E-Mail-Adresse auch vollständig eingeben.';
            this.close();
            return;
        }
        this.active = -1;
        this.open = this.results.length > 0;
        this.message = this.results.length === 0
            ? config.emptyMessage
            : this.results.length + ' Treffer' + (this.results.length >= config.limit ? ' (weitere Treffer: bitte genauer suchen)' : '') + ', mit Pfeiltasten auswählen.';
    },
    move(step) {
        if (! this.open) {
            if (! this.results.length) {
                return;
            }
            this.open = true;
        }
        this.active = (this.active + step + this.results.length) % this.results.length;
        this.$nextTick(() => document.getElementById(config.optionPrefix + this.active)?.scrollIntoView({ block: 'nearest' }));
    },
    choose(user) {
        this.id = user.id;
        this.query = user.email;
        this.chosenEmail = user.email;
        this.chosenLabel = user.name + ' (' + user.email + ')';
        this.message = 'Ausgewählt: ' + this.chosenLabel;
        this.close();
    },
    close() {
        this.open = false;
        this.active = -1;
    },
}));

Alpine.start();

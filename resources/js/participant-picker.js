/**
 * Teilnehmer-Suchfeld (Combobox nach WAI-ARIA APG), Markup in
 * resources/views/admin/_participant-picker.blade.php: sucht über einen
 * JSON-Endpunkt (höchstens `limit` Treffer, ab zwei Zeichen) und setzt die
 * gewählte ID ins versteckte Feld. Pfeiltasten wählen
 * (aria-activedescendant), Enter übernimmt, Escape schließt; Trefferzahl,
 * „keine Treffer“ und die Auswahl stehen in der Live-Region (`message`).
 * Als Alpine.data, damit eine Seite mit vielen Suchfeldern (Reiter
 * Zugangscodes: eines je Code) die Logik nicht in jedes Feld kopiert.
 */
export default (config) => ({
    url: config.url,
    id: config.id ?? '',
    query: config.email ?? '',
    chosenEmail: config.email ?? '',
    chosenLabel: config.label ?? '',
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
            // Laufende Suche verwerfen, sonst öffnet ihre späte Antwort die Liste wieder.
            this.controller?.abort();
            this.close();
            // WCAG 4.1.3: auch ein einzelnes Zeichen bekommt eine Rückmeldung.
            this.message = q.length === 1 ? 'Bitte mindestens zwei Zeichen eingeben.' : '';
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
                throw new Error(String(response.status));
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
});

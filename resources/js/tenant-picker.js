/**
 * Schulauswahl im Superadmin-Bereich (components/superadmin/tenant-picker):
 * Combobox nach WAI-ARIA APG mit Trefferliste aus
 * superadmin.tenants.search. Pfeiltasten wählen (aria-activedescendant),
 * Enter übernimmt, Escape schließt; Pfeil nach unten im leeren Feld zeigt
 * die ersten Schulen nach Name. Gewählt wird die ID im versteckten Feld; der
 * sichtbare Text (Name bzw. Slug) bleibt ohne JavaScript die Eingabe, die
 * der Server auflöst (App\Support\TenantPicker).
 */
export default (config) => ({
    url: config.url,
    inputId: config.inputId,
    limit: config.limit,
    submitOnChoose: Boolean(config.submitOnChoose),
    id: config.id ?? '',
    query: config.query ?? '',
    chosenText: config.query ?? '',
    results: [],
    open: false,
    active: -1,
    message: '',
    controller: null,

    async search(showAll = false) {
        if (this.query.trim().toLowerCase() !== this.chosenText.trim().toLowerCase()) {
            this.id = '';
            this.chosenText = '';
        }
        const q = this.query.trim();
        if (q.length === 1) {
            this.close();
            this.message = 'Bitte mindestens zwei Zeichen eingeben.';
            return;
        }
        if (q === '' && ! showAll) {
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
            if (! response.ok) throw new Error(String(response.status));
            this.results = (await response.json()).data;
        } catch (error) {
            if (error.name === 'AbortError') return;
            this.results = [];
            this.close();
            this.message = 'Die Suche ist gerade nicht möglich. Sie können das Kürzel (Slug) der Schule auch direkt eingeben.';
            return;
        }
        this.active = -1;
        this.open = this.results.length > 0;
        if (this.results.length === 0) {
            this.message = 'Keine Bootsschule gefunden.';
        } else {
            const more = this.results.length >= this.limit
                ? (q === '' ? ' (die ersten nach Name, für weitere bitte suchen)' : ' (weitere Treffer: bitte genauer suchen)')
                : '';
            this.message = this.results.length + ' Treffer' + more + ', mit Pfeiltasten auswählen.';
        }
    },

    move(step) {
        if (! this.open) {
            if (this.results.length === 0) {
                this.search(true);
                return;
            }
            this.open = true;
        }
        this.active = (this.active + step + this.results.length) % this.results.length;
        this.$nextTick(() => document.getElementById(this.optionId(this.active))?.scrollIntoView({ block: 'nearest' }));
    },

    optionId(index) {
        return this.inputId + '-option-' + index;
    },

    choose(tenant) {
        this.id = tenant.id;
        this.query = tenant.name;
        this.chosenText = tenant.name;
        this.message = 'Ausgewählt: ' + tenant.name + ' (' + tenant.slug + ')';
        this.close();
        if (this.submitOnChoose) {
            this.$nextTick(() => {
                const form = this.$root.closest('form');
                if (form) form.requestSubmit ? form.requestSubmit() : form.submit();
            });
        }
    },

    close() {
        this.open = false;
        this.active = -1;
    },
});

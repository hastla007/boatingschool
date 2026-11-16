// Schulauswahl im Superadmin-Bereich (tenant-picker.js): Zustandslogik der
// Combobox ohne Browser, fetch wird ersetzt.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import tenantPicker from './tenant-picker.js';

const schools = [
    { id: 'a', name: 'Alpha Segelschule', slug: 'alpha', status: 'active', status_label: 'Aktiv' },
    { id: 'b', name: 'Beta Bootsschule', slug: 'beta', status: 'suspended', status_label: 'Gesperrt' },
];

globalThis.document = { getElementById: () => null };

function picker(config = {}) {
    const requests = [];
    globalThis.fetch = async (url) => {
        requests.push(url);
        return { ok: true, json: async () => ({ data: schools }) };
    };
    const state = tenantPicker({ url: '/superadmin/tenants/search', inputId: 'tenant_id', limit: 20, ...config });
    state.$nextTick = (callback) => callback();
    state.$root = { closest: () => null };

    return { state, requests };
}

test('ein Zeichen sucht noch nicht, zwei Zeichen zeigen Treffer an', async () => {
    const { state, requests } = picker();
    state.query = 'a';
    await state.search();
    assert.equal(requests.length, 0);
    assert.match(state.message, /mindestens zwei Zeichen/);

    state.query = 'al';
    await state.search();
    assert.deepEqual(requests, ['/superadmin/tenants/search?q=al']);
    assert.equal(state.open, true);
    assert.match(state.message, /^2 Treffer/);
});

test('Pfeil nach unten im leeren Feld lädt die ersten Schulen', async () => {
    const { state, requests } = picker();
    state.move(1);
    await new Promise((resolve) => setImmediate(resolve));
    assert.deepEqual(requests, ['/superadmin/tenants/search?q=']);
    assert.equal(state.open, true);
    state.move(1);
    state.move(1);
    assert.equal(state.active, 1);
    state.move(1);
    assert.equal(state.active, 0);
});

test('Auswahl setzt ID und Namen, Ändern des Texts hebt sie auf', async () => {
    const { state } = picker({ id: 'old', query: 'Alte Schule' });
    state.choose(schools[1]);
    assert.equal(state.id, 'b');
    assert.equal(state.query, 'Beta Bootsschule');
    assert.equal(state.open, false);
    assert.equal(state.message, 'Ausgewählt: Beta Bootsschule (beta)');

    state.query = 'Beta Boots';
    await state.search();
    assert.equal(state.id, '');
});

test('vorbelegte Auswahl bleibt, solange der Text passt', async () => {
    const { state } = picker({ id: 'a', query: 'Alpha Segelschule' });
    state.query = ' alpha segelschule ';
    await state.search();
    assert.equal(state.id, 'a');
});

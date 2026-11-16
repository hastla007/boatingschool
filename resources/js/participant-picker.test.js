// Teilnehmer-Suchfeld (participant-picker.js): Tastaturverhalten und
// Ansagen der Combobox ohne Browser, fetch wird ersetzt.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import participantPicker from './participant-picker.js';

const people = [
    { id: 'u1', name: 'Max Mustermann', email: 'max@example.org' },
    { id: 'u2', name: 'Mara Meyer', email: 'mara@example.org' },
    { id: 'u3', name: 'Malte Kurz', email: 'malte@example.org' },
];

globalThis.document = { getElementById: () => null };

function picker(config = {}, data = people) {
    const requests = [];
    globalThis.fetch = async (url) => {
        requests.push(url);
        return { ok: true, json: async () => ({ data }) };
    };
    const state = participantPicker({
        url: '/admin/participants/search', limit: 20, id: '', email: '', label: '',
        optionPrefix: 'p-option-', emptyMessage: 'Keine passenden Teilnehmer gefunden.', ...config,
    });
    state.$nextTick = (callback) => callback();

    return { state, requests };
}

test('Tippen öffnet die Liste und sagt die Trefferzahl an', async () => {
    const { state, requests } = picker();
    state.query = 'ma';
    await state.search();
    assert.deepEqual(requests, ['/admin/participants/search?q=ma']);
    assert.equal(state.open, true);
    assert.equal(state.active, -1);
    assert.equal(state.message, '3 Treffer, mit Pfeiltasten auswählen.');
});

test('Pfeil runter/hoch wandert durch die Treffer und läuft am Ende um', async () => {
    const { state } = picker();
    state.query = 'ma';
    await state.search();
    state.move(1);
    assert.equal(state.active, 0);
    state.move(1);
    assert.equal(state.active, 1);
    state.move(-1);
    assert.equal(state.active, 0);
    state.move(-1);
    assert.equal(state.active, 2);
    state.move(1);
    assert.equal(state.active, 0);
});

test('Enter (choose) übernimmt den markierten Treffer und schließt', async () => {
    const { state } = picker();
    state.query = 'ma';
    await state.search();
    state.move(1);
    state.move(1);
    state.choose(state.results[state.active]);
    assert.equal(state.id, 'u2');
    assert.equal(state.query, 'mara@example.org');
    assert.equal(state.open, false);
    assert.equal(state.active, -1);
    assert.equal(state.message, 'Ausgewählt: Mara Meyer (mara@example.org)');
});

test('Escape (close) schließt, Pfeil runter öffnet die letzten Treffer wieder', async () => {
    const { state, requests } = picker();
    state.query = 'ma';
    await state.search();
    state.move(1);
    state.close();
    assert.equal(state.open, false);
    assert.equal(state.active, -1);
    state.move(1);
    assert.equal(state.open, true);
    assert.equal(state.active, 0);
    assert.equal(requests.length, 1);
});

test('keine Treffer werden angesagt, die Liste bleibt zu', async () => {
    const { state } = picker({}, []);
    state.query = 'zz';
    await state.search();
    assert.equal(state.open, false);
    assert.equal(state.message, 'Keine passenden Teilnehmer gefunden.');
    state.move(1);
    assert.equal(state.open, false);
});

test('ein Zeichen sucht nicht, sagt aber den Grund an', async () => {
    const { state, requests } = picker();
    state.query = 'm';
    await state.search();
    assert.equal(requests.length, 0);
    assert.equal(state.message, 'Bitte mindestens zwei Zeichen eingeben.');
    state.query = '';
    await state.search();
    assert.equal(state.message, '');
});

test('eine späte Antwort öffnet die Liste nach dem Löschen nicht wieder', async () => {
    let release;
    globalThis.fetch = (url, { signal }) => new Promise((resolve, reject) => {
        signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));
        release = () => resolve({ ok: true, json: async () => ({ data: people }) });
    });
    const state = participantPicker({ url: '/s', limit: 20, id: '', email: '', label: '', optionPrefix: 'p-', emptyMessage: 'leer' });
    state.$nextTick = (callback) => callback();
    state.query = 'ma';
    const pending = state.search();
    state.query = 'm';
    await state.search();
    release();
    await pending;
    assert.equal(state.open, false);
    assert.equal(state.message, 'Bitte mindestens zwei Zeichen eingeben.');
});

test('Ändern des Texts hebt eine vorbelegte Auswahl auf', async () => {
    const { state } = picker({ id: 'u1', email: 'max@example.org', label: 'Max Mustermann (max@example.org)' });
    state.query = ' MAX@example.org ';
    await state.search();
    assert.equal(state.id, 'u1');
    state.query = 'max@example.or';
    await state.search();
    assert.equal(state.id, '');
    assert.equal(state.chosenLabel, '');
});

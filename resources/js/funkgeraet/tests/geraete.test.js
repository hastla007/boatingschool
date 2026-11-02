// Stufe 4: Lektionen auf dem IC-M323 – Ausblendung, Anpassungen und
// automatische Schritt-Hilfen; ausgewählte Lektionen durchgespielt.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { DEVICE } from '../devices/icm323.js';
import { createIcM323 } from '../devices/icm323.js';
import { createLessonRun } from '../lessons.js';
import { examLessons, lessonAvailable, prepareLesson } from '../devices/index.js';

const data = JSON.parse(execFileSync('php', ['-r', 'echo json_encode(require "database/data/geraetesimulation.php");'], { encoding: 'utf8' }));
const lesson = (key) => data.lessons.find((l) => l.key === key);

test('every lesson available on the IC-M323 gets help and a target that exists on that radio', () => {
    const valid = new Set([...DEVICE.keys, ...DEVICE.knobs, 'ptt']);
    for (const original of data.lessons.filter((l) => lessonAvailable(l, 'ic-m323'))) {
        for (const [i, step] of prepareLesson(original, 'ic-m323').steps.entries()) {
            const ok = step.target === null || step.target.startsWith('sk:') || valid.has(step.target);
            assert.ok(ok, `${original.key} Schritt ${i}: Ziel ${step.target}`);
            assert.ok(step.help, `${original.key} Schritt ${i}: keine Hilfe`);
            if (! step.speech) assert.doesNotMatch(step.help, /\bDIAL\b|H\/L gedrückt|Kanalwahlknopf/, `${original.key} Schritt ${i}: Hilfe des IC-M505`);
        }
    }
});

test('position request and polling are hidden on the IC-M323, the test call only exists there; series b substitutes it', () => {
    assert.equal(lessonAvailable(lesson('position-polling'), 'ic-m323'), false);
    assert.equal(lessonAvailable(lesson('testanruf'), 'ic-m505'), false);
    const b = { lessons: data.exams.b, substitutes: data.exam_meta.b.substitutes };
    assert.ok(examLessons(b, 'ic-m323').includes('testanruf'));
    assert.ok(! examLessons(b, 'ic-m323').includes('position-polling'));
    assert.deepEqual(examLessons(b, 'ic-m505'), data.exams.b);
});

function play(key) {
    let now = 0;
    const events = [];
    const radio = createIcM323({ own: data.ship, clock: () => now, emit: (e) => events.push(e), addressBook: data.address_book, directory: data.directory });
    const timers = [];
    const voices = [];
    const prepared = prepareLesson(lesson(key), 'ic-m323');
    const run = createLessonRun(prepared, { schedule: (fn) => timers.push(fn), receive: (c) => radio.receive(c), say: (v) => { const r = radio.hear(v); voices.push({ ...v, ...r }); return r; } });
    radio.setup(prepared.start);
    events.length = 0;
    const speech = [];
    const pump = () => { while (events.length) { const e = events.shift(); const p = run.handle(e); if (p && ! p.miss) speech.push(p); } };
    const wait = (ms) => { for (let t = 0; t < ms; t += 100) { now += 100; radio.tick(); } pump(); };
    const press = (k, ms = 100) => { radio.keyDown(k); wait(ms); radio.keyUp(k); pump(); };
    const soft = (label) => press(`soft${radio.display().softkeys.indexOf(label) + 1}`);
    const pick = (item) => { const p = radio.display().panel; radio.turn('dial', p.items.indexOf(item) - p.cursor); press('enter'); };
    const fire = () => { timers.splice(0).forEach((fn) => fn()); pump(); };
    const speak = (t) => { radio.pttDown(); radio.pttUp(); radio.spoken(t); pump(); };
    run.start();

    return { radio, run, press, soft, pick, fire, speak, speech, voices, wait, pump, prepared };
}

test('IC-M323 lesson: Kanal 16, Anrufkanal und Kanal 70', () => {
    const p = play('kanaele-anrufkanal');
    p.press('ch16', 1200);
    p.soft('CHAN');
    while (p.radio.state.channel !== 70) p.radio.turn('dial', 0), p.press('up');
    p.radio.pttDown();
    p.pump();
    p.press('ch16');
    assert.equal(p.run.status().done, true);
});

test('IC-M323 lesson: Fehlalarm aufheben with the DSC cancel', () => {
    const p = play('fehlalarm-aufheben');
    assert.equal(p.run.status().target, 'sk:CANCEL');
    p.soft('CANCEL');
    p.soft('CONTINUE');
    p.soft('FINISH');
    assert.equal(p.run.status().index, 1);
    p.speak('All stations all stations all stations this is Kormoran cancel my distress alert over');
    assert.equal(p.speech.length, 1);
});

test('IC-M323 lesson: MEDICO announces urgency with an all ships call', () => {
    const p = play('medico');
    p.press('menu');
    p.pick('DSC Calls');
    p.pick('All Ships Call');
    p.pick('Urgency');
    p.press('enter');
    p.soft('CALL');
    p.wait(1100);
    assert.equal(p.run.status().index, 2);
    p.speak('Pan pan Bremen Rescue Radio this is Kormoran medical advice over');
    assert.equal(p.speech[0].stepIndex, 2);
});

test('IC-M323 lesson: Küstenfunkstelle – the coast station sets channel 26', () => {
    const p = play('kuestenfunkstelle');
    p.press('menu');
    p.pick('DSC Calls');
    p.pick('Individual Call');
    p.pick('NORDSEE RADIO');
    p.soft('CALL');
    p.wait(1100);
    p.fire();
    p.soft('ALARM OFF');
    assert.equal(p.radio.state.channel, 26);
    assert.equal(p.run.status().index, 2);
});

test('IC-M323 lesson: Notalarm empfangen – ACPT before the distress message on 16 can be heard', () => {
    const p = play('notalarm-empfangen');
    p.fire();
    p.soft('ALARM OFF');
    assert.equal(p.run.status().index, 1);
    p.fire();
    assert.equal(p.voices.at(-1).heard, false);
    p.soft('ACPT');
    p.soft('CH 16');
    p.fire();
    assert.equal(p.voices.at(-1).heard, true);
});

test('IC-M323 lesson: Testanruf', () => {
    const p = play('testanruf');
    p.press('menu');
    p.pick('DSC Calls');
    p.pick('Test Call');
    p.pick('MOEWE');
    p.soft('CALL');
    p.wait(1100);
    p.fire();
    p.soft('ALARM OFF');
    assert.equal(p.run.status().done, true);
});

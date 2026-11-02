// Oberfläche der Gerätesimulation: verbindet Gerätelogik, Zeichnung,
// Lektionen und Server. Konfiguration (Lektionen, Übungsschiff, URLs,
// Fortschritt) kommt aus einem JSON-Block der Seite; Gesprochenes bewertet
// der Server mit denselben Regeln wie die Praxis-Prüfungssimulation.

import { createIcM505 } from './devices/icm505.js';
import { createLessonRun } from './lessons.js';
import { renderDevice } from './ui/device-svg.js';

const LEVEL_LABELS = { 1: 'Mit Hilfe', 2: 'Nur Hinweis', 3: 'Allein' };

function h(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs)) {
        if (key === 'class') node.className = value;
        else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
        else if (value !== null && value !== undefined && value !== false) node.setAttribute(key, value === true ? '' : value);
    }
    for (const child of children.flat()) {
        if (child === null || child === undefined || child === false) continue;
        node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
    }

    return node;
}

async function postJson(url, body) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
        body: JSON.stringify(body),
    });
    if (! response.ok) throw new Error(`HTTP ${response.status}`);

    return response.json();
}

export function mount(root, config) {
    const progress = { ...(config.progress ?? {}) };
    let run = null;
    let lastSpeech = null;
    let audio = null;

    const makeRadio = () => createIcM505({ own: config.ship, addressBook: config.addressBook, emit: (event) => onRadioEvent(event) });
    // Jede Lektion startet mit einem frisch eingeschalteten Gerät; die
    // Bedienelemente greifen immer auf das aktuelle Gerät zu.
    let radio = makeRadio();

    // --- Aufbau ---------------------------------------------------------------
    const deviceBox = h('div', { class: 'fg-device bg-slate-100 dark:bg-slate-900 rounded-2xl p-3 sm:p-5' });
    const sidebar = h('aside', { class: 'fg-sidebar bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 space-y-4 text-sm' });
    const typeBox = h('form', { class: 'mt-3 flex gap-2', onsubmit: (e) => { e.preventDefault(); sendTyped(); } },
        h('input', { type: 'text', name: 'spruch', class: 'flex-1 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm', placeholder: 'Funkspruch eintippen (statt sprechen)', 'aria-label': 'Funkspruch eintippen' }),
        h('button', { type: 'submit', class: 'px-3 py-2 rounded-lg bg-slate-800 text-white text-sm' }, 'Senden'),
    );
    const transcript = h('p', { class: 'mt-2 text-xs text-slate-600 dark:text-slate-400 min-h-[1rem]', 'aria-live': 'polite' });
    root.replaceChildren(
        h('div', { class: 'grid lg:grid-cols-[minmax(0,1fr)_22rem] gap-4' },
            h('div', {}, deviceBox, typeBox, transcript),
            sidebar,
        ),
    );
    const device = renderDevice(deviceBox);

    // --- Bedienung ------------------------------------------------------------
    const ensureAudio = () => {
        if (! audio && window.AudioContext) audio = new AudioContext();
    };

    function beep(frequency = 1800, ms = 120) {
        if (! audio || radio.state.vol === 0) return;
        const osc = audio.createOscillator();
        const gain = audio.createGain();
        gain.gain.value = 0.04 * radio.state.vol;
        osc.frequency.value = frequency;
        osc.connect(gain).connect(audio.destination);
        osc.start();
        osc.stop(audio.currentTime + ms / 1000);
    }

    device.svg.querySelectorAll('[data-key]').forEach((node) => {
        const key = node.dataset.key;
        const down = (e) => {
            e.preventDefault();
            ensureAudio();
            node.setPointerCapture?.(e.pointerId);
            if (key === 'ptt') pttDown();
            else radio.keyDown(key);
            render();
        };
        const up = () => {
            if (key === 'ptt') pttUp();
            else radio.keyUp(key);
            render();
        };
        node.addEventListener('pointerdown', down);
        node.addEventListener('pointerup', up);
        node.addEventListener('pointercancel', up);
        node.addEventListener('keydown', (e) => {
            if ((e.key === 'Enter' || e.key === ' ') && ! e.repeat) { e.preventDefault(); key === 'ptt' ? pttDown() : radio.keyDown(key); render(); }
        });
        node.addEventListener('keyup', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); key === 'ptt' ? pttUp() : radio.keyUp(key); render(); }
        });
    });

    const turnKnob = (knob, steps) => {
        radio.turn(knob, steps);
        if (knob === 'channel') device.rotateChannel(steps);
        render();
    };
    // ENTER sitzt in der Mitte des Kanalknopfs: das Mausrad dreht dort den Knopf.
    device.svg.querySelector('[data-key="enter"]')?.addEventListener('wheel', (e) => { e.preventDefault(); turnKnob('channel', e.deltaY < 0 ? 1 : -1); }, { passive: false });

    device.svg.querySelectorAll('[data-knob]').forEach((node) => {
        const knob = node.dataset.knob;
        const turn = (steps) => turnKnob(knob, steps);
        let startY = null;
        node.addEventListener('wheel', (e) => { e.preventDefault(); turn(e.deltaY < 0 ? 1 : -1); }, { passive: false });
        node.addEventListener('pointerdown', (e) => { ensureAudio(); startY = e.clientY; node.setPointerCapture?.(e.pointerId); });
        node.addEventListener('pointermove', (e) => {
            if (startY === null) return;
            const steps = Math.trunc((startY - e.clientY) / 18);
            if (steps !== 0) { turn(steps); startY -= steps * 18; }
        });
        const end = () => { startY = null; };
        node.addEventListener('pointerup', end);
        node.addEventListener('pointercancel', end);
        node.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowUp' || e.key === 'ArrowRight') { e.preventDefault(); turn(1); }
            if (e.key === 'ArrowDown' || e.key === 'ArrowLeft') { e.preventDefault(); turn(-1); }
        });
    });

    // Leertaste als Sprechtaste, solange kein Eingabefeld den Fokus hat.
    document.addEventListener('keydown', (e) => {
        if (e.code !== 'Space' || e.repeat || e.target.closest('input, textarea, [data-key], [data-knob]')) return;
        e.preventDefault();
        pttDown();
    });
    document.addEventListener('keyup', (e) => {
        if (e.code !== 'Space' || e.target.closest('input, textarea, [data-key], [data-knob]')) return;
        e.preventDefault();
        pttUp();
    });

    // --- Sprechtaste und Spracherkennung ------------------------------------
    const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    let recognition = null;
    let heard = '';

    function pttDown() {
        if (! radio.pttDown()) { render(); return; }
        heard = '';
        transcript.textContent = 'Sendet … sprich jetzt.';
        if (Recognition) {
            recognition = new Recognition();
            recognition.lang = config.speechLang ?? 'en-GB';
            recognition.continuous = true;
            recognition.interimResults = true;
            recognition.onresult = (event) => {
                heard = Array.from(event.results).map((r) => r[0].transcript).join(' ');
                transcript.textContent = heard;
            };
            recognition.onerror = () => { transcript.textContent = 'Spracherkennung nicht verfügbar – tippe den Funkspruch unten ein.'; };
            try { recognition.start(); } catch { recognition = null; }
        }
        render();
    }

    function pttUp() {
        if (! radio.state.tx) return;
        radio.pttUp();
        const finish = () => {
            if (heard.trim()) radio.spoken(heard.trim());
            else if (! Recognition) transcript.textContent = 'Dieser Browser hat keine Spracherkennung – tippe den Funkspruch unten ein.';
            render();
        };
        if (recognition) {
            recognition.onend = finish;
            recognition.stop();
            recognition = null;
        } else {
            finish();
        }
    }

    function sendTyped() {
        const input = typeBox.querySelector('input');
        const text = input.value.trim();
        if (! text) return;
        if (radio.pttDown()) {
            radio.pttUp();
            transcript.textContent = text;
            radio.spoken(text);
            input.value = '';
        }
        render();
    }

    // --- Lektionen ------------------------------------------------------------
    function onRadioEvent(event) {
        if (event.type === 'dsc_received' || event.type === 'dsc_sent') beep(2100, 200);
        if (! run) return;
        const pending = run.handle(event);
        if (pending) evaluateSpeech(pending);
    }

    async function evaluateSpeech({ stepIndex, text }) {
        lastSpeech = { stepIndex, checking: true, checks: [] };
        renderSidebar();
        try {
            const result = await postJson(config.urls.evaluate, { lesson: run.lesson.key, step: stepIndex, text });
            lastSpeech = { stepIndex, checking: false, ...result };
            run.speechResult(stepIndex, result.passed);
        } catch {
            lastSpeech = { stepIndex, checking: false, error: true, checks: [] };
            run.speechResult(stepIndex, false);
        }
        renderSidebar();
    }

    function startLesson(lesson, level) {
        if (radio.state.tx) pttUp();
        radio = makeRadio();
        lastSpeech = null;
        run = createLessonRun(lesson, { level, receive: (call) => { radio.receive(call); render(); } });
        radio.setup(lesson.start);
        run.onChange((status) => { if (status.done) finishLesson(lesson, level); renderSidebar(); render(); });
        run.start();
        render();
    }

    async function finishLesson(lesson, level) {
        progress[lesson.key] = Math.max(progress[lesson.key] ?? 0, level);
        try { await postJson(config.urls.progress, { lesson: lesson.key, level }); } catch { /* Fortschritt bleibt lokal sichtbar */ }
        renderSidebar();
    }

    function lessonList() {
        const units = [];
        for (const lesson of config.lessons) {
            let unit = units.find((u) => u.name === lesson.unit);
            if (! unit) units.push(unit = { name: lesson.unit, lessons: [] });
            unit.lessons.push(lesson);
        }
        const solo = config.lessons.filter((l) => (progress[l.key] ?? 0) >= 3).length;

        return h('div', { class: 'space-y-3' },
            h('p', { class: 'text-xs text-slate-600 dark:text-slate-400' }, `Am IC-M505: ${solo} von ${config.lessons.length} selbst geschafft`),
            units.map((unit) => h('div', {},
                h('h3', { class: 'text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-1' }, unit.name),
                unit.lessons.map((lesson) => {
                    const best = progress[lesson.key] ?? 0;

                    return h('div', { class: 'py-2 border-b border-slate-100 dark:border-slate-700' },
                        h('div', { class: 'flex items-center gap-2' },
                            h('span', { class: `w-3 h-3 rounded-full ${best >= 3 ? 'bg-emerald-500' : best > 0 ? 'bg-amber-400' : 'border border-slate-400'}`, 'aria-hidden': 'true' }),
                            h('span', { class: 'flex-1 font-medium text-slate-800 dark:text-slate-100' }, lesson.title),
                            lesson.pruefung ? h('span', { class: 'text-[11px] px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300' }, `Prüfung ${lesson.pruefung}`) : null,
                        ),
                        h('div', { class: 'flex gap-1 mt-1 pl-5' }, [1, 2, 3].map((level) => h('button', {
                            type: 'button',
                            class: `text-[11px] px-2 py-1 rounded border ${best >= level ? 'border-emerald-500 text-emerald-700 dark:text-emerald-300' : 'border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300'} hover:bg-slate-50 dark:hover:bg-slate-700`,
                            onclick: () => startLesson(lesson, level),
                        }, LEVEL_LABELS[level]))),
                    );
                }),
            )),
        );
    }

    function lessonPanel() {
        const status = run.status();
        const lesson = run.lesson;
        const speech = lastSpeech && (status.awaitingSpeech || lastSpeech.stepIndex === status.index || status.done) ? lastSpeech : null;

        return h('div', { class: 'space-y-3' },
            h('button', { type: 'button', class: 'text-xs text-slate-600 dark:text-slate-400 underline', onclick: () => { run = null; renderSidebar(); render(); } }, '← Alle Lektionen'),
            h('h2', { class: 'font-semibold text-base text-slate-800 dark:text-white' }, lesson.title),
            h('p', { class: 'text-slate-600 dark:text-slate-300' }, lesson.intro),
            h('p', { class: 'text-xs text-slate-500 dark:text-slate-400' }, `${LEVEL_LABELS[run.level]} · ${status.done ? 'fertig' : `Schritt ${status.index + 1} von ${status.total}`}`),
            status.done
                ? h('div', { class: 'p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-200' },
                    run.level >= 3 ? 'Geschafft – selbst, ohne Hilfe.' : 'Geschafft. Versuch es als Nächstes mit weniger Hilfe.',
                    run.level < 3 ? h('button', { type: 'button', class: 'block mt-2 underline', onclick: () => startLesson(lesson, run.level + 1) }, `Weiter: ${LEVEL_LABELS[run.level + 1]}`) : null)
                : h('div', { class: 'p-3 rounded-lg bg-slate-50 dark:bg-slate-700/50 space-y-2' },
                    h('p', { class: 'font-medium text-slate-800 dark:text-slate-100' }, status.text),
                    status.help ? h('p', { class: 'text-slate-600 dark:text-slate-300' }, status.help) : null),
            speech ? h('div', { class: 'space-y-1' },
                speech.checking ? h('p', { class: 'text-slate-500' }, 'Funkspruch wird geprüft …') : null,
                speech.error ? h('p', { class: 'text-red-700 dark:text-red-300' }, 'Die Prüfung ist fehlgeschlagen. Bitte noch einmal senden.') : null,
                (speech.checks ?? []).map((c) => h('p', { class: c.ok ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300' }, `${c.ok ? '✓' : '✗'} ${c.label}`)),
                ! speech.checking && ! speech.passed && ! speech.error ? h('p', { class: 'text-slate-600 dark:text-slate-300' }, 'Noch nicht vollständig – sende den Funkspruch noch einmal.') : null,
            ) : null,
        );
    }

    function renderSidebar() {
        sidebar.replaceChildren(
            h('div', { class: 'text-xs text-slate-600 dark:text-slate-400' },
                h('div', { class: 'font-semibold text-slate-800 dark:text-white text-sm' }, `${config.device} · Seefunk`),
                `${config.ship.label} · Rufzeichen ${config.ship.callsign} · MMSI ${config.ship.mmsi}`),
            run ? lessonPanel() : lessonList(),
            h('details', { class: 'text-xs text-slate-600 dark:text-slate-400' },
                h('summary', { class: 'cursor-pointer font-semibold' }, 'Hilfe zur Bedienung'),
                h('ul', { class: 'list-disc pl-4 mt-2 space-y-1' },
                    h('li', {}, 'Tasten anklicken oder antippen; DISTRESS und POWER (Aus) gedrückt halten.'),
                    h('li', {}, 'Drehknöpfe mit dem Mausrad, durch Ziehen nach oben/unten oder mit den Pfeiltasten.'),
                    h('li', {}, 'Den großen Knopf drücken = ENTER.'),
                    h('li', {}, 'H/L gedrückt halten und DIAL drücken: Kanalgruppe wechseln.'),
                    h('li', {}, 'Sprechtaste am Hörer oder Leertaste gedrückt halten und sprechen; ohne Spracherkennung den Funkspruch eintippen.'),
                ),
            ),
        );
    }

    // --- Darstellung ----------------------------------------------------------
    function render() {
        const display = radio.display();
        const status = run?.status();
        device.update(display, radio.state, status?.target ?? null);
    }

    let lastBeep = 0;
    setInterval(() => {
        radio.tick();
        const display = radio.display();
        const now = Date.now();
        if ((display.alarm || radio.state.screen === 'distressHold') && now - lastBeep > 700) {
            beep(display.alarm ? 2200 : 1400, 180);
            lastBeep = now;
        }
        render();
    }, 100);

    renderSidebar();
    render();
}

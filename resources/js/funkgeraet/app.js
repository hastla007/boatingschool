// Oberfläche der Gerätesimulation: verbindet Gerätelogik, Zeichnung,
// Lektionen, Prüfungsmodus, Freies Üben und Server. Konfiguration
// (Lektionen, Prüfungsreihen, Übungsschiff, URLs, Fortschritt) kommt aus
// einem JSON-Block der Seite; Gesprochenes bewertet der Server mit denselben
// Regeln wie die Praxis-Prüfungssimulation. Gegenstationen sprechen mit der
// Sprachausgabe des Browsers, bis Sprecheraufnahmen vorliegen.

import { DEVICES, examLessons, lessonAvailable, prepareLesson } from './devices/index.js';
import { createLessonRun, EXAM_ATTEMPTS, missLabel } from './lessons.js';
import { createFreePractice, FREE_EVENTS } from './free-practice.js';

const LEVEL_LABELS = { 1: 'Mit Hilfe', 2: 'Nur Hinweis', 3: 'Allein' };
const MODES = { lessons: 'Lektionen', exam: 'Prüfung', free: 'Freies Üben' };
const MISSED = {
    off: () => 'Dein Gerät ist aus – nichts zu hören.',
    channel: (voice, radio) => `Auf Kanal ${voice.channel} wurde gesprochen, dein Gerät steht auf Kanal ${radio.state.channel}.`,
    volume: () => 'Die Lautstärke ist ganz zugedreht.',
    group: (voice, radio) => (voice.group === 'ATIS' ? `Das war Binnenfunk (Kanalgruppe ATIS), dein Gerät steht in der Kanalgruppe ${radio.state.group}.` : 'Das war Seefunk, dein Gerät steht im Binnenmodus (ATIS).'),
    squelch: () => 'Die Rauschsperre ist zu weit zugedreht – schwache Stationen werden weggedrückt.',
};
const START = { power: true, vol: 5, sql: 3, channel: 16, position: [54, 10.0, 'N', 7, 52.0, 'E'], utc: '12:30' };
const START_INLAND = { power: true, vol: 5, sql: 3, group: 'ATIS', channel: 10, highPower: false };
const SERVICES = { SRC: 'Seefunk', UBI: 'Binnenfunk' };

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

const button = (label, onclick, extra = '') => h('button', { type: 'button', class: `text-xs px-2 py-1 rounded border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 ${extra}`, onclick }, label);

export function mount(root, config) {
    // Gerätewahl: zuletzt gewähltes Gerät (nur Komfort, darf fehlen).
    const deviceList = (config.devices ?? [{ key: 'ic-m505', name: 'Icom IC-M505' }]).filter((d) => DEVICES[d.key]);
    let deviceKey = deviceList[0].key;
    try {
        const saved = window.localStorage.getItem('geraetesimulation.device');
        if (deviceList.some((d) => d.key === saved)) deviceKey = saved;
    } catch { /* ohne Speicher: erstes Gerät */ }
    const progressByDevice = Object.fromEntries(deviceList.map((d) => [d.key, { ...(config.progress?.[d.key] ?? {}) }]));
    const progressOf = () => progressByDevice[deviceKey];
    const deviceName = () => DEVICES[deviceKey].name;
    const visibleLessons = () => config.lessons.filter((l) => lessonAvailable(l, deviceKey));
    const exams = config.exams ?? {};
    const lessonByKey = Object.fromEntries(config.lessons.map((l) => [l.key, l]));
    const catalogs = config.catalogs ?? ['SRC'];
    // Ausgangslage für Freies Üben und den Start: Seefunk, in reinen
    // Binnenkursen der Binnenmodus.
    const defaultStart = catalogs.includes('SRC') ? START : START_INLAND;
    const speechLang = () => run?.lesson.lang ?? config.speechLang ?? 'en-GB';
    let mode = 'lessons';
    let run = null;
    let lastSpeech = null;
    let lastMiss = null;
    let exam = null;
    let free = null;
    let audio = null;
    // Zeitsprünge im Freien Üben verschieben die Uhr des Geräts.
    let timeOffset = 0;
    const clock = () => Date.now() + timeOffset;
    const log = [];

    // Zeitgesteuerte Ereignisse der laufenden Lektion bzw. Übung; beim Wechsel
    // werden sie verworfen, damit kein alter Anruf in die neue Übung platzt.
    const timers = new Set();
    const schedule = (fn, ms) => {
        const id = setTimeout(() => { timers.delete(id); fn(); }, ms);
        timers.add(id);
    };
    const clearTimers = () => { timers.forEach(clearTimeout); timers.clear(); window.speechSynthesis?.cancel(); };

    const makeRadio = () => DEVICES[deviceKey].create({ own: config.ship, addressBook: config.addressBook, directory: config.directory ?? [], clock, emit: (event) => onRadioEvent(event) });
    let radio = makeRadio();

    // --- Aufbau ---------------------------------------------------------------
    const deviceBox = h('div', { class: 'fg-device bg-slate-100 dark:bg-slate-900 rounded-2xl p-3 sm:p-5' });
    const sidebar = h('aside', { class: 'fg-sidebar bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 space-y-4 text-sm' });
    const typeBox = h('form', { class: 'mt-3 flex gap-2', onsubmit: (e) => { e.preventDefault(); sendTyped(); } },
        h('input', { type: 'text', name: 'spruch', class: 'flex-1 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm', placeholder: 'Funkspruch eintippen (statt sprechen)', 'aria-label': 'Funkspruch eintippen' }),
        h('button', { type: 'submit', class: 'px-3 py-2 rounded-lg bg-slate-800 text-white text-sm' }, 'Senden'),
    );
    const transcript = h('p', { class: 'mt-2 text-xs text-slate-600 dark:text-slate-400 min-h-[1rem]', 'aria-live': 'polite' });
    const radioLog = h('div', { class: 'mt-3 rounded-xl bg-white dark:bg-slate-800 shadow-sm p-3 text-xs space-y-1', 'aria-live': 'polite' });
    root.replaceChildren(
        h('div', { class: 'grid lg:grid-cols-[minmax(0,1fr)_22rem] gap-4' },
            h('div', {}, deviceBox, typeBox, transcript, radioLog),
            sidebar,
        ),
    );
    let device = null;

    // --- Ton und Stimmen ------------------------------------------------------
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

    const pitches = {};
    function speakAloud(text, from, lang = 'en-GB') {
        const synth = window.speechSynthesis;
        if (! synth || ! window.SpeechSynthesisUtterance) return;
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = lang;
        const voice = synth.getVoices().find((v) => v.lang === lang) ?? synth.getVoices().find((v) => v.lang?.startsWith(lang.slice(0, 2)));
        if (voice) utterance.voice = voice;
        // Jede Gegenstation klingt etwas anders.
        pitches[from] ??= 0.8 + (Object.keys(pitches).length % 4) * 0.15;
        utterance.pitch = pitches[from];
        utterance.volume = Math.min(1, radio.state.vol / 7);
        synth.speak(utterance);
    }

    function addLog(entry) {
        log.unshift(entry);
        log.length = Math.min(log.length, 8);
        renderLog();
    }

    // Eine Gegenstation spricht: hörbar nach den Regeln der Gerätelogik.
    function say(voice) {
        if (! voice.group && run) voice = { ...voice, group: run.lesson.catalog === 'UBI' ? 'ATIS' : 'INT' };
        const result = radio.hear(voice);
        if (result.heard) {
            addLog({ who: voice.from, channel: voice.channel, text: voice.text });
            speakAloud(voice.text, voice.from, voice.lang ?? speechLang());
        } else {
            const showHint = mode === 'free' || (mode === 'lessons' && run && run.level <= 2);
            if (showHint) addLog({ hint: MISSED[result.reason](voice, radio) });
        }
        render();

        return result;
    }

    // --- Bedienung ------------------------------------------------------------
    const turnKnob = (knob, steps) => {
        radio.turn(knob, steps);
        if (knob === 'channel') device.rotateChannel(steps);
        render();
    };

    // Eingerastete Tasten (Zweitasten-Bedienung mit der Maus: Umschalttaste +
    // Klick hält z. B. H/L gedrückt, bis die nächste Taste losgelassen wird).
    const latched = new Map();
    // Loslass-Funktionen der gerade gedrückten Tasten (Fensterwechsel).
    const heldKeys = new Set();
    const showLatch = (node, on) => {
        node.style.filter = on ? 'brightness(1.8)' : '';
        node.setAttribute('aria-pressed', on ? 'true' : 'false');
    };
    function releaseLatched() {
        for (const [key, node] of latched) {
            radio.keyUp(key);
            showLatch(node, false);
        }
        latched.clear();
    }
    // Neues Gerät bzw. neue Gerätelogik: nichts mehr gedrückt.
    function forgetLatched() {
        latched.forEach((node) => showLatch(node, false));
        latched.clear();
    }

    // Zeichnet das gewählte Gerät und hängt die Bedienung an seine Teile.
    function mountDevice() {
        deviceBox.replaceChildren();
        latched.clear();
        heldKeys.clear();
        device = DEVICES[deviceKey].render(deviceBox);
        const latchKeys = DEVICES[deviceKey].latch ?? [];
        device.svg.querySelectorAll('[data-key]').forEach((node) => {
            const key = node.dataset.key;
            // Gedrückt per Zeiger bzw. Tastatur; beim Fokuswechsel loslassen.
            let held = false;
            node.style.touchAction = 'none';
            const toggleLatch = () => {
                if (latched.has(key)) {
                    latched.delete(key);
                    radio.keyUp(key);
                    showLatch(node, false);
                } else {
                    radio.keyDown(key);
                    latched.set(key, node);
                    showLatch(node, true);
                }
                render();
            };
            const press = () => {
                ensureAudio();
                held = true;
                heldKeys.add(release);
                if (key === 'ptt') pttDown();
                else radio.keyDown(key);
                render();
            };
            function release() {
                if (! held) return;
                held = false;
                heldKeys.delete(release);
                if (key === 'ptt') pttUp();
                else radio.keyUp(key);
                if (key !== 'ptt' && latched.size) releaseLatched();
                render();
            }
            node.addEventListener('pointerdown', (e) => {
                e.preventDefault();
                if ((e.shiftKey || e.ctrlKey) && latchKeys.includes(key)) {
                    ensureAudio();
                    toggleLatch();

                    return;
                }
                node.setPointerCapture?.(e.pointerId);
                press();
            });
            node.addEventListener('pointerup', release);
            node.addEventListener('pointercancel', release);
            node.addEventListener('keydown', (e) => {
                if ((e.key !== 'Enter' && e.key !== ' ') || e.repeat) return;
                e.preventDefault();
                if (e.shiftKey && latchKeys.includes(key)) toggleLatch();
                else press();
            });
            node.addEventListener('keyup', (e) => {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); release(); }
            });
            node.addEventListener('blur', release);
        });

        // Taste in der Mitte eines Drehknopfs (ENTER bzw. VOL/SQL-Kappe): das
        // Mausrad dreht dort den Knopf.
        const wheel = DEVICES[deviceKey].wheel;
        if (wheel.key) device.svg.querySelector(`[data-key="${wheel.key}"]`)?.addEventListener('wheel', (e) => { e.preventDefault(); turnKnob(wheel.knob, e.deltaY < 0 ? 1 : -1); }, { passive: false });

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
    }
    mountDevice();

    // Leertaste als Sprechtaste, solange kein Bedienelement den Fokus hat.
    let spacePtt = false;
    document.addEventListener('keydown', (e) => {
        if (e.code !== 'Space' || e.repeat || e.target.closest('input, textarea, select, button, summary, a, [role="tab"], [data-key], [data-knob]')) return;
        e.preventDefault();
        ensureAudio();
        spacePtt = true;
        pttDown();
    });
    document.addEventListener('keyup', (e) => {
        // Losgelassen wird auch, wenn der Fokus inzwischen woanders ist.
        if (e.code !== 'Space' || ! spacePtt) return;
        e.preventDefault();
        spacePtt = false;
        pttUp();
    });
    // Fenster verliert den Fokus: Sprechtaste und gedrückte Tasten loslassen.
    window.addEventListener('blur', () => {
        if (spacePtt) { spacePtt = false; pttUp(); }
        [...heldKeys].forEach((release) => release());
        if (latched.size) releaseLatched();
        render();
    });

    // --- Sprechtaste und Spracherkennung ------------------------------------
    const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    let recognition = null;
    let heard = '';

    function pttDown() {
        if (! radio.pttDown()) { render(); return; }
        window.speechSynthesis?.cancel();
        heard = '';
        transcript.textContent = 'Sendet … sprich jetzt.';
        if (Recognition) {
            recognition = new Recognition();
            recognition.lang = speechLang();
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
        // Die Spracherkennung liefert erst später: der Funkspruch gehört zu
        // dieser Übung, diesem Gerät und dem Kanal beim Loslassen.
        const sentRun = run;
        const sentRadio = radio;
        const channel = radio.state.channel;
        const finish = () => {
            if (run !== sentRun || radio !== sentRadio) return;
            if (heard.trim()) transmit(heard.trim(), channel);
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

    function transmit(text, channel = radio.state.channel) {
        addLog({ who: 'Du', channel, text, own: true });
        radio.spoken(text, channel);
    }

    function sendTyped() {
        const input = typeBox.querySelector('input');
        const text = input.value.trim();
        if (! text) return;
        if (radio.pttDown()) {
            radio.pttUp();
            transcript.textContent = text;
            transmit(text);
            input.value = '';
        }
        render();
    }

    // --- Ereignisse ---------------------------------------------------------
    function onRadioEvent(event) {
        if (event.type === 'dsc_received' || event.type === 'dsc_sent') beep(2100, 200);
        if (mode === 'free' && free) free.handle(event);
        if (! run) return;
        const pending = run.handle(event);
        if (pending?.miss) {
            lastMiss = pending;
            renderSidebar();
        } else if (pending) {
            lastMiss = null;
            evaluateSpeech(pending);
        }
    }

    async function evaluateSpeech({ stepIndex, text }) {
        const current = run;
        lastSpeech = { stepIndex, checking: true, checks: [] };
        renderSidebar();
        try {
            const result = await postJson(config.urls.evaluate, { lesson: current.lesson.key, step: stepIndex, text });
            if (current !== run) return;
            lastSpeech = { stepIndex, checking: false, ...result };
            current.speechResult(stepIndex, result.passed, result.checks);
        } catch {
            if (current !== run) return;
            lastSpeech = { stepIndex, checking: false, error: true, checks: [] };
            // Ein Serverfehler ist kein Fehlversuch: der Schritt bleibt offen.
            current.speechError(stepIndex);
        }
        renderSidebar();
    }

    function freshRadio(start) {
        if (radio.state.tx) pttUp();
        clearTimers();
        forgetLatched();
        radio = makeRadio();
        radio.setup(start);
        lastSpeech = null;
        lastMiss = null;
        log.length = 0;
        renderLog();
    }

    function startRun(original, options) {
        const lesson = prepareLesson(original, deviceKey);
        freshRadio(lesson.start);
        run = createLessonRun(lesson, { ...options, schedule, receive: (call) => {
            // false: Gerät aus bzw. im Binnenmodus – die Lektion ruft später erneut.
            const taken = radio.receive(call) !== false;
            render();

            return taken;
        }, say });
        run.onChange((status) => {
            if (status.done && ! options.exam) finishLesson(lesson, options.level);
            if (status.done && options.exam) recordExamTask(status);
            renderSidebar();
            render();
        });
        run.start();
        renderSidebar();
        render();
    }

    // --- Lektionen ------------------------------------------------------------
    async function finishLesson(lesson, level) {
        const progress = progressOf();
        progress[lesson.key] = Math.max(progress[lesson.key] ?? 0, level);
        try { await postJson(config.urls.progress, { lesson: lesson.key, level, device: deviceKey }); } catch { /* Fortschritt bleibt lokal sichtbar */ }
        renderSidebar();
    }

    function lessonList() {
        const progress = progressOf();
        const lessons = visibleLessons();
        const units = [];
        for (const lesson of lessons) {
            let unit = units.find((u) => u.name === lesson.unit);
            if (! unit) units.push(unit = { name: lesson.unit, lessons: [] });
            unit.lessons.push(lesson);
        }
        const solo = lessons.filter((l) => (progress[l.key] ?? 0) >= 3).length;

        return h('div', { class: 'space-y-3' },
            h('p', { class: 'text-xs text-slate-600 dark:text-slate-400' }, `Am ${deviceName()}: ${solo} von ${lessons.length} selbst geschafft`),
            units.map((unit) => h('details', { class: 'group', open: unit.lessons.some((l) => (progress[l.key] ?? 0) < 3) ? true : null },
                h('summary', { class: 'cursor-pointer text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-1' },
                    `${unit.name} · ${unit.lessons.filter((l) => (progress[l.key] ?? 0) >= 3).length}/${unit.lessons.length}`),
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
                            onclick: () => startRun(lesson, { level }),
                        }, LEVEL_LABELS[level]))),
                    );
                }),
            )),
        );
    }

    function speechFeedback(status) {
        const speech = lastSpeech && (status.awaitingSpeech || lastSpeech.stepIndex === status.index || status.done) ? lastSpeech : null;
        const miss = lastMiss && lastMiss.stepIndex === status.index && ! status.done ? lastMiss : null;

        return h('div', { class: 'space-y-1' },
            miss ? h('p', { class: 'text-red-700 dark:text-red-300' }, `Dieser Funkspruch gehört auf ${missLabel(miss.expected)}.`) : null,
            speech?.checking ? h('p', { class: 'text-slate-500' }, 'Funkspruch wird geprüft …') : null,
            speech?.error ? h('p', { class: 'text-red-700 dark:text-red-300' }, 'Die Prüfung ist fehlgeschlagen. Bitte noch einmal senden.') : null,
            (speech?.checks ?? []).map((c) => h('p', { class: c.ok ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300' }, `${c.ok ? '✓' : '✗'} ${c.label}`)),
            speech && ! speech.checking && ! speech.passed && ! speech.error && ! status.done ? h('p', { class: 'text-slate-600 dark:text-slate-300' }, 'Noch nicht vollständig – sende den Funkspruch noch einmal.') : null,
        );
    }

    function lessonPanel() {
        const status = run.status();
        const lesson = run.lesson;

        return h('div', { class: 'space-y-3' },
            h('button', { type: 'button', class: 'text-xs text-slate-600 dark:text-slate-400 underline', onclick: () => { run = null; clearTimers(); renderSidebar(); render(); } }, '← Alle Lektionen'),
            h('h2', { class: 'font-semibold text-base text-slate-800 dark:text-white' }, lesson.title),
            h('p', { class: 'text-slate-600 dark:text-slate-300' }, lesson.intro),
            h('p', { class: 'text-xs text-slate-500 dark:text-slate-400' }, `${LEVEL_LABELS[run.level]} · ${status.done ? 'fertig' : `Schritt ${status.index + 1} von ${status.total}`}`),
            status.done
                ? h('div', { class: 'p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-200' },
                    run.level >= 3 ? 'Geschafft – selbst, ohne Hilfe.' : 'Geschafft. Versuch es als Nächstes mit weniger Hilfe.',
                    run.level < 3 ? h('button', { type: 'button', class: 'block mt-2 underline', onclick: () => startRun(lesson, { level: run.level + 1 }) }, `Weiter: ${LEVEL_LABELS[run.level + 1]}`) : null)
                : h('div', { class: 'p-3 rounded-lg bg-slate-50 dark:bg-slate-700/50 space-y-2' },
                    h('p', { class: 'font-medium text-slate-800 dark:text-slate-100' }, status.text),
                    status.help ? h('p', { class: 'text-slate-600 dark:text-slate-300' }, status.help) : null),
            speechFeedback(status),
        );
    }

    // --- Prüfungsmodus --------------------------------------------------------
    function startExam(series) {
        exam = { series, lessons: examLessons(exams[series], deviceKey), index: 0, tasks: {}, summary: null };
        startExamTask();
    }

    function startExamTask() {
        const lesson = lessonByKey[exam.lessons[exam.index]];
        // Binnenprüfung: Gerät zu Beginn aus und in der Kanalgruppe INT.
        const start = exams[exam.series].start;
        startRun(start ? { ...lesson, start } : lesson, { exam: true, level: 3 });
    }

    function recordExamTask(status) {
        if (! exam) return;
        exam.tasks[run.lesson.key] = { passed: ! status.failed, results: status.results };
    }

    async function finishExam() {
        // Die Antwort kann kommen, wenn längst eine andere Prüfung oder ein
        // anderes Gerät gewählt ist: dann nur speichern, nichts anzeigen.
        const current = exam;
        const device = deviceKey;
        const passed = Object.entries(current.tasks).filter(([, t]) => t.passed).map(([key]) => key);
        run = null;
        clearTimers();
        current.summary = { loading: true };
        renderSidebar();
        try {
            const summary = await postJson(config.urls.exam, { series: current.series, passed, device });
            exams[current.series].best = { ...(exams[current.series].best ?? {}), [device]: summary.best };
            current.summary = summary;
        } catch {
            current.summary = { error: true, passed: passed.length, total: current.lessons.length };
        }
        if (exam === current) renderSidebar();
    }

    function examPanel() {
        if (! exam) {
            return h('div', { class: 'space-y-3' },
                h('p', { class: 'text-slate-600 dark:text-slate-300' }, 'Aufgaben wie in der praktischen Prüfung: am Stück, ohne Hilfe, zwei Versuche je Funkspruch. Am Ende siehst du jede Aufgabe mit erfüllten und fehlenden Prüfpunkten und der Musterlösung.'),
                catalogs.includes('UBI') ? h('p', { class: 'text-xs text-slate-500 dark:text-slate-400' }, 'UBI: Das Gerät ist zu Beginn aus und steht in der Kanalgruppe INT – einschalten und auf ATIS stellen gehört zur Aufgabe.') : null,
                Object.entries(exams).map(([series, info]) => h('div', { class: 'p-3 rounded-lg border border-slate-200 dark:border-slate-700 space-y-2' },
                    h('div', { class: 'flex items-center gap-2' },
                        h('span', { class: 'font-semibold text-slate-800 dark:text-white flex-1' }, info.title ?? `Reihe ${series}`),
                        info.best?.[deviceKey] !== null && info.best?.[deviceKey] !== undefined ? h('span', { class: 'text-xs text-slate-500' }, `bestes Ergebnis ${info.best[deviceKey]} von ${info.lessons.length}`) : null),
                    h('ol', { class: 'list-decimal pl-5 text-xs text-slate-600 dark:text-slate-300' }, examLessons(info, deviceKey).map((key) => h('li', {}, lessonByKey[key]?.title ?? key))),
                    button(`${info.title ?? `Reihe ${series}`} starten`, () => startExam(series), 'font-semibold'),
                )),
            );
        }

        if (exam.summary) return examSummary();

        const status = run.status();
        const lesson = run.lesson;
        const last = exam.index === exam.lessons.length - 1;

        return h('div', { class: 'space-y-3' },
            h('p', { class: 'text-xs text-slate-500 dark:text-slate-400' }, `${exams[exam.series].title ?? `Reihe ${exam.series}`} · Aufgabe ${exam.index + 1} von ${exam.lessons.length}`),
            h('h2', { class: 'font-semibold text-base text-slate-800 dark:text-white' }, lesson.title),
            h('p', { class: 'text-slate-700 dark:text-slate-200' }, lesson.intro),
            status.done
                ? h('div', { class: `p-3 rounded-lg ${status.failed ? 'bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200' : 'bg-emerald-50 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-200'}` },
                    status.failed ? 'Aufgabe nicht bestanden.' : 'Aufgabe bestanden.',
                    h('div', { class: 'mt-2' }, last ? button('Prüfung auswerten', finishExam, 'font-semibold') : button('Nächste Aufgabe', () => { exam.index++; startExamTask(); }, 'font-semibold')))
                : h('div', { class: 'space-y-2' },
                    status.awaitingSpeech ? h('p', { class: 'text-slate-500' }, 'Funkspruch wird geprüft …') : null,
                    h('p', { class: 'text-xs text-slate-500 dark:text-slate-400' }, `Versuche für den nächsten Funkspruch: ${status.attemptsLeft} von ${EXAM_ATTEMPTS}`),
                    lastMiss && lastMiss.stepIndex === status.index ? h('p', { class: 'text-red-700 dark:text-red-300' }, 'Falscher Kanal oder falsche Sendeleistung – das war ein Fehlversuch.') : null,
                    lastSpeech && ! lastSpeech.checking && ! lastSpeech.passed && ! lastSpeech.error && lastSpeech.stepIndex === status.index ? h('p', { class: 'text-red-700 dark:text-red-300' }, 'Funkspruch unvollständig – das war ein Fehlversuch.') : null,
                    lastSpeech?.error && lastSpeech.stepIndex === status.index ? h('p', { class: 'text-red-700 dark:text-red-300' }, 'Die Prüfung des Funkspruchs ist fehlgeschlagen – kein Fehlversuch. Bitte noch einmal senden.') : null,
                    button('Aufgabe aufgeben', () => run.giveUp())),
            button('Prüfung abbrechen', () => { exam = null; run = null; clearTimers(); renderSidebar(); render(); }, 'mt-2'),
        );
    }

    function examSummary() {
        const s = exam.summary;
        if (s.loading) return h('p', { class: 'text-slate-500' }, 'Prüfung wird ausgewertet …');

        return h('div', { class: 'space-y-3' },
            h('h2', { class: 'font-semibold text-base text-slate-800 dark:text-white' }, `${exams[exam.series].title ?? `Reihe ${exam.series}`}: ${s.passed} von ${s.total} Aufgaben bestanden`),
            s.error ? h('p', { class: 'text-red-700 dark:text-red-300' }, 'Das Ergebnis konnte nicht gespeichert werden; die Musterlösungen fehlen deshalb.') : null,
            exam.lessons.map((key, i) => {
                const task = exam.tasks[key];
                const solutions = s.solutions?.[key] ?? [];

                return h('details', { class: 'p-2 rounded-lg border border-slate-200 dark:border-slate-700' },
                    h('summary', { class: `cursor-pointer font-medium ${task?.passed ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300'}` }, `${task?.passed ? '✓' : '✗'} ${i + 1}. ${lessonByKey[key]?.title ?? key}`),
                    (task?.results ?? []).map((r) => h('div', { class: 'mt-2 space-y-0.5' },
                        r.text ? h('p', { class: 'text-xs italic text-slate-500' }, `„${r.text}“`) : null,
                        r.checks.map((c) => h('p', { class: `text-xs ${c.ok ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300'}` }, `${c.ok ? '✓' : '✗'} ${c.label}`)))),
                    task && ! task.results?.length && ! task.passed ? h('p', { class: 'mt-2 text-xs text-slate-500' }, 'Geräteaktionen nicht vollständig ausgeführt oder aufgegeben.') : null,
                    solutions.map((text) => h('p', { class: 'mt-2 text-xs text-slate-700 dark:text-slate-200 whitespace-pre-line' }, h('strong', {}, 'Musterlösung: '), text)),
                );
            }),
            button('Zur Übersicht', () => { exam = null; renderSidebar(); }),
        );
    }

    // --- Freies Üben ----------------------------------------------------------
    function startFree() {
        freshRadio(defaultStart);
        timeOffset = 0;
        run = null;
        free = createFreePractice({ ship: config.ship, schedule, receive: (call) => { radio.receive(call); render(); }, say, radio: () => radio });
        renderSidebar();
        render();
    }

    function freePanel() {
        if (! free) startFree();

        return h('div', { class: 'space-y-3' },
            h('p', { class: 'text-slate-600 dark:text-slate-300' }, 'Löse Ereignisse aus und reagiere frei darauf. Freies Üben zählt nicht zum Fortschritt.'),
            FREE_EVENTS.filter((group) => catalogs.includes(group.catalog)).map((group) => h('div', { class: 'space-y-1' },
                h('h3', { class: 'text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400' }, group.title),
                h('div', { class: 'flex flex-wrap gap-1' }, group.items.map((item) => button(item.label, () => { ensureAudio(); free.trigger(item.key); render(); }))),
            )),
            h('div', { class: 'space-y-1' },
                h('h3', { class: 'text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400' }, 'Zeit'),
                h('div', { class: 'flex flex-wrap gap-1' },
                    button('+5 Minuten', () => jump(5 * 60 * 1000)),
                    button('+4 Stunden', () => jump(4 * 60 * 60 * 1000 + 60 * 1000))),
            ),
            catalogs.includes('SRC') ? h('p', { class: 'text-xs text-slate-500 dark:text-slate-400' }, 'Eigene Anrufe im Seefunk: Möwe, Seestern und Nordsee Radio antworten auf deine DSC-Anrufe und Funksprüche, Bremen Rescue auf einen Notalarm. Im Binnenfunk wird nur abgehört; Gespräche mit Antwort übst du in den Lektionen.') : null,
            button('Neu starten', startFree),
        );
    }

    function jump(ms) {
        timeOffset += ms;
        radio.tick();
        addLog({ hint: `Zeit vorgestellt um ${ms >= 3600000 ? `${Math.round(ms / 3600000)} Stunden` : `${Math.round(ms / 60000)} Minuten`}.` });
        render();
    }

    // --- Seitenleiste ---------------------------------------------------------
    function switchDevice(next) {
        if (next === deviceKey || ! DEVICES[next]) return;
        deviceKey = next;
        try { window.localStorage.setItem('geraetesimulation.device', next); } catch { /* egal */ }
        run = null;
        exam = null;
        free = null;
        clearTimers();
        radio = makeRadio();
        mountDevice();
        if (mode === 'free') startFree();
        else freshRadio(defaultStart);
        renderSidebar();
        render();
    }

    function switchMode(next) {
        if (next === mode) return;
        mode = next;
        run = null;
        exam = null;
        free = null;
        clearTimers();
        if (mode === 'free') startFree();
        else freshRadio(defaultStart);
        renderSidebar();
        render();
    }

    function renderSidebar() {
        sidebar.replaceChildren(
            h('div', { class: 'text-xs text-slate-600 dark:text-slate-400' },
                h('div', { class: 'font-semibold text-slate-800 dark:text-white text-sm' }, `${deviceName()} · ${catalogs.map((c) => SERVICES[c]).join(' und ')}`),
                [config.ship.label, `Rufzeichen ${config.ship.callsign}`, catalogs.includes('SRC') ? `MMSI ${config.ship.mmsi}` : null, catalogs.includes('UBI') && config.ship.atis ? `ATIS ${config.ship.atis}` : null].filter(Boolean).join(' · ')),
            deviceList.length > 1 ? h('label', { class: 'flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300' }, 'Gerät',
                h('select', { class: 'flex-1 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-xs py-1', 'aria-label': 'Funkgerät wählen', onchange: (e) => switchDevice(e.target.value) },
                    deviceList.map((d) => h('option', { value: d.key, selected: d.key === deviceKey ? true : null }, d.name)))) : null,
            h('div', { class: 'flex gap-1', role: 'tablist' }, Object.entries(MODES).map(([key, label]) => h('button', {
                type: 'button',
                role: 'tab',
                'aria-selected': mode === key ? 'true' : 'false',
                class: `flex-1 text-xs px-2 py-1.5 rounded-lg ${mode === key ? 'bg-slate-800 text-white dark:bg-slate-200 dark:text-slate-900' : 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200'}`,
                onclick: () => switchMode(key),
            }, label))),
            mode === 'lessons' ? (run ? lessonPanel() : lessonList()) : mode === 'exam' ? examPanel() : freePanel(),
            h('details', { class: 'text-xs text-slate-600 dark:text-slate-400' },
                h('summary', { class: 'cursor-pointer font-semibold' }, 'Hilfe zur Bedienung'),
                h('ul', { class: 'list-disc pl-4 mt-2 space-y-1' },
                    (DEVICES[deviceKey].controls ?? []).map((text) => h('li', {}, text)),
                    h('li', {}, 'Sprechtaste am Hörer oder Leertaste gedrückt halten und sprechen; ohne Spracherkennung den Funkspruch eintippen.'),
                    h('li', {}, 'Gegenstationen sprechen mit der Stimme des Browsers; mitlesen kannst du im Funkverkehr unter dem Gerät.'),
                ),
            ),
        );
    }

    function renderLog() {
        const lines = log.length
            ? log.map((entry) => entry.hint
                ? h('p', { class: 'text-amber-700 dark:text-amber-300' }, entry.hint)
                : h('p', { class: entry.own ? 'text-slate-500 dark:text-slate-400' : 'text-slate-800 dark:text-slate-100' }, h('strong', {}, `${entry.who} · Kanal ${String(entry.channel).padStart(2, '0')}: `), entry.text))
            : [h('p', { class: 'text-slate-500 dark:text-slate-400' }, 'Noch nichts gehört.')];
        radioLog.replaceChildren(h('div', { class: 'font-semibold text-slate-700 dark:text-slate-200' }, 'Funkverkehr'), ...lines);
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

    radio.setup(defaultStart);
    renderSidebar();
    renderLog();
    render();
}

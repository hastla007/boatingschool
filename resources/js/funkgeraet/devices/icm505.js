// Gerätelogik Icom IC-M505 (Stufe 1 der Gerätesimulation). Nachgebildet nach
// dem Geräteprofil aus der Bedienungsanleitung: Tasten mit Druckdauer (kurz,
// 1 s, 3 s, DISTRESS 5 s), H/L als Umschalttaste, Display und DSC-Menü.
// Reine Logik ohne DOM: die Oberfläche ruft keyDown/keyUp/turn/tick auf und
// zeichnet display(); jede Aktion wird als Ereignis gemeldet, das die
// Lektionen prüfen. Uhrzeit kommt von außen (clock), damit Tests
// Haltedauern ohne Warten durchspielen können.

import { CHANNEL_GROUPS, channelInfo, stepChannel } from '../channels.js';

export const NATURES = ['Undesignated', 'Explosion', 'Flooding', 'Collision', 'Grounding', 'Capsizing', 'Sinking', 'Adrift', 'Abandoning', 'Piracy', 'MOB'];

export const CATEGORIES = ['Routine', 'Safety', 'Urgency', 'Distress'];

const LONG = 1000;
const VERY_LONG = 3000;
const DISTRESS_HOLD = 5000;
const DISTRESS_REPEAT = 4 * 60 * 1000;
const AUTO_RETURN = 2000;

const MAIN_MENU = ['Position Input', 'Individual Call', 'Group Call', 'All Ships Call', 'Position Request', 'Position Report', 'Polling Request', 'Received Calls', 'Distress Setting', 'Set up', 'Exit'];
const SETUP_MENU = ['Add:INDV ID', 'Add:Group ID', 'DEL:INDV ID', 'DEL:Group ID', 'Offset Time', 'MMSI Check', 'Auto ACK', 'NMEA Output', 'Exit'];
// In Stufe 1 bedienbar; die übrigen Menüpunkte folgen in Stufe 2.
const AVAILABLE = new Set(['Position Input', 'Individual Call', 'All Ships Call', 'Received Calls', 'Distress Setting', 'Set up', 'Exit', 'MMSI Check']);

export const DEVICE = {
    key: 'ic-m505',
    name: 'Icom IC-M505',
    keys: ['distressCover', 'distress', 'menu', 'clr', 'hail', 'ic', 'ch16', 'dial', 'scan', 'hl', 'power', 'enter', 'micUp', 'micDown', 'micHiLo'],
    knobs: ['channel', 'vol', 'sql'],
};

function pad(n, width = 2) {
    return String(n).padStart(width, '0');
}

export function formatPosition(pos) {
    if (! pos) return null;
    const [latD, latM, ns, lonD, lonM, ew] = pos;

    return [`${pad(latD)}°${latM.toFixed(3).padStart(6, '0')}${ns}`, `${pad(lonD, 3)}°${lonM.toFixed(3).padStart(6, '0')}${ew}`];
}

export function createIcM505({ own, clock = () => Date.now(), emit = () => {}, addressBook = [] } = {}) {
    const s = {
        power: false,
        vol: 0,
        sql: 0,
        group: 'INT',
        channel: 16,
        workChannel: 16,
        callChannel: { INT: 16, DSC: 16, ATIS: 10 },
        highPower: true,
        watch: 'off',
        tx: false,
        screen: 'off',
        cover: false,
        cursor: 0,
        menu: null,
        nature: 0,
        position: null,
        utc: null,
        editor: null,
        call: null,
        distress: null,
        received: [],
        incoming: null,
        screenSince: 0,
    };
    const pressed = {};

    // "radio" ist der Gerätezustand nach dem Ereignis; die Ereignisfelder selbst
    // (z. B. state der Rauschsperre) bleiben davon unberührt.
    const event = (type, data = {}) => emit({ type, at: clock(), ...data, radio: snapshot() });

    function snapshot() {
        return { power: s.power, group: s.group, channel: s.channel, highPower: effectiveHighPower(), vol: s.vol, sql: s.sql, screen: s.screen, watch: s.watch, nature: NATURES[s.nature], position: s.position, utc: s.utc, coverOpen: s.cover, tx: s.tx };
    }

    function current() {
        return channelInfo(s.group, s.channel);
    }

    function effectiveHighPower() {
        return s.highPower && ! current()?.lowPowerOnly;
    }

    function setScreen(screen) {
        const changed = s.screen !== screen;
        s.screen = screen;
        s.screenSince = clock();
        if (changed) event('screen', { screen });
    }

    function setChannel(channel, { work = true, group = s.group } = {}) {
        s.group = group;
        s.channel = channel;
        s.onCall = false;
        if (work && channel !== 16 && channel !== s.callChannel[s.group]) s.workChannel = channel;
        event('channel', { channel, group });
    }

    // --- Ein/Aus, Lautstärke, Rauschsperre --------------------------------

    function powerOn() {
        s.power = true;
        setScreen('normal');
        event('power', { on: true });
    }

    function powerOff() {
        Object.assign(s, { power: false, tx: false, menu: null, editor: null, call: null, incoming: null, cover: false });
        setScreen('off');
        event('power', { on: false });
    }

    // Rauschen ist hörbar, solange die Rauschsperre ganz offen ist; ab Stufe 7
    // werden schwache Stationen weggedrückt (Lektion „Hören und senden“).
    function squelchState() {
        if (! s.power) return 'off';
        if (s.sql <= 1) return 'noise';
        if (s.sql >= 7) return 'tight';

        return 'set';
    }

    // --- Menüs ------------------------------------------------------------

    function openMenu(title, items, back = null) {
        s.menu = { title, items, back };
        s.cursor = 0;
        setScreen('menu');
    }

    // Ohne angeschlossenen GPS-Empfänger steht „Position Input“ im Menü; die
    // Simulation hat keinen GPS-Empfänger.
    function mainMenuItems() {
        return MAIN_MENU;
    }

    function closeToNormal() {
        s.menu = null;
        s.editor = null;
        setScreen(s.distress?.waiting ? 'distressWait' : 'normal');
    }

    function chooseMenuItem() {
        const item = s.menu.items[s.cursor];

        if (! AVAILABLE.has(item)) {
            event('menu_unavailable', { item });

            return;
        }

        event('menu', { item });

        switch (item) {
            case 'Exit':
                if (s.menu.back) openMenu(...s.menu.back);
                else closeToNormal();
                break;
            case 'Set up':
                openMenu('Set up', SETUP_MENU, ['Select Item', mainMenuItems()]);
                break;
            case 'MMSI Check':
                setScreen('mmsiCheck');
                event('mmsi_checked', { mmsi: own?.mmsi });
                break;
            case 'Distress Setting':
                s.cursor = s.nature;
                setScreen('nature');
                break;
            case 'Position Input':
                startEditor('position');
                break;
            case 'Individual Call':
                s.call = { kind: 'individual' };
                s.cursor = 0;
                setScreen('callAddress');
                break;
            case 'All Ships Call':
                s.call = { kind: 'all_ships' };
                s.cursor = 0;
                setScreen('callCategory');
                break;
            case 'Received Calls':
                s.cursor = 0;
                setScreen('receivedList');
                break;
        }
    }

    // --- Ziffern-Editor für Position, UTC und MMSI ------------------------

    function startEditor(kind) {
        const templates = {
            position: { title: 'Input Position', template: '##°##.###N ###°##.###E', next: 'utc' },
            utc: { title: 'Input UTC Time', template: '##:##', next: null },
            mmsi: { title: 'Input 9 digits', template: '#########', next: 'category' },
        };
        const t = templates[kind];
        const chars = t.template.split('').map((c) => (c === '#' ? '0' : c));
        const editable = t.template.split('').map((c, i) => (c === '#' || c === 'N' || c === 'E' ? i : -1)).filter((i) => i >= 0);
        s.editor = { kind, title: t.title, chars, editable, pos: 0, next: t.next };
        setScreen('editor');
    }

    function editorTurn(steps) {
        const e = s.editor;
        const i = e.editable[e.pos];
        const c = e.chars[i];

        if (c === 'N' || c === 'S') e.chars[i] = c === 'N' ? 'S' : 'N';
        else if (c === 'E' || c === 'W') e.chars[i] = c === 'E' ? 'W' : 'E';
        else e.chars[i] = String((((Number(c) + steps) % 10) + 10) % 10);
    }

    function editorMove(direction) {
        const e = s.editor;
        e.pos = Math.max(0, Math.min(e.editable.length - 1, e.pos + direction));
    }

    function editorConfirm() {
        const e = s.editor;
        const text = e.chars.join('');

        if (e.kind === 'position') {
            const m = text.match(/^(\d\d)°(\d\d\.\d\d\d)([NS]) (\d\d\d)°(\d\d\.\d\d\d)([EW])$/);
            s.position = [Number(m[1]), Number(m[2]), m[3], Number(m[4]), Number(m[5]), m[6]];
            event('position_set', { position: s.position });
            startEditor('utc');
        } else if (e.kind === 'utc') {
            s.utc = text;
            event('utc_set', { utc: s.utc });
            closeToNormal();
        } else if (e.kind === 'mmsi') {
            s.call.address = { name: null, mmsi: text };
            s.editor = null;
            s.cursor = 0;
            setScreen('callCategory');
        }
    }

    // --- DSC-Anrufe --------------------------------------------------------

    function callAddressItems() {
        return ['Manual Input', ...addressBook.map((a) => a.name)];
    }

    function chooseCallStep() {
        const c = s.call;

        if (s.screen === 'callAddress') {
            if (s.cursor === 0) {
                startEditor('mmsi');

                return;
            }
            c.address = addressBook[s.cursor - 1];
            s.cursor = 0;
            setScreen('callCategory');
        } else if (s.screen === 'callCategory') {
            c.category = CATEGORIES[s.cursor];
            s.cursor = 0;
            if (c.kind === 'individual') {
                c.channel = 8;
                setScreen('callChannel');
            } else {
                setScreen('callReady');
            }
        } else if (s.screen === 'callChannel') {
            setScreen('callReady');
        } else if (s.screen === 'callReady') {
            event('dsc_sent', { kind: c.kind, address: c.address ?? null, category: c.category, channel: c.channel ?? null });
            setScreen('callTx');
            if (c.kind === 'individual') c.waiting = true;
        }
    }

    // --- Notalarm ----------------------------------------------------------

    function sendDistress(repeat = false) {
        const nature = NATURES[s.nature];
        s.distress = { nature, sentAt: clock(), nextAt: clock() + DISTRESS_REPEAT, waiting: true, acked: false, count: (s.distress?.count ?? 0) + 1 };
        s.channel = 70;
        setScreen('distressSent');
        event('dsc_sent', { kind: 'distress', nature, position: s.position, utc: s.utc, repeat });
    }

    function cancelDistressRepeat() {
        if (! s.distress?.waiting) return;
        s.distress.waiting = false;
        event('distress_repeat_cancelled', { nature: s.distress.nature });
        setScreen('normal');
    }

    // --- Tastenlogik -------------------------------------------------------

    function shortPress(key) {
        if (! s.power) {
            if (key === 'power') powerOn();

            return;
        }

        if (key === 'distressCover') {
            s.cover = ! s.cover;
            event('cover', { open: s.cover });

            return;
        }

        if (s.incoming && key === 'clr') {
            event('alarm_stopped', { call: s.incoming.type });
            s.incoming = null;
            setScreen(s.distress?.waiting ? 'distressWait' : 'normal');

            return;
        }

        switch (s.screen) {
            case 'menu':
                if (key === 'enter') chooseMenuItem();
                else if (key === 'clr' || key === 'menu') { if (s.menu.back) openMenu(...s.menu.back); else closeToNormal(); }
                else if (key === 'micUp') s.cursor = Math.max(0, s.cursor - 1);
                else if (key === 'micDown') s.cursor = Math.min(s.menu.items.length - 1, s.cursor + 1);

                return;
            case 'nature':
                if (key === 'enter') {
                    event('nature_selected', { nature: NATURES[s.cursor] });
                    s.nature = s.cursor;
                    s.menu = null;
                    if (! s.position) startEditor('position');
                    else setScreen('distressReady');
                } else if (key === 'clr') {
                    closeToNormal();
                }

                return;
            case 'editor':
                if (key === 'enter') editorConfirm();
                else if (key === 'clr') { s.call = null; closeToNormal(); }
                else if (key === 'ch16') editorMove(1);
                else if (key === 'dial') editorMove(-1);

                return;
            case 'mmsiCheck':
            case 'receivedList':
                if (key === 'clr' || key === 'enter') openMenu('Select Item', mainMenuItems());

                return;
            case 'callAddress':
            case 'callCategory':
            case 'callChannel':
            case 'callReady':
                if (key === 'enter') chooseCallStep();
                else if (key === 'clr') { s.call = null; closeToNormal(); }

                return;
            case 'callTx':
            case 'distressReady':
                if (key === 'clr') { s.call = null; closeToNormal(); }

                return;
            case 'distressSent':
            case 'distressWait':
                if (key === 'clr') { cancelDistressRepeat(); return; }
                break;
        }

        switch (key) {
            case 'menu':
                openMenu('Select Item', mainMenuItems());
                break;
            case 'ch16':
                if (s.channel !== 16 && s.channel !== s.callChannel[s.group]) s.workChannel = s.channel;
                setChannel(16, { work: false });
                break;
            case 'dial':
                if (s.watch !== 'off') {
                    s.watch = 'off';
                    event('watch', { mode: 'off' });
                } else {
                    setChannel(s.workChannel);
                }
                break;
            case 'hl':
            case 'micHiLo':
                if (current()?.lowPowerOnly) {
                    event('power_level_blocked', { channel: s.channel });
                } else {
                    s.highPower = ! s.highPower;
                    event('power_level', { high: s.highPower, channel: s.channel });
                }
                break;
            case 'micUp':
            case 'micDown':
                setChannel(stepChannel(s.group, s.channel, key === 'micUp' ? 1 : -1));
                break;
            case 'scan':
                event('scan', { running: true });
                break;
        }
    }

    function longPress(key) {
        if (! s.power) return;

        switch (key) {
            case 'power':
                powerOff();
                break;
            case 'ch16':
                if (s.channel !== 16 && s.channel !== s.callChannel[s.group]) s.workChannel = s.channel;
                setChannel(s.callChannel[s.group], { work: false });
                s.onCall = true;
                event('call_channel', { channel: s.callChannel[s.group] });
                break;
            case 'dial':
                s.watch = 'dual';
                event('watch', { mode: s.watch });
                break;
            case 'scan':
                event('tag', { channel: s.channel });
                break;
            case 'clr':
                if (s.screen === 'editor' && s.editor.kind !== 'mmsi') {
                    s.editor.chars = s.editor.chars.map((c) => (/\d/.test(c) ? '0' : c));
                    event('editor_cleared', { kind: s.editor.kind });
                }
                break;
        }
    }

    function combo(modifier, key) {
        if (modifier === 'hl' && key === 'dial') {
            const order = ['INT', 'ATIS', 'DSC'];
            const group = order[(order.indexOf(s.group) + 1) % order.length];
            const ch = channelInfo(group, s.channel) ? s.channel : CHANNEL_GROUPS[group][0].number;
            setChannel(ch, { group });
            event('group', { group });
        }
    }

    // --- Öffentliche Schnittstelle ------------------------------------------

    const radio = {
        get state() {
            return snapshot();
        },
        keyDown(key) {
            const modifier = Object.keys(pressed).find((k) => k === 'hl' && ! pressed[k].released);
            pressed[key] = { t0: clock(), long: false, veryLong: false, combo: false };

            if (modifier && key !== 'hl') {
                pressed[modifier].combo = true;
                pressed[key].combo = true;
                combo(modifier, key);

                return;
            }

            if (key === 'distress' && s.power) {
                if (! s.cover) {
                    event('distress_covered');

                    return;
                }
                s.distressHold = { t0: clock(), from: s.screen };
                setScreen('distressHold');
            }
        },
        keyUp(key) {
            const p = pressed[key];
            delete pressed[key];
            if (! p) return;

            if (key === 'distress') {
                if (s.screen === 'distressHold') {
                    setScreen(s.distressHold.from === 'distressReady' ? 'distressReady' : 'normal');
                    event('distress_released_early', { heldMs: clock() - p.t0 });
                }
                s.distressHold = null;

                return;
            }

            if (p.combo || p.long) return;
            shortPress(key);
        },
        turn(knob, steps) {
            if (knob === 'vol') {
                s.vol = Math.max(0, Math.min(10, s.vol + steps));
                event('volume', { level: s.vol });

                return;
            }
            if (knob === 'sql') {
                s.sql = Math.max(0, Math.min(10, s.sql + steps));
                event('squelch', { level: s.sql, state: squelchState() });

                return;
            }
            if (! s.power) return;

            if (s.screen === 'menu') {
                s.cursor = Math.max(0, Math.min(s.menu.items.length - 1, s.cursor + steps));
            } else if (s.screen === 'nature') {
                s.cursor = Math.max(0, Math.min(NATURES.length - 1, s.cursor + steps));
            } else if (s.screen === 'editor') {
                editorTurn(steps);
            } else if (s.screen === 'callAddress') {
                s.cursor = Math.max(0, Math.min(callAddressItems().length - 1, s.cursor + steps));
            } else if (s.screen === 'callCategory') {
                s.cursor = Math.max(0, Math.min(CATEGORIES.length - 1, s.cursor + steps));
            } else if (s.screen === 'callChannel') {
                s.call.channel = stepChannel('INT', s.call.channel, Math.sign(steps));
            } else if (s.screen === 'normal' || s.screen === 'distressWait') {
                const direction = Math.sign(steps);
                for (let i = 0; i < Math.abs(steps); i++) setChannel(stepChannel(s.group, s.channel, direction));
            }
        },
        pttDown() {
            if (! s.power) return false;
            if (current()?.receiveOnly) {
                event('tx_blocked', { channel: s.channel });

                return false;
            }
            s.tx = true;
            event('tx_start', { channel: s.channel, group: s.group, highPower: effectiveHighPower() });

            return true;
        },
        pttUp() {
            if (! s.tx) return;
            s.tx = false;
            event('tx_end', { channel: s.channel, group: s.group, highPower: effectiveHighPower() });
        },
        // Erkannter Text eines Funkspruchs (Spracherkennung oder Tipp-Eingabe).
        spoken(text, channel = s.channel) {
            event('spoken', { text, channel, group: s.group, highPower: effectiveHighPower() });
        },
        tick() {
            const now = clock();

            for (const [key, p] of Object.entries(pressed)) {
                const held = now - p.t0;
                if (key === 'distress') continue;
                if (! p.combo && ! p.long && held >= LONG) {
                    p.long = true;
                    longPress(key);
                }
            }

            if (s.screen === 'distressHold' && s.distressHold && now - s.distressHold.t0 >= DISTRESS_HOLD) {
                s.distressHold = null;
                sendDistress();
            }

            if (s.screen === 'distressSent' && now - s.screenSince >= AUTO_RETURN) {
                s.channel = 16;
                setScreen('distressWait');
                event('channel', { channel: 16, group: s.group, auto: true });
            }

            if (s.screen === 'callTx' && s.call && ! s.call.waiting && now - s.screenSince >= AUTO_RETURN) {
                if (s.call.channel) setChannel(s.call.channel);
                s.call = null;
                closeToNormal();
            }

            if (s.distress?.waiting && now >= s.distress.nextAt) {
                sendDistress(true);
            }
        },
        // Eingehender DSC-Anruf einer Gegenstation (Lektion oder Freies Üben).
        receive(call) {
            if (! s.power) return;
            s.received.unshift({ ...call, at: clock() });

            if (call.type === 'distress_ack' && s.distress?.waiting) {
                s.distress.waiting = false;
                s.distress.acked = true;
            }

            // „Able to comply“ auf den eigenen Einzelanruf: das Gerät stellt den
            // vereinbarten Arbeitskanal selbst ein.
            if (call.type === 'individual_ack' && s.call?.waiting) {
                const channel = s.call.channel;
                s.call = null;
                setChannel(channel);
            }

            if (['distress_ack', 'distress', 'distress_relay'].includes(call.type)) {
                s.channel = 16;
            }

            s.incoming = { ...call, silenced: false };
            setScreen('received');
            event('dsc_received', { call });
        },
        holdProgress() {
            if (s.screen !== 'distressHold' || ! s.distressHold) return null;

            return Math.min(1, (clock() - s.distressHold.t0) / DISTRESS_HOLD);
        },
        squelch: squelchState,
        // Ausgangslage einer Lektion.
        setup(start = {}) {
            if (start.power) powerOn();
            for (const key of ['vol', 'sql', 'group', 'channel', 'highPower', 'position', 'utc']) {
                if (key in start) s[key] = start[key];
            }
            if ('channel' in start) s.workChannel = start.channel;
            if ('nature' in start) s.nature = NATURES.indexOf(start.nature);
            if (start.distressWaiting) {
                s.distress = { nature: start.distressWaiting, sentAt: clock(), nextAt: clock() + DISTRESS_REPEAT, waiting: true, acked: false, count: 1 };
                setScreen('distressWait');
            }
        },
        display() {
            return renderDisplay();
        },
    };

    // --- Display -------------------------------------------------------------

    function renderDisplay() {
        if (! s.power) return { on: false };

        const ch = current();
        const base = {
            on: true,
            status: {
                busy: squelchState() === 'noise' || Boolean(s.incoming && ! s.incoming.silenced),
                tx: s.tx,
                power: effectiveHighPower() ? '25W' : '1W',
                group: s.group,
                dup: Boolean(ch?.duplex),
                call: Boolean(s.onCall),
            },
            channel: pad(s.channel),
            channelName: ch?.name ?? '',
            watch: s.watch === 'dual' ? 'DUAL 16' : s.watch === 'tri' ? 'TRI 16' : null,
            lines: [],
            panel: null,
        };
        const position = formatPosition(s.position);
        const timeLine = s.utc ? `UTC ${s.utc}` : 'No Time';
        const posLines = position ?? ['No Position'];

        switch (s.screen) {
            case 'menu':
                return { ...base, panel: { title: '--DSC Menu--', subtitle: s.menu.title, items: s.menu.items, cursor: s.cursor, footer: '<CLR→Exit / ENT→OK>' } };
            case 'nature':
                return { ...base, panel: { title: '--DSC Menu--', subtitle: 'Select Nature', items: NATURES, cursor: s.cursor, footer: '<CLR→Exit / ENT→OK>' } };
            case 'editor': {
                const e = s.editor;
                return { ...base, panel: { title: '--DSC Menu--', subtitle: e.title, editor: { chars: e.chars, cursor: e.editable[e.pos] }, footer: e.kind === 'mmsi' ? '<CLR→Exit / ENT→OK>' : '<CLR 1sec→Null Data>' } };
            }
            case 'mmsiCheck':
                return { ...base, panel: { title: '--DSC Menu--', subtitle: 'MMSI Check', lines: [own?.mmsi ?? '---------'], footer: '<CLR→Exit>' } };
            case 'receivedList':
                return { ...base, panel: { title: '--DSC Menu--', subtitle: 'Select Message', lines: s.received.length ? s.received.slice(0, 4).map((r) => `${r.type} ${r.from?.name ?? r.from?.mmsi ?? ''}`) : ['No Message'], footer: '<CLR→Exit>' } };
            case 'callAddress':
                return { ...base, panel: { title: '--DSC Menu--', subtitle: 'Select Address', items: callAddressItems(), cursor: s.cursor, footer: '<CLR→Exit / ENT→OK>' } };
            case 'callCategory':
                return { ...base, panel: { title: '--DSC Menu--', subtitle: 'Select Category', items: CATEGORIES, cursor: s.cursor, footer: '<CLR→Exit / ENT→OK>' } };
            case 'callChannel':
                return { ...base, panel: { title: '--DSC Menu--', subtitle: 'Select Traffic CH', lines: [pad(s.call.channel)], footer: '<CLR→Exit / ENT→OK>' } };
            case 'callReady':
                return { ...base, panel: { title: '--DSC Menu--', subtitle: s.call.kind === 'individual' ? 'Individual Call Ready' : 'All Ships Call Ready', lines: [], footer: '<CLR→Exit / ENT→OK>' } };
            case 'callTx':
                return { ...base, panel: { title: '--DSC Menu--', subtitle: s.call.kind === 'individual' ? 'Individual Call' : 'All Ships Call', lines: s.call.waiting ? ['TX Complete', 'Now Waiting for ACK'] : ['TX Complete'], blink: s.call.waiting, footer: '<CLR→Exit>' } };
            case 'distressReady':
                return { ...base, lines: [`Nature: ${NATURES[s.nature]}`, 'Push DISTRESS', 'for 5 sec.'] };
            case 'distressHold':
                return { ...base, panel: { title: 'Distress Call', lines: ['Push for 5 sec.'], progress: radio.holdProgress() } };
            case 'distressSent':
                return { ...base, channel: '70', channelName: 'DSC', panel: { title: 'Distress Call', lines: ['TX Complete', 'Now Waiting for ACK'], blink: true, footer: '<CLR→Cancel ACK>' } };
            case 'distressWait':
                return { ...base, lines: ['Wait ACK', '<CLR→', 'Cancel ACK>'] };
            case 'received': {
                const i = s.incoming;
                const titles = { individual_ack: 'INDV ACK', distress_ack: 'DistressACK', distress: 'Distress', distress_relay: 'DistressRLY', individual: 'Individual', all_ships: 'All Ships', geographic: 'Geographic', group: 'Group' };
                return { ...base, lines: ['Received', titles[i.type] ?? i.type, `<${i.from?.name ?? i.from?.mmsi ?? ''}`, i.silenced ? '' : '<CLR→ Beep Off>'], alarm: ! i.silenced };
            }
            default:
                return { ...base, lines: [...posLines, timeLine] };
        }
    }

    return radio;
}

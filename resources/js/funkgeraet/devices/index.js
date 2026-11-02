// Geräteregister der Gerätesimulation: Logik, Zeichnung und Schritt-Hilfen
// je Gerät. Lektionen sind geräteunabhängig; prepareLesson() wendet die
// Anpassungen eines Geräts an (Schritt-Feld "device": {<gerät>: {...}}) und
// ersetzt Hilfetext und Markierung durch die Hilfen des Geräts.

import { createIcM505 } from './icm505.js';
import { createIcM323, createIcM423 } from './icm323.js';
import { hintM323, hintM423 } from './icm323-hints.js';
import { createIcM503 } from './icm503.js';
import { hintM503 } from './icm503-hints.js';
import { createIcM330 } from './icm330.js';
import { hintM330 } from './icm330-hints.js';
import { renderDevice } from '../ui/device-svg.js';
import { renderDeviceM323, renderDeviceM423 } from '../ui/device-svg-m323.js';
import { renderDeviceM503 } from '../ui/device-svg-m503.js';
import { renderDeviceM330 } from '../ui/device-svg-m330.js';

// Bedienhilfe je Gerät ("Hilfe zur Bedienung" in der Seitenleiste).
const LATCH_HELP = 'Mit der Maus geht das so: die erste Taste mit gedrückter Umschalttaste anklicken (sie bleibt gedrückt), dann die zweite Taste.';
const CONTROLS = {
    'ic-m505': [
        'Tasten anklicken oder antippen; DISTRESS (5 Sekunden), POWER (Aus), DIAL (Wache) und SCAN (TAG) gedrückt halten.',
        'Drehknöpfe mit dem Mausrad, durch Ziehen nach oben/unten oder mit den Pfeiltasten; den großen Knopf drücken = ENTER.',
        `H/L gedrückt halten und DIAL drücken: Kanalgruppe wechseln (INT, ATIS für den Binnenfunk, DSC). 16/C gedrückt halten und POWER drücken: Set-Modus. ${LATCH_HELP}`,
    ],
    'ic-m323': [
        'Drehknopf VOL/SQL • PWR: 1 Sekunde halten = Ein/Aus, kurz drücken = Lautstärke, Rauschsperre, Kanalwahl; drehen mit dem Mausrad, durch Ziehen oder mit den Pfeiltasten.',
        'Softkeys unter dem Display; mit ◀/▶ die Leiste weiterblättern. MENU öffnet die Menüs, ENT bestätigt, CLEAR geht zurück.',
        '16/C kurz = Kanal 16, 1 Sekunde = Anrufkanal. DISTRESS unter der Klappe 3 Sekunden halten.',
    ],
    'ic-m503': [
        'Am Funkgerät: POWER, HI/LO, DUAL, SCAN, CALL und 16; Kanal mit dem Knopf CHANNEL (Mausrad, Ziehen oder Pfeiltasten).',
        `HI/LO gedrückt halten und DIAL drücken: Kanalgruppe. 16 gedrückt halten und POWER drücken: Set-Modus. ${LATCH_HELP}`,
        'DSC am DS-100: CALL öffnet das Menü, ▲▼ wählen, ENT bestätigt, CLR geht zurück; gesendet wird mit CALL und dann ENT. DISTRESS unter der Klappe 5 Sekunden halten.',
    ],
    'ic-m330': [
        'Drehknopf PWR/VOL/SQL: 1 Sekunde halten = Ein/Aus, einmal drücken = Lautstärke, zweimal = Rauschsperre; drehen mit dem Mausrad, durch Ziehen oder mit den Pfeiltasten.',
        'Softkeys auf Seiten: mit ◀/▶ blättern (Seite 1 DISTRESS und OTHER DSC, Seite 2 SCAN, DW, CHAN, HI/LO, Seite 3 ★ …).',
        'MENU öffnet das Menü; ▲▼ wählen, ENT oder ▶ weiter, CLR oder ◀ zurück. 16/C kurz = Kanal 16, 1 Sekunde = Anrufkanal. DISTRESS unter der Klappe 3 Sekunden halten.',
    ],
};
CONTROLS['ic-m423'] = CONTROLS['ic-m323'];

// latch: Tasten, die man für eine Zweitasten-Bedienung gedrückt hält; mit
// der Maus rasten sie per Umschalttaste + Klick ein.
export const DEVICES = {
    'ic-m505': { key: 'ic-m505', name: 'Icom IC-M505', create: createIcM505, render: renderDevice, hint: null, wheel: { key: 'enter', knob: 'channel' }, latch: ['hl', 'ch16'], controls: CONTROLS['ic-m505'] },
    'ic-m323': { key: 'ic-m323', name: 'Icom IC-M323', create: createIcM323, render: renderDeviceM323, hint: hintM323, wheel: { key: 'knob', knob: 'dial' }, controls: CONTROLS['ic-m323'] },
    // "like": Lektions-Anpassungen, Ausblendungen und Ersatzaufgaben gelten
    // wie für dieses Gerät, solange keine eigenen angegeben sind.
    'ic-m423': { key: 'ic-m423', name: 'Icom IC-M423', like: 'ic-m323', create: createIcM423, render: renderDeviceM423, hint: hintM423, wheel: { key: 'knob', knob: 'dial' }, controls: CONTROLS['ic-m423'] },
    'ic-m503': { key: 'ic-m503', name: 'Icom IC-M503 + DS-100', create: createIcM503, render: renderDeviceM503, hint: hintM503, wheel: { key: null, knob: 'channel' }, latch: ['hl', 'ch16'], controls: CONTROLS['ic-m503'] },
    // DSC Klasse D wie das IC-M323 (kein Senden von Positions-/Polling-
    // Abfragen, dafür Testanruf); Bedienung über Softkey-Seiten und das
    // Formular OTHER DSC.
    'ic-m330': { key: 'ic-m330', name: 'Icom IC-M330', like: 'ic-m323', create: createIcM330, render: renderDeviceM330, hint: hintM330, wheel: { key: 'knob', knob: 'dial' }, controls: CONTROLS['ic-m330'] },
};

const likeOf = (deviceKey) => DEVICES[deviceKey]?.like ?? null;

export function lessonAvailable(lesson, deviceKey) {
    return ! lesson.devices || lesson.devices.includes(deviceKey) || lesson.devices.includes(likeOf(deviceKey));
}

export function prepareLesson(lesson, deviceKey) {
    const device = DEVICES[deviceKey];

    return {
        ...lesson,
        steps: lesson.steps.map((step) => {
            const override = step.device?.[deviceKey] ?? step.device?.[likeOf(deviceKey)] ?? {};
            const merged = { ...step, ...override };
            if (! device?.hint || 'help' in override) return merged;
            // Funksprüche behalten ihren Wortlaut als Hilfe.
            if (merged.speech) return { ...merged, target: 'ptt' };
            const hint = device.hint(merged);

            return { ...merged, help: hint.help, target: hint.target };
        }),
    };
}

// Lektionen einer Prüfungsreihe für ein Gerät (Ersatz für Aufgaben, die das
// Gerät nicht kann).
export function examLessons(exam, deviceKey) {
    const substitutes = exam.substitutes?.[deviceKey] ?? exam.substitutes?.[likeOf(deviceKey)] ?? {};

    return exam.lessons.map((key) => substitutes[key] ?? key);
}

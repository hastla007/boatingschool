// Geräteregister der Gerätesimulation: Logik, Zeichnung und Schritt-Hilfen
// je Gerät. Lektionen sind geräteunabhängig; prepareLesson() wendet die
// Anpassungen eines Geräts an (Schritt-Feld "device": {<gerät>: {...}}) und
// ersetzt Hilfetext und Markierung durch die Hilfen des Geräts.

import { createIcM505 } from './icm505.js';
import { createIcM323 } from './icm323.js';
import { hintM323 } from './icm323-hints.js';
import { renderDevice } from '../ui/device-svg.js';
import { renderDeviceM323 } from '../ui/device-svg-m323.js';

export const DEVICES = {
    'ic-m505': { key: 'ic-m505', name: 'Icom IC-M505', create: createIcM505, render: renderDevice, hint: null, wheel: { key: 'enter', knob: 'channel' } },
    'ic-m323': { key: 'ic-m323', name: 'Icom IC-M323', create: createIcM323, render: renderDeviceM323, hint: hintM323, wheel: { key: 'knob', knob: 'dial' } },
};

export function lessonAvailable(lesson, deviceKey) {
    return ! lesson.devices || lesson.devices.includes(deviceKey);
}

export function prepareLesson(lesson, deviceKey) {
    const device = DEVICES[deviceKey];

    return {
        ...lesson,
        steps: lesson.steps.map((step) => {
            const override = step.device?.[deviceKey] ?? {};
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
    return exam.lessons.map((key) => exam.substitutes?.[deviceKey]?.[key] ?? key);
}

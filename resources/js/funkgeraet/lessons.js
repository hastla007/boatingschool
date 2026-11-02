// Lektionssteuerung der Gerätesimulation: vergleicht die Ereignisse der
// Gerätelogik mit den Zielen der Lektionsschritte (database/data/
// geraetesimulation.php). Ein Ziel nennt Ereignistyp und Felder; Felder mit
// Punkt prüfen verschachtelte Werte ("call.type"), {min, max} prüfen
// Bereiche, Arrays werden elementweise verglichen. Gesprochenes ("spoken")
// prüft der Server; die Lektion wartet dann auf dessen Ergebnis.

// Stufe 1 mit gelbem Rahmen und Schritt-Hilfe, Stufe 2 nur mit Hinweistext,
// Stufe 3 allein. „Selbst geschafft“ zählt nur Stufe 3.
export const LEVELS = [1, 2, 3];

function valueAt(object, path) {
    return path.split('.').reduce((value, key) => (value == null ? undefined : value[key]), object);
}

function same(expected, actual) {
    if (Array.isArray(expected)) {
        return Array.isArray(actual) && expected.length === actual.length && expected.every((v, i) => same(v, actual[i]));
    }
    if (expected && typeof expected === 'object') {
        if ('min' in expected || 'max' in expected) {
            return typeof actual === 'number' && (expected.min === undefined || actual >= expected.min) && (expected.max === undefined || actual <= expected.max);
        }

        return Object.entries(expected).every(([key, value]) => same(value, actual?.[key]));
    }

    return expected === actual;
}

export function matchesGoal(goal, event) {
    return Object.entries(goal).every(([key, expected]) => same(expected, valueAt(event, key)));
}

export function createLessonRun(lesson, { level = 1, schedule = (fn, ms) => setTimeout(fn, ms), receive = () => {} } = {}) {
    let index = 0;
    let done = false;
    let pendingSpeech = null;
    const listeners = new Set();

    const notify = () => listeners.forEach((fn) => fn(run.status()));

    function enterStep() {
        const step = lesson.steps[index];
        if (step?.receive) {
            schedule(() => receive(step.receive.call), step.receive.delay_ms ?? 0);
        }
    }

    function advance() {
        index++;
        if (index >= lesson.steps.length) {
            done = true;
        } else {
            enterStep();
        }
        notify();
    }

    const run = {
        lesson,
        level,
        get step() {
            return done ? null : lesson.steps[index];
        },
        status() {
            const step = done ? null : lesson.steps[index];

            return {
                index,
                total: lesson.steps.length,
                done,
                text: step?.text ?? null,
                // Stufe 1: Hinweis und Rahmen; Stufe 2: nur Hinweis; Stufe 3: nichts.
                help: step && level <= 2 ? step.help : null,
                target: step && level === 1 ? step.target : null,
                awaitingSpeech: Boolean(pendingSpeech),
            };
        },
        onChange(fn) {
            listeners.add(fn);

            return () => listeners.delete(fn);
        },
        // Ereignis der Gerätelogik. Gibt den Schritt zurück, wenn ein
        // gesprochener Funkspruch vom Server bewertet werden muss.
        handle(event) {
            if (done) return null;
            const step = lesson.steps[index];

            if (! matchesGoal(step.goal, event)) return null;

            if (step.speech) {
                pendingSpeech = { index, text: event.text };

                return { stepIndex: index, text: event.text };
            }

            advance();

            return null;
        },
        // Ergebnis der Serverbewertung eines Funkspruchs.
        speechResult(stepIndex, passed) {
            if (! pendingSpeech || pendingSpeech.index !== stepIndex) return;
            pendingSpeech = null;
            if (passed) advance();
            else notify();
        },
        start() {
            enterStep();
            notify();
        },
    };

    return run;
}

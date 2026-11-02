// Kanaltabellen der Gerätesimulation. INT nach der Kanalliste der
// Icom-Anleitungen (internationale Kanäle 01–28, 60–88), ATIS nach dem
// Kanalplan Binnenschifffahrtsfunk (RAINWAT; siehe Konzept). Frequenzen
// werden nicht gebraucht, nur was das Gerät anzeigt und erzwingt.

const DUPLEX_INT = new Set([1, 2, 3, 4, 5, 7, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 60, 61, 62, 63, 64, 65, 66, 78, 79, 80, 81, 82, 83, 84, 85, 86]);

const INT_NAMES = {
    6: 'SAFETY', 8: 'COMMERCIAL', 9: 'CALLING', 10: 'COMMERCIAL', 13: 'BRIDGE COM', 15: 'ON BOARD',
    16: 'DISTRESS', 17: 'ON BOARD', 67: 'SAR', 69: 'PLEASURE', 70: 'DSC', 72: 'SHIP-SHIP', 73: 'SAFETY', 77: 'SHIP-SHIP',
};

function intChannels() {
    const list = [];
    for (let ch = 1; ch <= 28; ch++) list.push(ch);
    for (let ch = 60; ch <= 88; ch++) list.push(ch);

    return list.map((ch) => ({
        number: ch,
        label: String(ch).padStart(2, '0'),
        duplex: DUPLEX_INT.has(ch),
        // 15/17 Bordverkehr und 75/76 navigationsbezogen: höchstens 1 W.
        lowPowerOnly: [15, 17, 75, 76].includes(ch),
        // Kanal 70 ist dem DSC vorbehalten, Sprechfunk ist dort nicht möglich.
        receiveOnly: ch === 70,
        name: INT_NAMES[ch] ?? (DUPLEX_INT.has(ch) ? 'PORT OPR' : 'INTL'),
    }));
}

const ATIS_PLAN = [
    [8, 'Schiff-Schiff'], [10, 'Schiff-Schiff'], [11, 'Schiff-Hafen'], [12, 'Schiff-Hafen'], [13, 'Schiff-Hafen'],
    [14, 'Schiff-Hafen'], [15, 'An Bord'], [17, 'An Bord'], [18, 'Nautische Information'], [19, 'Nautische Information'],
    [20, 'Nautische Information'], [22, 'Nautische Information'], [23, 'Nautische Information'], [24, 'Nautische Information'],
    [25, 'Nautische Information'], [26, 'Nautische Information'], [27, 'Nautische Information'], [28, 'Nautische Information'],
    [71, 'Schiff-Hafen'], [72, 'Schiff-Schiff'], [73, 'Nautische Information'], [77, 'Schiff-Schiff'], [78, 'Nautische Information'],
    [80, 'Nautische Information'], [82, 'Nautische Information'], [84, 'Nautische Information'],
];

function atisChannels() {
    return ATIS_PLAN.slice().sort((a, b) => a[0] - b[0]).map(([ch, circle]) => ({
        number: ch,
        label: String(ch).padStart(2, '0'),
        duplex: circle === 'Nautische Information' && ch >= 18 && ch !== 73,
        // Im Binnenfunk sind 25 W nur in der Nautischen Information zulässig.
        lowPowerOnly: circle !== 'Nautische Information',
        receiveOnly: false,
        name: circle === 'Nautische Information' ? 'NAUT INFO' : circle === 'Schiff-Hafen' ? 'SHIP-PORT' : circle === 'An Bord' ? 'ON BOARD' : 'SHIP-SHIP',
        circle,
    }));
}

export const CHANNEL_GROUPS = {
    INT: intChannels(),
    // Die DSC-Gruppe deutscher Geräte entspricht für den Sprechfunk den
    // internationalen Kanälen.
    DSC: intChannels(),
    ATIS: atisChannels(),
};

export function channelInfo(group, number) {
    return CHANNEL_GROUPS[group].find((c) => c.number === number) ?? null;
}

export function stepChannel(group, number, direction) {
    const list = CHANNEL_GROUPS[group];
    const index = list.findIndex((c) => c.number === number);
    const next = (index + direction + list.length) % list.length;

    return list[index === -1 ? 0 : next].number;
}

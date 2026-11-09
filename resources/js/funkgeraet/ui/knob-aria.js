// Screenreader-Werte der Drehknöpfe (role="slider"): ohne aria-valuenow &
// Co. sagt ein Screenreader nur „Schieberegler, Lautstärke VOL“, aber nicht,
// wie laut es ist oder welcher Kanal eingestellt ist (WCAG 2.2, 4.1.2).
import { CHANNEL_GROUPS } from '../channels.js';

// Setzt aria-valuenow/-min/-max/-valuetext eines Drehknopfs (role="slider").
export function setKnobAria(node, { now, min, max, text }) {
    if (! node) return;
    const attrs = { 'aria-valuenow': now, 'aria-valuemin': min, 'aria-valuemax': max, 'aria-valuetext': text };
    Object.entries(attrs).forEach(([name, value]) => {
        if (node.getAttribute(name) !== String(value)) node.setAttribute(name, String(value));
    });
}

const off = (state) => (state.power ? '' : ', Gerät aus');

export const volAria = (state, max) => ({ now: state.vol, min: 0, max, text: `Lautstärke ${state.vol} von ${max}${off(state)}` });
export const sqlAria = (state) => ({ now: state.sql, min: 0, max: 10, text: `Rauschsperre ${state.sql} von 10${off(state)}` });

export function channelAria(state) {
    const numbers = (CHANNEL_GROUPS[state.group] ?? CHANNEL_GROUPS.INT).map((c) => c.number);
    const number = String(state.channel).padStart(2, '0');
    const group = state.group && state.group !== 'INT' ? ` (${state.group})` : '';

    return { now: state.channel, min: Math.min(...numbers), max: Math.max(...numbers), text: `Kanal ${number}${group}${off(state)}` };
}

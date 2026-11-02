// Eigene Zeichnung des IC-M323 (SVG), angelehnt an die Frontansicht der
// Bedienungsanleitung: Lautsprecher und DISTRESS unter der Klappe links,
// großes Display mit Softkey-Leiste, vier Softkeys darunter, rechts Wippe
// ▲▼◀▶ mit ENT, MENU, CLEAR, 16/C und der Drehknopf VOL/SQL·PWR. Keine
// Fotos oder Herstellergrafiken. Gleiche Schnittstelle wie device-svg.js;
// ein Ziel "sk:LABEL" markiert den Softkey mit dieser Beschriftung.

const NS = 'http://www.w3.org/2000/svg';
const BODY = '#26292e';
const KEY = '#3a3e45';
const KEY_TEXT = '#f2f4f7';
const LCD = '#c3cdb0';
const LCD_INK = '#1e2717';
const MONO = 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';

function el(name, attrs = {}, parent = null, text = null) {
    const node = document.createElementNS(NS, name);
    for (const [key, value] of Object.entries(attrs)) node.setAttribute(key, value);
    if (text !== null) node.textContent = text;
    if (parent) parent.appendChild(node);

    return node;
}

function key(svg, { id, x, y, w = 70, h = 40, label, round = false, color = KEY, size = 17, ariaLabel = null }) {
    const g = el('g', { 'data-key': id, tabindex: '0', role: 'button', 'aria-label': ariaLabel ?? label, class: 'fg-control', style: 'cursor:pointer' }, svg);
    if (round) el('circle', { cx: x, cy: y, r: w / 2, fill: color, stroke: '#0f1114', 'stroke-width': 3 }, g);
    else el('rect', { x, y, width: w, height: h, rx: 9, fill: color, stroke: '#0f1114', 'stroke-width': 3 }, g);
    el('text', { x: round ? x : x + w / 2, y: (round ? y : y + h / 2) + 6, 'text-anchor': 'middle', 'font-size': size, 'font-weight': 700, fill: KEY_TEXT, 'font-family': 'Arial, sans-serif', 'pointer-events': 'none' }, g, label);

    return g;
}

export function renderDeviceM323(container) {
    const svg = el('svg', { viewBox: '0 0 1000 560', role: 'group', 'aria-label': 'Funkgerät IC-M323 (Nachbildung)', style: 'width:100%;height:auto;user-select:none;-webkit-user-select:none' });
    container.appendChild(svg);

    // Hörer
    el('path', { d: 'M110 470 C 110 520, 190 530, 240 500', fill: 'none', stroke: '#15171a', 'stroke-width': 10 }, svg);
    el('path', { d: 'M120 200 Q 150 165 185 165 Q 220 165 250 200 L 262 505 Q 185 545 105 505 Z', fill: '#2c2f34', stroke: '#15171a', 'stroke-width': 3 }, svg);
    for (let i = 0; i < 6; i++) el('rect', { x: 140, y: 215 + i * 20, width: 96, height: 7, rx: 3, fill: '#16181b' }, svg);
    const ptt = el('g', { 'data-key': 'ptt', tabindex: '0', role: 'button', 'aria-label': 'Sprechtaste (PTT)', class: 'fg-control', style: 'cursor:pointer' }, svg);
    el('rect', { x: 88, y: 270, width: 24, height: 110, rx: 9, fill: '#1b1d21', stroke: '#0f1114', 'stroke-width': 3 }, ptt);
    el('text', { x: 100, y: 262, 'text-anchor': 'middle', 'font-size': 14, 'font-weight': 700, fill: '#333', 'font-family': 'Arial, sans-serif' }, svg, 'PTT');
    key(svg, { id: 'micUp', x: 132, y: 380, w: 38, h: 34, label: '▲', size: 16 });
    key(svg, { id: 'micDown', x: 132, y: 420, w: 38, h: 34, label: '▼', size: 16 });
    key(svg, { id: 'micHiLo', x: 182, y: 398, w: 48, h: 36, label: 'H/L', size: 15, ariaLabel: 'HI/LO am Hörer' });

    // Gehäuse
    el('rect', { x: 270, y: 60, width: 710, height: 440, rx: 34, fill: BODY, stroke: '#101215', 'stroke-width': 4 }, svg);
    el('text', { x: 615, y: 92, 'text-anchor': 'middle', 'font-size': 18, 'font-weight': 700, fill: '#d6dae0', 'letter-spacing': 2, 'font-family': 'Arial, sans-serif' }, svg, 'VHF MARINE TRANSCEIVER  IC-M323');
    for (let i = 0; i < 9; i++) el('rect', { x: 296, y: 120 + i * 18, width: 70, height: 7, rx: 3, fill: '#16181b' }, svg);

    // DISTRESS mit Klappe
    const distress = el('g', { 'data-key': 'distress', tabindex: '0', role: 'button', 'aria-label': 'DISTRESS (3 Sekunden halten)', class: 'fg-control', style: 'cursor:pointer' }, svg);
    el('rect', { x: 296, y: 310, width: 84, height: 70, rx: 10, fill: '#c62828', stroke: '#6d0f0f', 'stroke-width': 3 }, distress);
    el('text', { x: 338, y: 352, 'text-anchor': 'middle', 'font-size': 14, 'font-weight': 700, fill: '#fff', 'font-family': 'Arial, sans-serif', 'pointer-events': 'none' }, distress, 'DISTRESS');
    const cover = el('g', { 'data-key': 'distressCover', tabindex: '0', role: 'button', 'aria-label': 'Schutzklappe der DISTRESS-Taste', class: 'fg-control', style: 'cursor:pointer' }, svg);
    const coverLid = el('rect', { x: 290, y: 302, width: 96, height: 86, rx: 12, fill: 'rgba(229,57,53,0.55)', stroke: '#8e1b1b', 'stroke-width': 3 }, cover);

    // Display
    el('rect', { x: 400, y: 108, width: 410, height: 250, rx: 12, fill: '#15171a' }, svg);
    el('rect', { x: 412, y: 118, width: 386, height: 230, rx: 6, fill: LCD }, svg);
    const lcd = el('g', { 'font-family': MONO, fill: LCD_INK }, svg);

    // Softkeys
    const softkeys = [0, 1, 2, 3].map((i) => key(svg, { id: `soft${i + 1}`, x: 416 + i * 97, y: 372, w: 86, h: 34, label: '', size: 13, ariaLabel: `Softkey ${i + 1}` }));
    const softTexts = softkeys.map((g) => g.querySelector('text'));

    // Wippe, MENU, CLEAR
    key(svg, { id: 'up', x: 862, y: 112, w: 64, h: 40, label: '▲', ariaLabel: 'Kanal auf / ▲' });
    key(svg, { id: 'down', x: 862, y: 218, w: 64, h: 40, label: '▼', ariaLabel: 'Kanal ab / ▼' });
    key(svg, { id: 'left', x: 820, y: 164, w: 40, h: 46, label: '◀', ariaLabel: 'links / Softkeys blättern' });
    key(svg, { id: 'right', x: 928, y: 164, w: 40, h: 46, label: '▶', ariaLabel: 'rechts / Softkeys blättern' });
    key(svg, { id: 'enter', x: 864, y: 162, w: 60, h: 50, label: 'ENT', size: 15 });
    key(svg, { id: 'menu', x: 830, y: 284, w: 64, h: 38, label: 'MENU', size: 15 });
    key(svg, { id: 'clr', x: 902, y: 284, w: 64, h: 38, label: 'CLEAR', size: 13 });
    key(svg, { id: 'ch16', x: 760, y: 440, w: 64, round: true, label: '16/C', size: 18, color: '#1d5fbf' });

    // Drehknopf VOL/SQL·PWR: Ring drehen, Kappe drücken
    const ring = el('g', { 'data-knob': 'dial', tabindex: '0', role: 'slider', 'aria-label': 'Drehknopf VOL/SQL', class: 'fg-control', style: 'cursor:ns-resize;touch-action:none' }, svg);
    el('circle', { cx: 890, cy: 410, r: 54, fill: '#1b1d21', stroke: '#0f1114', 'stroke-width': 3 }, ring);
    const pointer = el('line', { x1: 890, y1: 410, x2: 890, y2: 362, stroke: '#f2f4f7', 'stroke-width': 4, 'stroke-linecap': 'round' }, ring);
    const cap = el('g', { 'data-key': 'knob', tabindex: '0', role: 'button', 'aria-label': 'Drehknopf drücken (kurz: Lautstärke/Rauschsperre/Kanal, 1 Sekunde: Ein/Aus)', class: 'fg-control', style: 'cursor:pointer' }, svg);
    el('circle', { cx: 890, cy: 410, r: 24, fill: '#2a2d32', stroke: '#0f1114', 'stroke-width': 2 }, cap);
    el('text', { x: 890, y: 486, 'text-anchor': 'middle', 'font-size': 14, 'font-weight': 700, fill: KEY_TEXT, 'font-family': 'Arial, sans-serif' }, svg, 'VOL/SQL • PWR');

    const highlight = el('rect', { fill: 'none', stroke: '#e3b505', 'stroke-width': 4, 'stroke-dasharray': '10 6', rx: 14, visibility: 'hidden', 'pointer-events': 'none' }, svg);

    function text(x, y, value, attrs = {}) {
        el('text', { x, y, 'font-size': 16, ...attrs }, lcd, value);
    }

    function drawDisplay(d) {
        lcd.replaceChildren();
        softTexts.forEach((t, i) => { t.textContent = d.softkeys?.[i] ?? ''; });
        if (! d.on) return;
        const st = d.status;
        text(422, 138, [st.tx ? 'TX' : st.busy ? 'BUSY' : '', st.power, st.group, st.dup ? 'DUP' : '', st.tag ? '★' : ''].filter(Boolean).join('  '), { 'font-size': 15 });
        if (st.call) text(640, 156, 'CALL', { 'font-size': 14 });
        // Softkey-Leiste unten im Display
        el('rect', { x: 412, y: 322, width: 386, height: 26, fill: LCD_INK }, lcd);
        (d.softkeys ?? []).forEach((label, i) => text(463 + i * 97, 340, label, { 'text-anchor': 'middle', 'font-size': 13, fill: LCD, 'font-weight': 700 }));
        if (d.arrows) { text(418, 340, '◀', { fill: LCD, 'font-size': 12 }); text(786, 340, '▶', { fill: LCD, 'font-size': 12 }); }

        if (d.panel) {
            const p = d.panel;
            if (p.title) {
                el('rect', { x: 412, y: 146, width: 386, height: 22, fill: LCD_INK }, lcd);
                text(605, 162, `≡ ${p.title} ≡`, { 'text-anchor': 'middle', 'font-size': 14, fill: LCD, 'font-weight': 700 });
            }
            let y = 188;
            if (p.items) {
                const first = Math.max(0, Math.min(p.cursor - 2, p.items.length - 5));
                p.items.slice(first, first + 5).forEach((item, i) => {
                    const selected = first + i === p.cursor;
                    if (selected) el('rect', { x: 416, y: y - 15, width: 378, height: 20, fill: LCD_INK }, lcd);
                    text(422, y, item, { 'font-size': 15, fill: selected ? LCD : LCD_INK });
                    y += 22;
                });
            }
            (p.lines ?? []).forEach((line) => {
                text(605, y, line, { 'text-anchor': 'middle', 'font-size': 16, class: p.blink ? 'fg-blink' : '' });
                y += 22;
            });
            if (p.editor) {
                p.editor.chars.forEach((c, i) => {
                    const x = 424 + i * 15;
                    text(x, y + 4, c, { 'font-size': 18 });
                    if (i === p.editor.cursor) el('rect', { x: x - 1, y: y + 8, width: 13, height: 3, fill: LCD_INK }, lcd);
                });
                if (p.footer) text(605, y + 34, p.footer, { 'text-anchor': 'middle', 'font-size': 13 });
            }
            if (typeof p.progress === 'number') {
                el('rect', { x: 450, y: 268, width: 310, height: 14, fill: 'none', stroke: LCD_INK, 'stroke-width': 2 }, lcd);
                el('rect', { x: 452, y: 270, width: 306 * p.progress, height: 10, fill: LCD_INK }, lcd);
            }

            return;
        }

        if (d.watch) text(422, 176, d.watch, { 'font-size': 15 });
        d.lines.forEach((line, i) => text(422, 222 + i * 24, line, { 'font-size': 16 }));
        el('text', { x: 792, y: 268, 'text-anchor': 'end', 'font-size': 104, 'font-weight': 700, 'font-family': 'Arial, sans-serif' }, lcd, d.channel);
        text(792, 304, d.channelName, { 'text-anchor': 'end', 'font-size': 14 });
        if (d.window) {
            el('rect', { x: 440, y: 190, width: 330, height: 70, rx: 6, fill: LCD, stroke: LCD_INK, 'stroke-width': 2 }, lcd);
            text(456, 218, `${d.window.label} ${d.window.value}`, { 'font-size': 18, 'font-weight': 700 });
            if (d.window.level !== null) {
                el('rect', { x: 456, y: 232, width: 296, height: 12, fill: 'none', stroke: LCD_INK, 'stroke-width': 2 }, lcd);
                el('rect', { x: 458, y: 234, width: 292 * d.window.level, height: 8, fill: LCD_INK }, lcd);
            }
        }
    }

    return {
        svg,
        update(display, radioState, target) {
            drawDisplay(display);
            const level = radioState.knobMode === 'sql' ? radioState.sql / 10 : radioState.vol / 20;
            pointer.setAttribute('transform', `rotate(${-135 + level * 270} 890 410)`);
            if (radioState.coverOpen) Object.entries({ y: 250, height: 30 }).forEach(([k, v]) => coverLid.setAttribute(k, v));
            else Object.entries({ y: 302, height: 86 }).forEach(([k, v]) => coverLid.setAttribute(k, v));

            let node = null;
            if (target?.startsWith('sk:')) {
                const index = (display.softkeys ?? []).indexOf(target.slice(3));
                node = index >= 0 ? softkeys[index] : svg.querySelector('[data-key="right"]');
            } else if (target) {
                node = svg.querySelector(`[data-key="${target}"], [data-knob="${target}"]`);
            }
            if (node) {
                const box = node.getBBox();
                highlight.setAttribute('x', box.x - 8);
                highlight.setAttribute('y', box.y - 8);
                highlight.setAttribute('width', box.width + 16);
                highlight.setAttribute('height', box.height + 16);
                highlight.setAttribute('visibility', 'visible');
            } else {
                highlight.setAttribute('visibility', 'hidden');
            }
        },
        rotateChannel() {},
    };
}

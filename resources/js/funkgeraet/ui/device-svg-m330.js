// Eigene Zeichnung des IC-M330 (SVG), angelehnt an die Frontansicht der
// Bedienungsanleitung: links Lautsprecher, unten links DISTRESS unter der
// Klappe, Display mit Softkey-Leiste (Seitenmarken ◂ ▸, breite Felder
// DISTRESS und OTHER DSC), vier Softkeys darunter, rechts Tastenkreuz
// ▲▼◄► mit ENT, darunter MENU und CLR, unten rechts 16/C und der
// Drehknopf PWR/VOL/SQL. Keine Fotos oder Herstellergrafiken. Gleiche
// Schnittstelle wie device-svg.js; ein Ziel "sk:LABEL" markiert den Softkey
// mit dieser Beschriftung bzw. die Blättertaste zur richtigen Seite.

const NS = 'http://www.w3.org/2000/svg';
const BODY = '#24272c';
const KEY = '#3a3e45';
const KEY_TEXT = '#f2f4f7';
const LCD = '#e6c58a';
const LCD_INK = '#2a1d0b';
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

// Benachbarte gleiche Beschriftungen bilden ein breites Feld.
function fields(labels) {
    const out = [];
    labels.forEach((label, i) => {
        const last = out.at(-1);
        if (last && label && last.label === label) last.span++;
        else out.push({ label, start: i, span: 1 });
    });

    return out;
}

export function renderDeviceM330(container) {
    const svg = el('svg', { viewBox: '0 0 1000 560', role: 'group', 'aria-label': 'Funkgerät IC-M330 (Nachbildung)', style: 'width:100%;height:auto;user-select:none;-webkit-user-select:none' });
    container.appendChild(svg);

    // Hörer
    el('path', { d: 'M110 470 C 110 520, 190 530, 240 500', fill: 'none', stroke: '#15171a', 'stroke-width': 10 }, svg);
    el('path', { d: 'M120 200 Q 150 165 185 165 Q 220 165 250 200 L 262 505 Q 185 545 105 505 Z', fill: '#2c2f34', stroke: '#15171a', 'stroke-width': 3 }, svg);
    for (let i = 0; i < 6; i++) el('rect', { x: 140, y: 215 + i * 20, width: 96, height: 7, rx: 3, fill: '#16181b' }, svg);
    const ptt = el('g', { 'data-key': 'ptt', tabindex: '0', role: 'button', 'aria-label': 'Sprechtaste (PTT)', class: 'fg-control', style: 'cursor:pointer' }, svg);
    el('rect', { x: 88, y: 270, width: 24, height: 110, rx: 9, fill: '#1b1d21', stroke: '#0f1114', 'stroke-width': 3 }, ptt);
    el('text', { x: 100, y: 262, 'text-anchor': 'middle', 'font-size': 14, 'font-weight': 700, fill: '#333', 'font-family': 'Arial, sans-serif' }, svg, 'PTT');
    key(svg, { id: 'micHiLo', x: 124, y: 400, w: 44, h: 34, label: 'H/L', size: 13, ariaLabel: 'HI/LO am Hörer' });
    key(svg, { id: 'micUp', x: 174, y: 380, w: 36, h: 32, label: '▲', size: 15, ariaLabel: '▲ am Hörer' });
    key(svg, { id: 'micDown', x: 174, y: 418, w: 36, h: 32, label: '▼', size: 15, ariaLabel: '▼ am Hörer' });
    key(svg, { id: 'mic16', x: 214, y: 400, w: 40, h: 34, label: '16/C', size: 11, color: '#1d5fbf', ariaLabel: '16/C am Hörer' });

    // Gehäuse
    el('rect', { x: 270, y: 60, width: 710, height: 440, rx: 34, fill: BODY, stroke: '#101215', 'stroke-width': 4 }, svg);
    el('text', { x: 615, y: 92, 'text-anchor': 'middle', 'font-size': 18, 'font-weight': 700, fill: '#d6dae0', 'letter-spacing': 2, 'font-family': 'Arial, sans-serif' }, svg, 'VHF MARINE TRANSCEIVER  IC-M330');
    for (let i = 0; i < 10; i++) el('rect', { x: 296, y: 116 + i * 16, width: 80, height: 6, rx: 3, fill: '#16181b' }, svg);

    // DISTRESS mit Klappe (unten links)
    const distress = el('g', { 'data-key': 'distress', tabindex: '0', role: 'button', 'aria-label': 'DISTRESS (3 Sekunden halten)', class: 'fg-control', style: 'cursor:pointer' }, svg);
    el('rect', { x: 296, y: 330, width: 84, height: 66, rx: 10, fill: '#c62828', stroke: '#6d0f0f', 'stroke-width': 3 }, distress);
    el('text', { x: 338, y: 369, 'text-anchor': 'middle', 'font-size': 14, 'font-weight': 700, fill: '#fff', 'font-family': 'Arial, sans-serif', 'pointer-events': 'none' }, distress, 'DISTRESS');
    const cover = el('g', { 'data-key': 'distressCover', tabindex: '0', role: 'button', 'aria-label': 'Schutzklappe der DISTRESS-Taste', class: 'fg-control', style: 'cursor:pointer' }, svg);
    const coverLid = el('rect', { x: 290, y: 322, width: 96, height: 82, rx: 12, fill: 'rgba(229,57,53,0.55)', stroke: '#8e1b1b', 'stroke-width': 3 }, cover);

    // Display
    el('rect', { x: 400, y: 108, width: 410, height: 250, rx: 12, fill: '#15171a' }, svg);
    el('rect', { x: 412, y: 118, width: 386, height: 230, rx: 6, fill: LCD }, svg);
    const lcd = el('g', { 'font-family': MONO, fill: LCD_INK }, svg);

    // Softkeys
    const softkeys = [0, 1, 2, 3].map((i) => key(svg, { id: `soft${i + 1}`, x: 416 + i * 97, y: 372, w: 86, h: 34, label: '', size: 12, ariaLabel: `Softkey ${i + 1}` }));
    const softTexts = softkeys.map((g) => g.querySelector('text'));

    // Tastenkreuz, MENU, CLR
    key(svg, { id: 'up', x: 862, y: 112, w: 64, h: 40, label: '▲', ariaLabel: 'Kanal auf / ▲' });
    key(svg, { id: 'down', x: 862, y: 218, w: 64, h: 40, label: '▼', ariaLabel: 'Kanal ab / ▼' });
    key(svg, { id: 'left', x: 820, y: 164, w: 40, h: 46, label: '◀', ariaLabel: 'links / Softkey-Seite zurück' });
    key(svg, { id: 'right', x: 928, y: 164, w: 40, h: 46, label: '▶', ariaLabel: 'rechts / Softkey-Seite vor' });
    key(svg, { id: 'enter', x: 864, y: 162, w: 60, h: 50, label: 'ENT', size: 15 });
    key(svg, { id: 'menu', x: 830, y: 274, w: 64, h: 36, label: 'MENU', size: 15 });
    key(svg, { id: 'clr', x: 902, y: 274, w: 64, h: 36, label: 'CLR', size: 15 });
    key(svg, { id: 'ch16', x: 790, y: 440, w: 56, round: true, label: '16/C', size: 16, color: '#1d5fbf' });

    // Drehknopf PWR/VOL/SQL: Ring drehen, Kappe drücken
    const ring = el('g', { 'data-knob': 'dial', tabindex: '0', role: 'slider', 'aria-label': 'Drehknopf PWR/VOL/SQL', class: 'fg-control', style: 'cursor:ns-resize;touch-action:none' }, svg);
    el('circle', { cx: 900, cy: 415, r: 52, fill: '#1b1d21', stroke: '#0f1114', 'stroke-width': 3 }, ring);
    const pointer = el('line', { x1: 900, y1: 415, x2: 900, y2: 369, stroke: '#f2f4f7', 'stroke-width': 4, 'stroke-linecap': 'round' }, ring);
    const cap = el('g', { 'data-key': 'knob', tabindex: '0', role: 'button', 'aria-label': 'Drehknopf drücken (1×: Lautstärke, 2×: Rauschsperre, 1 Sekunde: Ein/Aus)', class: 'fg-control', style: 'cursor:pointer' }, svg);
    el('circle', { cx: 900, cy: 415, r: 23, fill: '#2a2d32', stroke: '#0f1114', 'stroke-width': 2 }, cap);
    el('text', { x: 900, y: 488, 'text-anchor': 'middle', 'font-size': 14, 'font-weight': 700, fill: KEY_TEXT, 'font-family': 'Arial, sans-serif' }, svg, 'PWR/VOL/SQL');

    const highlight = el('rect', { fill: 'none', stroke: '#e3b505', 'stroke-width': 4, 'stroke-dasharray': '10 6', rx: 14, visibility: 'hidden', 'pointer-events': 'none' }, svg);

    function text(x, y, value, attrs = {}) {
        el('text', { x, y, 'font-size': 16, ...attrs }, lcd, value);
    }

    function drawSoftkeyBar(d) {
        el('rect', { x: 412, y: 322, width: 386, height: 26, fill: LCD_INK }, lcd);
        for (const f of fields(d.softkeys ?? [])) {
            if (! f.label) continue;
            const x = 416 + f.start * 97 + (f.span * 97 - 11) / 2;
            text(x, 340, f.label, { 'text-anchor': 'middle', 'font-size': 13, fill: LCD, 'font-weight': 700 });
            if (f.span > 1) el('rect', { x: 418 + f.start * 97, y: 325, width: f.span * 97 - 14, height: 20, rx: 3, fill: 'none', stroke: LCD, 'stroke-width': 1 }, lcd);
        }
        if (d.pages) {
            if (d.pages.current > 0) text(415, 340, '◂', { fill: LCD, 'font-size': 13 });
            if (d.pages.current < d.pages.count - 1) text(786, 340, '▸', { fill: LCD, 'font-size': 13 });
        }
    }

    function drawDisplay(d) {
        lcd.replaceChildren();
        // Breite Felder: beide Tasten tragen die Beschriftung.
        softTexts.forEach((t, i) => { t.textContent = d.softkeys?.[i] ?? ''; });
        if (! d.on) return;
        const st = d.status;
        text(422, 138, [st.tx ? 'TX' : st.busy ? 'BUSY' : '', st.power, st.group, st.mail ? '✉' : ''].filter(Boolean).join('  '), { 'font-size': 15 });
        drawSoftkeyBar(d);

        if (d.panel) {
            const p = d.panel;
            if (p.title) {
                el('rect', { x: 412, y: 146, width: 386, height: 22, fill: LCD_INK }, lcd);
                text(605, 162, p.title.startsWith('≡') || p.title.startsWith('!!') ? p.title : `≡ ${p.title} ≡`, { 'text-anchor': 'middle', 'font-size': 14, fill: LCD, 'font-weight': 700 });
            }
            let y = 188;
            if (p.note) {
                text(605, y, p.note, { 'text-anchor': 'middle', 'font-size': 14 });
                y += 24;
            }
            if (p.items) {
                const visible = p.note ? 4 : 5;
                const first = Math.max(0, Math.min(p.cursor - 2, p.items.length - visible));
                p.items.slice(first, first + visible).forEach((item, i) => {
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
                const e = p.editor;
                const width = e.chars.length * 17;
                e.chars.forEach((c, i) => {
                    const x = 605 - width / 2 + i * 17;
                    text(x, y + 4, c, { 'font-size': 19 });
                    if (i === e.cursor) el('rect', { x: x - 1, y: y + 8, width: 14, height: 3, fill: LCD_INK }, lcd);
                });
                // Zeichenzeile, markiertes Zeichen invers
                const rowWidth = e.row.length * 22;
                e.row.forEach((c, i) => {
                    const x = 605 - rowWidth / 2 + i * 22;
                    if (i === e.sel) el('rect', { x: x - 3, y: y + 22, width: 20, height: 22, fill: LCD_INK }, lcd);
                    text(x + 7, y + 39, c, { 'text-anchor': 'middle', 'font-size': 16, fill: i === e.sel ? LCD : LCD_INK });
                });
            }
            if (typeof p.progress === 'number') {
                el('rect', { x: 450, y: 268, width: 310, height: 14, fill: 'none', stroke: LCD_INK, 'stroke-width': 2 }, lcd);
                el('rect', { x: 452, y: 270, width: 306 * p.progress, height: 10, fill: LCD_INK }, lcd);
            }

            return;
        }

        el('rect', { x: 418, y: 150, width: 46, height: 20, fill: LCD_INK }, lcd);
        text(441, 165, d.mode, { 'text-anchor': 'middle', 'font-size': 13, fill: LCD, 'font-weight': 700 });
        if (d.watch) text(474, 165, d.watch, { 'font-size': 14 });
        d.lines.forEach((line, i) => text(422, 214 + i * 24, line, { 'font-size': 16 }));
        el('text', { x: 742, y: 268, 'text-anchor': 'end', 'font-size': 104, 'font-weight': 700, 'font-family': 'Arial, sans-serif' }, lcd, d.channel);
        const right = [st.tag ? '★' : '', st.call ? 'CALL' : '', st.dup ? 'DUP' : ''].filter(Boolean);
        right.forEach((label, i) => text(792, 190 + i * 22, label, { 'text-anchor': 'end', 'font-size': 14 }));
        text(742, 304, d.channelName, { 'text-anchor': 'end', 'font-size': 14 });
        if (d.window) {
            el('rect', { x: 440, y: 190, width: 330, height: 70, rx: 6, fill: LCD, stroke: LCD_INK, 'stroke-width': 2 }, lcd);
            text(456, 218, `${d.window.label} ${d.window.value}`, { 'font-size': 18, 'font-weight': 700 });
            el('rect', { x: 456, y: 232, width: 296, height: 12, fill: 'none', stroke: LCD_INK, 'stroke-width': 2 }, lcd);
            el('rect', { x: 458, y: 234, width: 292 * d.window.level, height: 8, fill: LCD_INK }, lcd);
        }
    }

    function softkeyNode(display, label) {
        const index = (display.softkeys ?? []).indexOf(label);
        if (index >= 0) return softkeys[index];
        // Nicht sichtbar: die Blättertaste in Richtung der richtigen Seite.
        const pages = display.pages;
        const page = pages?.labels.findIndex((labels) => labels.includes(label)) ?? -1;
        const direction = page >= 0 && page < pages.current ? 'left' : 'right';

        return svg.querySelector(`[data-key="${direction}"]`);
    }

    return {
        svg,
        update(display, radioState, target) {
            drawDisplay(display);
            const level = radioState.knobMode === 'sql' ? radioState.sql / 10 : radioState.vol / 20;
            pointer.setAttribute('transform', `rotate(${-135 + level * 270} 900 415)`);
            if (radioState.coverOpen) Object.entries({ y: 270, height: 30 }).forEach(([k, v]) => coverLid.setAttribute(k, v));
            else Object.entries({ y: 322, height: 82 }).forEach(([k, v]) => coverLid.setAttribute(k, v));

            let node = null;
            if (target?.startsWith('sk:')) node = softkeyNode(display, target.slice(3));
            else if (target) node = svg.querySelector(`[data-key="${target}"], [data-knob="${target}"]`);
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

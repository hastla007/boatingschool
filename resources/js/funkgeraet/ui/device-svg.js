// Eigene Zeichnung des IC-M505 (SVG), angelehnt an die Frontansicht der
// Bedienungsanleitung: Lautsprecher links, Display in der Mitte, Tasten
// rechts und unten, Kanalwahlknopf mit ENTER, Regler VOL und SQL, Hörer mit
// Sprechtaste. Keine Fotos oder Herstellergrafiken (siehe Konzept,
// Rechtliches). Jedes Bedienteil trägt data-key bzw. data-knob; die
// Oberfläche (app.js) hängt die Bedienung daran.

const NS = 'http://www.w3.org/2000/svg';

function el(name, attrs = {}, parent = null, text = null) {
    const node = document.createElementNS(NS, name);
    for (const [key, value] of Object.entries(attrs)) node.setAttribute(key, value);
    if (text !== null) node.textContent = text;
    if (parent) parent.appendChild(node);

    return node;
}

const BODY = '#24272c';
const KEY = '#3a3e45';
const KEY_TEXT = '#f2f4f7';
const LCD = '#b7c2a1';
const LCD_INK = '#1e2717';
const MONO = 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';

function key(svg, { id, x, y, w = 92, h = 44, label, sub = null, color = KEY, round = false, ariaLabel = null }) {
    const g = el('g', { 'data-key': id, tabindex: '0', role: 'button', 'aria-label': ariaLabel ?? label, class: 'fg-control', style: 'cursor:pointer' }, svg);
    if (round) {
        el('circle', { cx: x, cy: y, r: w / 2, fill: color, stroke: '#0f1114', 'stroke-width': 3 }, g);
    } else {
        el('rect', { x, y, width: w, height: h, rx: 10, fill: color, stroke: '#0f1114', 'stroke-width': 3 }, g);
    }
    const cx = round ? x : x + w / 2;
    const cy = round ? y : y + h / 2;
    el('text', { x: cx, y: cy + 7, 'text-anchor': 'middle', 'font-size': round ? 26 : 19, 'font-weight': 700, fill: KEY_TEXT, 'font-family': 'Arial, sans-serif', 'pointer-events': 'none' }, g, label);
    if (sub) el('text', { x: cx, y: round ? y - w / 2 - 10 : y - 9, 'text-anchor': 'middle', 'font-size': 14, 'font-weight': 700, fill: '#d6dae0', 'font-family': 'Arial, sans-serif', 'pointer-events': 'none' }, svg, sub);

    return g;
}

function knob(svg, { id, cx, cy, r, label, ariaLabel }) {
    const g = el('g', { 'data-knob': id, tabindex: '0', role: 'slider', 'aria-label': ariaLabel, class: 'fg-control', style: 'cursor:ns-resize;touch-action:none' }, svg);
    for (let i = 0; i < 11; i++) {
        const a = (-135 + i * 27) * Math.PI / 180;
        el('circle', { cx: cx + Math.sin(a) * (r + 12), cy: cy - Math.cos(a) * (r + 12), r: 2.6, fill: '#d6dae0' }, g);
    }
    el('circle', { cx, cy, r, fill: '#1b1d21', stroke: '#0f1114', 'stroke-width': 3 }, g);
    const pointer = el('line', { x1: cx, y1: cy, x2: cx, y2: cy - r + 6, stroke: '#f2f4f7', 'stroke-width': 4, 'stroke-linecap': 'round' }, g);
    if (label) el('text', { x: cx + r + 22, y: cy + 8, 'font-size': 20, 'font-weight': 700, fill: KEY_TEXT, 'font-family': 'Arial, sans-serif' }, svg, label);

    return { g, pointer, cx, cy };
}

export function renderDevice(container) {
    const svg = el('svg', { viewBox: '0 0 1000 560', role: 'group', 'aria-label': 'Funkgerät IC-M505 (Nachbildung)', style: 'width:100%;height:auto;user-select:none;-webkit-user-select:none' });
    container.appendChild(svg);

    // Hörer mit Kabel
    el('path', { d: 'M110 470 C 110 520, 190 530, 230 500', fill: 'none', stroke: '#15171a', 'stroke-width': 10 }, svg);
    const handset = el('g', {}, svg);
    el('path', { d: 'M40 150 Q 95 95 150 150 L 165 420 Q 95 480 25 420 Z', fill: '#2d3036', stroke: '#0f1114', 'stroke-width': 3 }, handset);
    for (let i = 0; i < 6; i++) el('rect', { x: 55, y: 160 + i * 18, width: 85, height: 8, rx: 4, fill: '#15171a' }, handset);
    const ptt = el('g', { 'data-key': 'ptt', tabindex: '0', role: 'button', 'aria-label': 'Sprechtaste (PTT) gedrückt halten', class: 'fg-control', style: 'cursor:pointer' }, svg);
    el('rect', { x: 14, y: 210, width: 22, height: 80, rx: 8, fill: '#1b1d21', stroke: '#0f1114', 'stroke-width': 3 }, ptt);
    el('text', { x: 6, y: 205, 'font-size': 13, 'font-weight': 700, fill: '#2d3036', 'font-family': 'Arial, sans-serif' }, svg, 'PTT');
    key(svg, { id: 'micUp', x: 52, y: 340, w: 40, h: 34, label: '▲', ariaLabel: 'Hörer Kanal auf' });
    key(svg, { id: 'micDown', x: 52, y: 378, w: 40, h: 34, label: '▼', ariaLabel: 'Hörer Kanal ab' });
    key(svg, { id: 'micHiLo', x: 102, y: 358, w: 48, h: 34, label: 'H/L', sub: 'HI/LO', ariaLabel: 'Hörer Sendeleistung HI/LO' });

    // Gehäuse
    el('rect', { x: 200, y: 30, width: 785, height: 500, rx: 42, fill: BODY, stroke: '#0f1114', 'stroke-width': 4 }, svg);
    el('text', { x: 560, y: 70, 'text-anchor': 'middle', 'font-size': 22, 'font-weight': 700, fill: '#e6e8eb', 'font-family': 'Arial, sans-serif', 'letter-spacing': 2 }, svg, 'VHF MARINE  IC-M505');
    for (let i = 0; i < 5; i++) el('rect', { x: 230, y: 100 + i * 40, width: 130, height: 16, rx: 8, fill: '#15171a' }, svg);

    // Display
    el('rect', { x: 382, y: 92, width: 406, height: 236, rx: 14, fill: '#15171a' }, svg);
    el('rect', { x: 392, y: 102, width: 386, height: 216, rx: 8, fill: LCD }, svg);
    const lcd = el('g', { 'font-family': MONO, fill: LCD_INK, 'aria-live': 'polite' }, svg);

    // Tasten rechts
    el('rect', { x: 820, y: 84, width: 130, height: 92, rx: 12, fill: '#7a0d12', stroke: '#0f1114', 'stroke-width': 3 }, svg);
    const distress = key(svg, { id: 'distress', x: 838, y: 108, w: 94, h: 46, label: 'DISTRESS', color: '#b3121b', ariaLabel: 'DISTRESS-Taste (5 Sekunden halten)' });
    distress.querySelector('text').setAttribute('font-size', 16);
    // Schutzklappe über der Taste; geöffnet klappt sie nach oben weg.
    const cover = el('g', { 'data-key': 'distressCover', tabindex: '0', role: 'button', 'aria-label': 'Schutzklappe der DISTRESS-Taste', class: 'fg-control', style: 'cursor:pointer' }, svg);
    const coverLid = el('rect', { x: 826, y: 90, width: 118, height: 80, rx: 10, fill: '#e0414a', 'fill-opacity': 0.8, stroke: '#7a0d12', 'stroke-width': 2 }, cover);
    key(svg, { id: 'menu', x: 840, y: 196, label: 'MENU' });
    key(svg, { id: 'clr', x: 840, y: 252, label: 'CLR' });
    key(svg, { id: 'hail', x: 840, y: 316, label: 'HAIL', sub: 'RX' });
    key(svg, { id: 'ic', x: 840, y: 380, label: 'IC' });
    key(svg, { id: 'ch16', x: 770, y: 420, w: 72, label: '16/C', color: '#1d5fb4', round: true, ariaLabel: '16/C: Kanal 16, 1 Sekunde Anrufkanal' });

    // Tasten unten und Regler
    key(svg, { id: 'power', x: 236, y: 350, w: 96, label: 'POWER', ariaLabel: 'POWER: Ein, 1 Sekunde halten Aus' });
    key(svg, { id: 'hl', x: 346, y: 350, w: 76, label: 'H/L', ariaLabel: 'H/L: Sendeleistung, gehalten Umschalttaste' });
    key(svg, { id: 'scan', x: 436, y: 350, w: 86, label: 'SCAN', sub: 'TAG' });
    key(svg, { id: 'dial', x: 536, y: 350, w: 86, label: 'DIAL', sub: 'DUAL GRP', ariaLabel: 'DIAL: zurück zum Betriebskanal, 1 Sekunde Zweikanalwache' });
    const vol = knob(svg, { id: 'vol', cx: 270, cy: 458, r: 30, label: 'VOL', ariaLabel: 'Lautstärke VOL' });
    const sql = knob(svg, { id: 'sql', cx: 400, cy: 458, r: 30, label: 'SQL', ariaLabel: 'Rauschsperre SQL' });
    const channel = knob(svg, { id: 'channel', cx: 650, cy: 452, r: 62, label: null, ariaLabel: 'Kanalwahlknopf' });
    const enter = el('g', { 'data-key': 'enter', tabindex: '0', role: 'button', 'aria-label': 'ENTER (Kanalwahlknopf drücken)', class: 'fg-control', style: 'cursor:pointer' }, svg);
    el('circle', { cx: 650, cy: 452, r: 30, fill: '#2a2d32', stroke: '#0f1114', 'stroke-width': 2 }, enter);
    el('text', { x: 650, y: 528, 'text-anchor': 'middle', 'font-size': 18, 'font-weight': 700, fill: KEY_TEXT, 'font-family': 'Arial, sans-serif' }, svg, 'ENTER');

    const highlight = el('rect', { fill: 'none', stroke: '#e3b505', 'stroke-width': 4, 'stroke-dasharray': '10 6', rx: 14, visibility: 'hidden', 'pointer-events': 'none' }, svg);

    function setKnob(k, level) {
        const angle = -135 + (level / 10) * 270;
        k.pointer.setAttribute('transform', `rotate(${angle} ${k.cx} ${k.cy})`);
    }

    let channelAngle = 0;

    function text(x, y, value, attrs = {}) {
        el('text', { x, y, 'font-size': 18, ...attrs }, lcd, value);
    }

    function drawDisplay(d) {
        lcd.replaceChildren();
        if (! d.on) return;
        const st = d.status;
        text(404, 126, [st.tx ? 'TX' : st.busy ? 'BUSY' : '', st.power].filter(Boolean).join(' '), { 'font-size': 17 });
        text(560, 126, [st.group, st.dup ? 'DUP' : ''].filter(Boolean).join(' '), { 'font-size': 17 });
        if (st.call) text(700, 126, 'CALL', { 'font-size': 17 });

        if (d.panel) {
            const p = d.panel;
            text(585, 152, p.title, { 'text-anchor': 'middle', 'font-size': 17, 'font-weight': 700 });
            if (p.subtitle) text(404, 176, p.subtitle, { 'font-size': 17 });
            let y = 200;
            if (p.items) {
                const first = Math.max(0, Math.min(p.cursor - 2, p.items.length - 5));
                p.items.slice(first, first + 5).forEach((item, i) => {
                    const selected = first + i === p.cursor;
                    text(404, y, `${selected ? '→' : ' '}${item}`, { 'font-size': 17, 'font-weight': selected ? 700 : 400 });
                    y += 21;
                });
            }
            if (p.editor) {
                const chars = p.editor.chars;
                chars.forEach((c, i) => {
                    const x = 410 + i * 15;
                    text(x, 214, c, { 'font-size': 20 });
                    if (i === p.editor.cursor) el('rect', { x: x - 1, y: 218, width: 13, height: 3, fill: LCD_INK }, lcd);
                });
            }
            (p.lines ?? []).forEach((line) => {
                text(585, y, line, { 'text-anchor': 'middle', 'font-size': 18, class: p.blink ? 'fg-blink' : '' });
                y += 23;
            });
            if (typeof p.progress === 'number') {
                el('rect', { x: 430, y: 250, width: 310, height: 14, fill: 'none', stroke: LCD_INK, 'stroke-width': 2 }, lcd);
                el('rect', { x: 432, y: 252, width: 306 * p.progress, height: 10, fill: LCD_INK }, lcd);
            }
            if (p.footer) text(585, 306, p.footer, { 'text-anchor': 'middle', 'font-size': 15 });

            return;
        }

        el('text', { x: 768, y: 268, 'text-anchor': 'end', 'font-size': 112, 'font-weight': 700, 'font-family': 'Arial, sans-serif' }, lcd, d.channel);
        text(768, 302, d.channelName, { 'text-anchor': 'end', 'font-size': 16 });
        if (d.watch) text(404, 152, d.watch, { 'font-size': 16 });
        d.lines.forEach((line, i) => text(404, 214 + i * 24, line, { 'font-size': 18, 'font-weight': d.alarm && i < 2 ? 700 : 400 }));
    }

    return {
        svg,
        update(display, radioState, target) {
            drawDisplay(display);
            setKnob(vol, radioState.vol);
            setKnob(sql, radioState.sql);
            channel.pointer.setAttribute('transform', `rotate(${channelAngle} ${channel.cx} ${channel.cy})`);
            if (radioState.coverOpen) {
                Object.entries({ y: 60, height: 22 }).forEach(([k, v]) => coverLid.setAttribute(k, v));
            } else {
                Object.entries({ y: 90, height: 80 }).forEach(([k, v]) => coverLid.setAttribute(k, v));
            }

            const node = target ? svg.querySelector(`[data-key="${target}"], [data-knob="${target}"]`) : null;
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
        rotateChannel(steps) {
            channelAngle += steps * 15;
        },
    };
}

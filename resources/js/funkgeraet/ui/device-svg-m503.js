// Eigene Zeichnung von IC-M503 (oben) und DSC-Controller DS-100 (unten),
// angelehnt an die Frontansichten der Bedienungsanleitungen. Keine Fotos
// oder Herstellergrafiken. Gleiche Schnittstelle wie device-svg.js.

const NS = 'http://www.w3.org/2000/svg';
const BODY = '#26292e';
const KEY = '#3a3e45';
const KEY_TEXT = '#f2f4f7';
const LCD = '#b9c4a3';
const LCD_DS = '#c9d3b6';
const LCD_INK = '#1e2717';
const MONO = 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';

function el(name, attrs = {}, parent = null, text = null) {
    const node = document.createElementNS(NS, name);
    for (const [key, value] of Object.entries(attrs)) node.setAttribute(key, value);
    if (text !== null) node.textContent = text;
    if (parent) parent.appendChild(node);

    return node;
}

function key(svg, { id, x, y, w = 70, h = 34, label, sub = null, color = KEY, size = 15, ariaLabel = null }) {
    const g = el('g', { 'data-key': id, tabindex: '0', role: 'button', 'aria-label': ariaLabel ?? label, class: 'fg-control', style: 'cursor:pointer' }, svg);
    el('rect', { x, y, width: w, height: h, rx: 8, fill: color, stroke: '#0f1114', 'stroke-width': 2.5 }, g);
    el('text', { x: x + w / 2, y: y + h / 2 + (sub ? 1 : 5), 'text-anchor': 'middle', 'font-size': size, 'font-weight': 700, fill: KEY_TEXT, 'font-family': 'Arial, sans-serif', 'pointer-events': 'none' }, g, label);
    if (sub) el('text', { x: x + w / 2, y: y + h - 5, 'text-anchor': 'middle', 'font-size': 10, 'font-weight': 700, fill: '#cfd3d8', 'font-family': 'Arial, sans-serif', 'pointer-events': 'none' }, g, sub);

    return g;
}

function knob(svg, { id, cx, cy, r, label, ariaLabel }) {
    const g = el('g', { 'data-knob': id, tabindex: '0', role: 'slider', 'aria-label': ariaLabel, class: 'fg-control', style: 'cursor:ns-resize;touch-action:none' }, svg);
    el('circle', { cx, cy, r, fill: '#1b1d21', stroke: '#0f1114', 'stroke-width': 3 }, g);
    const pointer = el('line', { x1: cx, y1: cy, x2: cx, y2: cy - r + 5, stroke: '#f2f4f7', 'stroke-width': 3.5, 'stroke-linecap': 'round' }, g);
    if (label) el('text', { x: cx, y: cy + r + 16, 'text-anchor': 'middle', 'font-size': 13, 'font-weight': 700, fill: KEY_TEXT, 'font-family': 'Arial, sans-serif' }, svg, label);

    return { pointer, cx, cy };
}

export function renderDeviceM503(container) {
    const svg = el('svg', { viewBox: '0 0 1000 560', role: 'group', 'aria-label': 'Funkgerät IC-M503 mit DSC-Controller DS-100 (Nachbildung)', style: 'width:100%;height:auto;user-select:none;-webkit-user-select:none' });
    container.appendChild(svg);

    // Hörer
    el('path', { d: 'M120 430 C 120 500, 200 520, 280 470', fill: 'none', stroke: '#15171a', 'stroke-width': 9 }, svg);
    el('path', { d: 'M110 150 Q 140 115 175 115 Q 210 115 240 150 L 252 440 Q 175 478 98 440 Z', fill: '#2c2f34', stroke: '#15171a', 'stroke-width': 3 }, svg);
    for (let i = 0; i < 6; i++) el('rect', { x: 130, y: 165 + i * 18, width: 92, height: 6, rx: 3, fill: '#16181b' }, svg);
    const ptt = el('g', { 'data-key': 'ptt', tabindex: '0', role: 'button', 'aria-label': 'Sprechtaste (PTT)', class: 'fg-control', style: 'cursor:pointer' }, svg);
    el('rect', { x: 78, y: 215, width: 24, height: 100, rx: 9, fill: '#1b1d21', stroke: '#0f1114', 'stroke-width': 3 }, ptt);
    el('text', { x: 90, y: 207, 'text-anchor': 'middle', 'font-size': 13, 'font-weight': 700, fill: '#333', 'font-family': 'Arial, sans-serif' }, svg, 'PTT');
    key(svg, { id: 'micUp', x: 122, y: 320, w: 36, h: 32, label: '▲' });
    key(svg, { id: 'micDown', x: 122, y: 358, w: 36, h: 32, label: '▼' });
    key(svg, { id: 'micHiLo', x: 170, y: 338, w: 50, h: 34, label: 'HI/LO', size: 12, ariaLabel: 'HI/LO am Hörer' });

    // --- IC-M503 ---------------------------------------------------------------
    el('rect', { x: 280, y: 16, width: 700, height: 250, rx: 26, fill: BODY, stroke: '#101215', 'stroke-width': 4 }, svg);
    for (let i = 0; i < 7; i++) el('rect', { x: 298, y: 40 + i * 16, width: 62, height: 6, rx: 3, fill: '#16181b' }, svg);
    el('text', { x: 330, y: 172, 'text-anchor': 'middle', 'font-size': 12, 'font-weight': 700, fill: '#cfd3d8', 'font-family': 'Arial, sans-serif' }, svg, 'IC-M503');
    el('rect', { x: 376, y: 32, width: 330, height: 122, rx: 8, fill: '#15171a' }, svg);
    el('rect', { x: 384, y: 39, width: 314, height: 108, rx: 5, fill: LCD }, svg);
    const lcd = el('g', { 'font-family': MONO, fill: LCD_INK }, svg);
    key(svg, { id: 'ch16', x: 720, y: 50, w: 62, h: 58, label: '16', size: 22, color: '#1d5fbf' });
    key(svg, { id: 'dual', x: 384, y: 166, w: 72, label: 'DUAL' });
    key(svg, { id: 'scan', x: 464, y: 166, w: 72, label: 'SCAN', sub: 'TAG', size: 13 });
    key(svg, { id: 'dial', x: 544, y: 166, w: 72, label: 'DIAL' });
    key(svg, { id: 'call', x: 624, y: 166, w: 72, label: 'CALL' });
    key(svg, { id: 'power', x: 298, y: 214, w: 64, label: 'POWER', size: 12 });
    key(svg, { id: 'hl', x: 370, y: 214, w: 64, label: 'HI/LO', size: 12 });
    const vol = knob(svg, { id: 'vol', cx: 480, cy: 230, r: 18, label: null, ariaLabel: 'Lautstärke (VOL)' });
    el('text', { x: 506, y: 235, 'font-size': 12, 'font-weight': 700, fill: KEY_TEXT, 'font-family': 'Arial, sans-serif' }, svg, 'VOL');
    const sql = knob(svg, { id: 'sql', cx: 560, cy: 230, r: 18, label: null, ariaLabel: 'Rauschsperre (SQL)' });
    el('text', { x: 586, y: 235, 'font-size': 12, 'font-weight': 700, fill: KEY_TEXT, 'font-family': 'Arial, sans-serif' }, svg, 'SQL');
    const channel = knob(svg, { id: 'channel', cx: 880, cy: 118, r: 56, label: 'CHANNEL', ariaLabel: 'Kanalwahlknopf (CHANNEL)' });
    key(svg, { id: 'dimmer', x: 846, y: 214, w: 68, label: 'DIMMER', size: 11 });

    // --- DS-100 -----------------------------------------------------------------
    el('rect', { x: 280, y: 282, width: 700, height: 262, rx: 22, fill: '#2b2e33', stroke: '#101215', 'stroke-width': 4 }, svg);
    el('text', { x: 300, y: 300, 'font-size': 12, 'font-weight': 700, fill: '#cfd3d8', 'font-family': 'Arial, sans-serif' }, svg, 'DSC CONTROLLER DS-100');
    el('rect', { x: 296, y: 306, width: 372, height: 172, rx: 6, fill: '#15171a' }, svg);
    el('rect', { x: 302, y: 312, width: 360, height: 160, rx: 4, fill: LCD_DS }, svg);
    const ds = el('g', { 'font-family': MONO, fill: LCD_INK }, svg);

    const distress = el('g', { 'data-key': 'distress', tabindex: '0', role: 'button', 'aria-label': 'DISTRESS (5 Sekunden halten)', class: 'fg-control', style: 'cursor:pointer' }, svg);
    el('rect', { x: 300, y: 488, width: 80, height: 44, rx: 8, fill: '#c62828', stroke: '#6d0f0f', 'stroke-width': 3 }, distress);
    el('text', { x: 340, y: 515, 'text-anchor': 'middle', 'font-size': 12, 'font-weight': 700, fill: '#fff', 'font-family': 'Arial, sans-serif', 'pointer-events': 'none' }, distress, 'DISTRESS');
    const cover = el('g', { 'data-key': 'distressCover', tabindex: '0', role: 'button', 'aria-label': 'Schutzklappe der DISTRESS-Taste', class: 'fg-control', style: 'cursor:pointer' }, svg);
    const coverLid = el('rect', { x: 294, y: 482, width: 92, height: 56, rx: 10, fill: 'rgba(229,57,53,0.55)', stroke: '#8e1b1b', 'stroke-width': 3 }, cover);
    key(svg, { id: 'dsClr', x: 396, y: 492, w: 76, h: 38, label: 'CLR' });
    key(svg, { id: 'dsCall', x: 484, y: 492, w: 76, h: 38, label: 'CALL' });
    key(svg, { id: 'dsEnt', x: 572, y: 492, w: 76, h: 38, label: 'ENT' });

    const pad = [['d1', '1', 'QZ'], ['d2', '2', 'ABC'], ['d3', '3', 'DEF'], ['d4', '4', 'GHI'], ['d5', '5', 'JKL'], ['d6', '6', 'MNO'], ['d7', '7', 'PRS'], ['d8', '8', 'TUV'], ['d9', '9', 'WXY'], ['dsAa', 'A/a', null], ['d0', '0', null], ['dsBs', 'BS', null]];
    pad.forEach(([id, label, sub], i) => key(svg, { id, x: 700 + (i % 3) * 88, y: 306 + Math.floor(i / 3) * 44, w: 78, h: 38, label, sub, size: 15 }));
    key(svg, { id: 'dsLeft', x: 700, y: 492, w: 54, h: 38, label: '◀' });
    key(svg, { id: 'dsUp', x: 762, y: 492, w: 54, h: 38, label: '▲' });
    key(svg, { id: 'dsDown', x: 824, y: 492, w: 54, h: 38, label: '▼' });
    key(svg, { id: 'dsRight', x: 886, y: 492, w: 54, h: 38, label: '▶' });

    const highlight = el('rect', { fill: 'none', stroke: '#e3b505', 'stroke-width': 4, 'stroke-dasharray': '10 6', rx: 12, visibility: 'hidden', 'pointer-events': 'none' }, svg);
    let channelAngle = 0;

    function text(parent, x, y, value, attrs = {}) {
        el('text', { x, y, 'font-size': 14, ...attrs }, parent, value);
    }

    function drawRadio(d) {
        lcd.replaceChildren();
        if (! d.on) return;
        if (d.setMode) {
            text(lcd, 392, 62, 'Set mode', { 'font-size': 15, 'font-weight': 700 });
            text(lcd, 392, 112, d.setMode.item, { 'font-size': 15 });
            el('text', { x: 690, y: 132, 'text-anchor': 'end', 'font-size': 44, 'font-weight': 700, 'font-family': 'Arial, sans-serif' }, lcd, d.setMode.value);

            return;
        }
        const st = d.status;
        text(lcd, 392, 58, [st.tx ? 'TX' : st.busy ? 'BUSY' : '', st.group].filter(Boolean).join('  '), { 'font-size': 14 });
        text(lcd, 392, 80, [st.power, st.call ? 'CALL' : ''].filter(Boolean).join('  '), { 'font-size': 14 });
        text(lcd, 392, 102, [st.tag ? 'TAG' : '', st.dup ? 'DUP' : ''].filter(Boolean).join('  '), { 'font-size': 14 });
        text(lcd, 392, 136, d.watch ?? d.channelName, { 'font-size': 14 });
        el('text', { x: 692, y: 128, 'text-anchor': 'end', 'font-size': 82, 'font-weight': 700, 'font-family': 'Arial, sans-serif' }, lcd, d.channel);
    }

    function drawDs(d) {
        ds.replaceChildren();
        if (! d.on) return;
        const p = d.ds;
        let y = 330;
        if (p.title) { text(ds, 310, y, p.title, { 'font-size': 13, 'font-weight': 700 }); y += 20; }
        if (p.items) {
            p.items.forEach((item, i) => { text(ds, 310, y, `${i === p.cursor ? '▶' : ' '}${item}`, { 'font-size': 13, 'font-weight': i === p.cursor ? 700 : 400 }); y += 17; });
        }
        (p.lines ?? []).forEach((line) => { text(ds, 310, y, line, { 'font-size': 13, class: p.blink ? 'fg-blink' : '', 'xml:space': 'preserve' }); y += 17; });
        if (p.editor) {
            p.editor.chars.forEach((c, i) => {
                const x = 316 + i * 13;
                text(ds, x, y + 8, c, { 'font-size': 16 });
                if (i === p.editor.cursor) el('rect', { x: x - 1, y: y + 12, width: 11, height: 3, fill: LCD_INK }, ds);
            });
        }
        if (typeof p.progress === 'number') {
            el('rect', { x: 320, y: 440, width: 320, height: 12, fill: 'none', stroke: LCD_INK, 'stroke-width': 2 }, ds);
            el('rect', { x: 322, y: 442, width: 316 * p.progress, height: 8, fill: LCD_INK }, ds);
        }
        if (p.footer) text(ds, 482, 466, p.footer, { 'text-anchor': 'middle', 'font-size': 12 });
    }

    function setKnob(k, level) {
        k.pointer.setAttribute('transform', `rotate(${-135 + (level / 10) * 270} ${k.cx} ${k.cy})`);
    }

    return {
        svg,
        update(display, radioState, target) {
            drawRadio(display);
            drawDs(display);
            setKnob(vol, radioState.vol);
            setKnob(sql, radioState.sql);
            channel.pointer.setAttribute('transform', `rotate(${channelAngle} ${channel.cx} ${channel.cy})`);
            if (radioState.coverOpen) Object.entries({ y: 440, height: 30 }).forEach(([k, v]) => coverLid.setAttribute(k, v));
            else Object.entries({ y: 482, height: 56 }).forEach(([k, v]) => coverLid.setAttribute(k, v));
            const node = target ? svg.querySelector(`[data-key="${target}"], [data-knob="${target}"]`) : null;
            if (node) {
                const box = node.getBBox();
                highlight.setAttribute('x', box.x - 6);
                highlight.setAttribute('y', box.y - 6);
                highlight.setAttribute('width', box.width + 12);
                highlight.setAttribute('height', box.height + 12);
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

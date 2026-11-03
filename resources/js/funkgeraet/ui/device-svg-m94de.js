// Zeichnung des Handfunkgeräts IC-M94DE (SVG) im Stil der Fotos, ohne
// Herstellerlogo und mit gekürzter Antenne: links die Vorderansicht
// (Display, drei Softkeys, MENU, CLR, Steuerkreuz mit ENT, VOL/SQL, 16/C,
// Lautsprecher; Sprechtaste und Ein/Aus-Taste an der linken Seite), rechts
// die Rückseite oben mit DISTRESS unter der roten Schutzkappe. Tasten und
// Display leuchten bei eingeschaltetem Gerät. Gleiche Schnittstelle wie
// device-svg.js; ein Ziel "sk:LABEL" markiert den Softkey mit dieser
// Beschriftung bzw. die Blättertaste zur richtigen Seite.

import { createBacklight, glowFilter } from './backlight.js';

const NS = 'http://www.w3.org/2000/svg';
const LCD_ON = '#dfe7ee';
const LCD_INK = '#16222d';
const MONO = 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';
const SANS = 'Arial, Helvetica, sans-serif';
const PRINT = '#e4e6ea';
const RIM = { on: '#f4ddb0', off: '#3a3c41' };
const LABEL = { on: '#fff6e2', off: '#8c9097' };

// Vorderansicht: Fotopunkte auf die Zeichenfläche (Gehäuse x 160–482).
const F = (px, py) => [160 + (px - 287) * 1.88, 10 + (py - 455) * 1.88];
// Rückseite oben: Fotopunkte auf die Zeichenfläche (ab x 560).
const B = (px, py) => [560 + (px - 273) * 1.6, 120 + (py - 488) * 1.6];

// Die Anzeige wird in einer Fläche von 220 × 233 geschrieben und in das
// Display eingepasst.
const LCD_W = 220;

function el(name, attrs = {}, parent = null, text = null) {
    const node = document.createElementNS(NS, name);
    for (const [key, value] of Object.entries(attrs)) node.setAttribute(key, value);
    if (text !== null) node.textContent = text;
    if (parent) parent.appendChild(node);

    return node;
}

function defs(svg) {
    const d = el('defs', {}, svg);
    const grad = (id, stops, attrs = {}) => {
        const g = el('linearGradient', { id, x1: 0, y1: 0, x2: 0, y2: 1, ...attrs }, d);
        stops.forEach(([o, c]) => el('stop', { offset: o, 'stop-color': c }, g));
    };
    const radial = (id, stops) => {
        const g = el('radialGradient', { id, cx: '40%', cy: '35%', r: '70%' }, d);
        stops.forEach(([o, c]) => el('stop', { offset: o, 'stop-color': c }, g));
    };
    grad('m94-body', [[0, '#5a5d63'], [0.12, '#3c3f44'], [0.88, '#33363b'], [1, '#55585e']], { x1: 0, x2: 1, y1: 0, y2: 0 });
    grad('m94-face', [[0, '#2e3035'], [1, '#202226']]);
    grad('m94-bezel', [[0, '#16171a'], [1, '#0b0c0d']]);
    grad('m94-lcd', [[0, '#eef3f7'], [1, '#d3dde6']]);
    grad('m94-key', [[0, '#34363b'], [1, '#141518']]);
    grad('m94-blue', [[0, '#4a8fea'], [1, '#1b54b0']]);
    grad('m94-red', [[0, '#e0353f'], [1, '#8e0f16']]);
    grad('m94-side', [[0, '#2a2c30'], [1, '#121315']], { x1: 0, x2: 1, y1: 0, y2: 0 });
    grad('m94-antenna', [[0, '#2a2c30'], [0.5, '#3e4146'], [1, '#1b1c1f']], { x1: 0, x2: 1, y1: 0, y2: 0 });
    grad('m94-back', [[0, '#4b4e54'], [1, '#3a3d42']]);
    radial('m94-knob', [[0, '#4a4d52'], [1, '#141517']]);
    const shadow = el('filter', { id: 'm94-shadow', x: '-15%', y: '-15%', width: '140%', height: '140%' }, d);
    el('feDropShadow', { dx: 0, dy: 1.5, stdDeviation: 1.3, 'flood-color': '#000', 'flood-opacity': 0.6 }, shadow);
    const bodyShadow = el('filter', { id: 'm94-bodyshadow', x: '-10%', y: '-5%', width: '125%', height: '115%' }, d);
    el('feDropShadow', { dx: 3, dy: 6, stdDeviation: 6, 'flood-color': '#000', 'flood-opacity': 0.35 }, bodyShadow);
    glowFilter(d, 'm94-glow', NS, 1.2);
}

const control = (svg, attrs) => el('g', { tabindex: '0', role: 'button', class: 'fg-control', style: 'cursor:pointer', ...attrs }, svg);

// Beleuchtete Gummitaste zwischen zwei Fotopunkten; label darf mehrzeilig sein.
function key(svg, light, id, a, b, label, { ariaLabel = null, size = 15, rx = 10, bar = false, fill = 'url(#m94-key)', ink = null } = {}) {
    const [x1, y1] = F(...a);
    const [x2, y2] = F(...b);
    const w = x2 - x1;
    const h = y2 - y1;
    const g = control(svg, { 'data-key': id, 'aria-label': ariaLabel ?? [label].flat().join(' ') });
    const rim = el('rect', { x: x1, y: y1, width: w, height: h, rx, fill, stroke: ink ? '#0e3d86' : RIM.on, 'stroke-width': ink ? 1.5 : 2.2, filter: 'url(#m94-shadow)' }, g);
    if (! ink) light.add(rim, { stroke: RIM.on }, { stroke: RIM.off });
    el('rect', { x: x1 + 4, y: y1 + 3, width: w - 8, height: h * 0.35, rx: rx - 3, fill: '#ffffff', 'fill-opacity': 0.07, 'pointer-events': 'none' }, g);
    const lit = { fill: ink ?? LABEL.on, filter: ink ? null : 'url(#m94-glow)' };
    const dark = { fill: ink ?? LABEL.off, filter: null };
    if (bar) {
        light.add(el('rect', { x: x1 + w / 2 - 10, y: y1 + h / 2 - 2, width: 20, height: 4, rx: 2, ...lit, 'pointer-events': 'none' }, g), lit, dark);
    } else {
        const lines = [label].flat();
        const lineHeight = size * 1.02;
        const top = y1 + h / 2 - (lines.length * lineHeight) / 2 + size * 0.8;
        lines.forEach((line, i) => {
            const t = el('text', { x: x1 + w / 2, y: top + i * lineHeight, 'text-anchor': 'middle', 'font-size': size, 'font-weight': 700, 'font-family': SANS, ...lit, 'pointer-events': 'none' }, g, line);
            if (! ink) light.add(t, lit, dark);
        });
    }

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

export function renderDeviceM94DE(container) {
    const svg = el('svg', { viewBox: '0 0 1000 880', role: 'group', 'aria-label': 'Handfunkgerät IC-M94DE (Nachbildung), Vorderansicht und Rückseite', style: 'width:100%;height:auto;user-select:none;-webkit-user-select:none' });
    container.appendChild(svg);
    defs(svg);
    const light = createBacklight();

    // --- Vorderansicht ---------------------------------------------------------
    // Gekürzte Antenne und Buchsenabdeckung oben
    const [ax1] = F(392, 0);
    const [ax2] = F(405, 0);
    el('rect', { x: ax1, y: 8, width: ax2 - ax1, height: 60, rx: (ax2 - ax1) / 2, fill: 'url(#m94-antenna)', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    const [bx1, by1] = F(383, 472);
    const [bx2] = F(415, 0);
    el('path', { d: `M${bx1 + 10} ${by1} L ${bx2 - 10} ${by1} L ${bx2} 120 L ${bx1} 120 Z`, fill: 'url(#m94-antenna)', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    for (let i = 0; i < 5; i++) el('line', { x1: bx1 + 4, y1: by1 + 14 + i * 10, x2: bx2 - 4, y2: by1 + 14 + i * 10, stroke: '#4a4d52', 'stroke-width': 1.2 }, svg);
    const [kx1, ky1] = F(327, 497);
    const [kx2] = F(362, 0);
    el('rect', { x: kx1, y: ky1, width: kx2 - kx1, height: 40, rx: 10, fill: 'url(#m94-knob)', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    for (let i = 0; i < 9; i++) el('line', { x1: kx1 + 6 + i * ((kx2 - kx1 - 12) / 8), y1: ky1 + 3, x2: kx1 + 6 + i * ((kx2 - kx1 - 12) / 8), y2: ky1 + 15, stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);

    // Sprechtaste und Ein/Aus-Taste an der linken Seite
    const [px1, py1] = F(279, 562);
    const [px2, py2] = F(291, 652);
    const ptt = control(svg, { 'data-key': 'ptt', 'aria-label': 'Sprechtaste (PTT) gedrückt halten' });
    el('rect', { x: px1, y: py1, width: px2 - px1, height: py2 - py1, rx: 9, fill: 'url(#m94-side)', stroke: '#0b0c0d', 'stroke-width': 2, filter: 'url(#m94-shadow)' }, ptt);
    for (let i = 0; i < 9; i++) el('rect', { x: px1 + 4, y: py1 + 22 + i * 14, width: 8, height: 3, rx: 1.5, fill: '#44474c', 'pointer-events': 'none' }, ptt);
    el('text', { x: px1 - 4, y: py1 + 2, 'text-anchor': 'end', 'font-size': 13, 'font-weight': 700, fill: '#6b6e74', 'font-family': SANS }, svg, 'PTT');
    const [wx1, wy1] = F(280, 662);
    const [wx2, wy2] = F(291, 690);
    const power = control(svg, { 'data-key': 'power', 'aria-label': 'Ein/Aus (1 Sekunde halten)' });
    el('rect', { x: wx1, y: wy1, width: wx2 - wx1, height: wy2 - wy1, rx: 7, fill: 'url(#m94-side)', stroke: '#0b0c0d', 'stroke-width': 2, filter: 'url(#m94-shadow)' }, power);
    el('text', { x: wx1 - 4, y: (wy1 + wy2) / 2 + 5, 'text-anchor': 'end', 'font-size': 15, 'font-weight': 700, fill: '#6b6e74', 'font-family': SANS }, svg, '⏻');

    // Gehäuse mit hellerem Seitenrand und dunkler Front
    const [gx1, gy1] = F(287, 512);
    const [gx2, gy2] = F(458, 912);
    el('rect', { x: gx1, y: gy1, width: gx2 - gx1, height: gy2 - gy1, rx: 56, fill: 'url(#m94-body)', stroke: '#0b0c0d', 'stroke-width': 3, filter: 'url(#m94-bodyshadow)' }, svg);
    const [fx1, fy1] = F(298, 532);
    const [fx2, fy2] = F(447, 902);
    el('rect', { x: fx1, y: fy1, width: fx2 - fx1, height: fy2 - fy1, rx: 44, fill: 'url(#m94-face)', stroke: '#0b0c0d', 'stroke-width': 2 }, svg);

    // Display mit Rahmen
    const [dx1, dy1] = F(315, 562);
    const [dx2, dy2] = F(433, 687);
    el('rect', { x: dx1, y: dy1, width: dx2 - dx1, height: dy2 - dy1, rx: 14, fill: 'url(#m94-bezel)', stroke: '#050506', 'stroke-width': 2 }, svg);
    const [lx1, ly1] = F(320, 568);
    const [lx2, ly2] = F(428, 682);
    const lcdBox = { x: lx1, y: ly1, width: lx2 - lx1, height: ly2 - ly1 };
    light.add(el('rect', { ...lcdBox, rx: 6, fill: 'url(#m94-lcd)' }, svg), { fill: 'url(#m94-lcd)' }, { fill: '#5b6066' });
    const k = lcdBox.width / LCD_W;
    const lcd = el('g', { 'font-family': MONO, fill: LCD_INK, transform: `translate(${lcdBox.x} ${lcdBox.y}) scale(${k})` }, svg);
    const [mx, my] = F(374, 698);
    el('text', { x: mx, y: my, 'text-anchor': 'middle', 'font-size': 17, 'font-weight': 700, fill: PRINT, 'font-family': SANS, 'letter-spacing': 1 }, svg, 'IC-M94DE');

    // Softkeys
    const softkeys = [[322, 352], [359, 389], [395, 425]].map(([a, b], i) => key(svg, light, `soft${i + 1}`, [a, 704], [b, 719], '', { ariaLabel: `Softkey ${i + 1}`, bar: true, rx: 12 }));

    // MENU, CLR, VOL/SQL, 16/C
    key(svg, light, 'menu', [313, 727], [345, 746], 'MENU', { size: 14, rx: 12 });
    key(svg, light, 'clr', [401, 725], [432, 746], ['CLR', '⚿'], { ariaLabel: 'CLR (1 Sekunde: Tastensperre)', size: 13, rx: 12 });
    key(svg, light, 'volsql', [313, 752], [347, 773], ['VOL', 'SQL'], { ariaLabel: 'VOL/SQL (1×: Lautstärke, 2×: Rauschsperre, 1 Sekunde: Monitor)', size: 13, rx: 12 });
    key(svg, light, 'ch16', [400, 755], [432, 776], '16/C', { ariaLabel: '16/C: Kanal 16, 1 Sekunde Anrufkanal', size: 16, rx: 12, fill: 'url(#m94-blue)', ink: '#ffffff' });

    // Steuerkreuz ▲▼◄► als Ring mit ENT in der Mitte
    const [cx, cy] = F(373, 748);
    const R = 44;
    const r = 19;
    el('circle', { cx, cy, r: R + 4, fill: '#0b0c0d' }, svg);
    const wedge = (id, angle, glyph, ariaLabel) => {
        const a1 = (angle - 44) * Math.PI / 180;
        const a2 = (angle + 44) * Math.PI / 180;
        const pt = (rad, a) => [cx + Math.sin(a) * rad, cy - Math.cos(a) * rad];
        const [o1x, o1y] = pt(R, a1);
        const [o2x, o2y] = pt(R, a2);
        const [i2x, i2y] = pt(r + 3, a2);
        const [i1x, i1y] = pt(r + 3, a1);
        const g = control(svg, { 'data-key': id, 'aria-label': ariaLabel });
        const seg = el('path', { d: `M${o1x} ${o1y} A ${R} ${R} 0 0 1 ${o2x} ${o2y} L ${i2x} ${i2y} A ${r + 3} ${r + 3} 0 0 0 ${i1x} ${i1y} Z`, fill: 'url(#m94-key)', stroke: RIM.on, 'stroke-width': 1.6 }, g);
        light.add(seg, { stroke: RIM.on }, { stroke: RIM.off });
        const [gx, gy] = pt((R + r) / 2 + 1, angle * Math.PI / 180);
        const t = el('text', { x: gx, y: gy + 5, 'text-anchor': 'middle', 'font-size': 13, 'font-weight': 700, 'font-family': SANS, fill: LABEL.on, filter: 'url(#m94-glow)', 'pointer-events': 'none' }, g, glyph);
        light.add(t, { fill: LABEL.on, filter: 'url(#m94-glow)' }, { fill: LABEL.off, filter: null });
    };
    wedge('up', 0, '▲', 'Kanal auf / ▲');
    wedge('right', 90, '▶', 'rechts / Softkey-Seite vor');
    wedge('down', 180, '▼', 'Kanal ab / ▼');
    wedge('left', 270, '◀', 'links / Softkey-Seite zurück');
    const enter = control(svg, { 'data-key': 'enter', 'aria-label': 'ENT' });
    el('circle', { cx, cy, r, fill: 'url(#m94-key)', stroke: '#0b0c0d', 'stroke-width': 1.5 }, enter);
    light.add(el('circle', { cx, cy, r: r - 6, fill: '#1d5fbf', 'fill-opacity': 0.85, 'pointer-events': 'none' }, enter), { 'fill-opacity': 0.85 }, { 'fill-opacity': 0.25 });

    // Lautsprecher mit Mikrofon
    for (const py of [803, 817, 831, 845, 859, 873]) {
        const [sx1, sy] = F(306, py);
        const [sx2] = F(441, 0);
        el('rect', { x: sx1, y: sy - 5, width: sx2 - sx1, height: 10, rx: 5, fill: '#0e0f11' }, svg);
        el('line', { x1: sx1 + 5, y1: sy + 6.5, x2: sx2 - 5, y2: sy + 6.5, stroke: '#55585e', 'stroke-opacity': 0.6, 'stroke-width': 1.2 }, svg);
    }
    const [micX, micY] = F(312, 796);
    el('text', { x: micX, y: micY, 'font-size': 10, 'font-weight': 700, fill: '#6b6e74', 'font-family': SANS }, svg, 'MIC');

    // --- Rückseite oben mit DISTRESS ---------------------------------------------
    const [rx0] = B(273, 0);
    const [rx1] = B(455, 0);
    el('text', { x: (rx0 + rx1) / 2, y: 40, 'text-anchor': 'middle', 'font-size': 18, 'font-weight': 700, fill: '#64748b', 'font-family': SANS }, svg, 'Rückseite (oben)');
    const [bax1] = B(312, 0);
    const [bax2] = B(352, 0);
    el('rect', { x: bax1 + 8, y: 66, width: bax2 - bax1 - 16, height: 40, rx: 10, fill: 'url(#m94-antenna)', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    el('path', { d: `M${bax1 + 6} 100 L ${bax2 - 6} 100 L ${bax2} 156 L ${bax1} 156 Z`, fill: 'url(#m94-antenna)', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    const [bkx1, bky1] = B(372, 488);
    const [bkx2] = B(410, 0);
    el('rect', { x: bkx1, y: bky1, width: bkx2 - bkx1, height: 34, rx: 9, fill: 'url(#m94-knob)', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    const [rby] = B(0, 508);
    const [, rbBottom] = B(0, 660);
    el('path', { d: `M${rx0} ${rbBottom} L ${rx0} ${rby + 40} Q ${rx0} ${rby} ${rx0 + 40} ${rby} L ${rx1 - 40} ${rby} Q ${rx1} ${rby} ${rx1} ${rby + 40} L ${rx1} ${rbBottom}`, fill: 'url(#m94-back)', stroke: '#0b0c0d', 'stroke-width': 3 }, svg);
    // Die Ansicht endet hier (Schnittkante gestrichelt).
    el('line', { x1: rx0, y1: rbBottom, x2: rx1, y2: rbBottom, stroke: '#94a3b8', 'stroke-width': 2, 'stroke-dasharray': '8 6' }, svg);
    for (const hx of [300, 420]) {
        const [hcx, hcy] = B(hx, 520);
        el('circle', { cx: hcx, cy: hcy, r: 12, fill: '#111214' }, svg);
    }
    const [fr1x, fr1y] = B(331, 517);
    const [fr2x, fr2y] = B(391, 582);
    el('rect', { x: fr1x, y: fr1y, width: fr2x - fr1x, height: fr2y - fr1y, rx: 8, fill: '#1c1d20', stroke: '#6a6d73', 'stroke-width': 1.5 }, svg);
    const [cl1x, cl1y] = B(352, 586);
    const [cl2x, cl2y] = B(378, 634);
    el('rect', { x: cl1x, y: cl1y, width: cl2x - cl1x, height: cl2y - cl1y, rx: 4, fill: '#141517', stroke: '#55585e', 'stroke-width': 1 }, svg);
    const [db1x, db1y] = B(345, 527);
    const [db2x, db2y] = B(377, 570);
    const distress = control(svg, { 'data-key': 'distress', 'aria-label': 'DISTRESS (3 Sekunden halten)' });
    el('rect', { x: db1x, y: db1y, width: db2x - db1x, height: db2y - db1y, rx: 6, fill: 'url(#m94-red)', stroke: '#5b080d', 'stroke-width': 2 }, distress);
    el('text', { x: (db1x + db2x) / 2, y: (db1y + db2y) / 2 + 5, 'text-anchor': 'middle', 'font-size': 12, 'font-weight': 700, fill: '#ffffff', 'font-family': SANS, 'pointer-events': 'none' }, distress, 'DISTRESS');
    const cover = control(svg, { 'data-key': 'distressCover', 'aria-label': 'Schutzkappe der DISTRESS-Taste' });
    const coverClosed = { y: db1y - 8, height: db2y - db1y + 18 };
    const coverOpen = { y: db1y - 44, height: 30 };
    const coverLid = el('rect', { x: db1x - 6, ...coverClosed, width: db2x - db1x + 12, rx: 8, fill: '#e2333d', 'fill-opacity': 0.55, stroke: '#9b1219', 'stroke-width': 2 }, cover);

    const highlight = el('rect', { fill: 'none', stroke: '#e3b505', 'stroke-width': 4, 'stroke-dasharray': '10 6', rx: 14, visibility: 'hidden', 'pointer-events': 'none' }, svg);

    function text(x, y, value, attrs = {}) {
        el('text', { x, y, 'font-size': 13, ...attrs }, lcd, value);
    }

    function inverted(x, y, w, h, label, size = 11) {
        el('rect', { x, y, width: w, height: h, rx: 2, fill: LCD_INK }, lcd);
        text(x + w / 2, y + h / 2 + size * 0.36, label, { 'text-anchor': 'middle', 'font-size': size, fill: LCD_ON, 'font-weight': 700 });
    }

    function drawSoftkeyBar(d) {
        if (d.locked) {
            inverted(10, 205, 200, 22, '⚿ Key Locked', 12);

            return;
        }
        for (const f of fields(d.softkeys ?? [])) {
            if (! f.label) continue;
            const x = 10 + f.start * 68;
            const w = f.span * 68 - 4;
            inverted(x, 205, w, 22, f.label, f.label.length > 7 ? 9.5 : f.label.length > 5 ? 10.5 : 12);
        }
        if (d.pages) {
            if (d.pages.current > 0) text(1, 221, '◂', { 'font-size': 11 });
            if (d.pages.current < d.pages.count - 1) text(211, 221, '▸', { 'font-size': 11 });
        }
    }

    function drawPanel(p) {
        if (p.title) {
            el('rect', { x: 0, y: 0, width: LCD_W, height: 19, fill: LCD_INK }, lcd);
            text(110, 14, p.title.startsWith('≡') || p.title.startsWith('!!') ? p.title : `≡ ${p.title} ≡`, { 'text-anchor': 'middle', 'font-size': 11, fill: LCD_ON, 'font-weight': 700 });
        }
        let y = 38;
        if (p.note) {
            text(110, y, p.note, { 'text-anchor': 'middle', 'font-size': 10 });
            y += 18;
        }
        if (p.items) {
            const visible = p.note ? 7 : 8;
            const first = Math.max(0, Math.min(p.cursor - 3, p.items.length - visible));
            p.items.slice(first, first + visible).forEach((item, i) => {
                const selected = first + i === p.cursor;
                if (selected) el('rect', { x: 2, y: y - 13, width: 216, height: 17, fill: LCD_INK }, lcd);
                text(6, y, item.length > 30 ? `${item.slice(0, 29)}…` : item, { 'font-size': 11.5, fill: selected ? LCD_ON : LCD_INK });
                y += 18;
            });
        }
        (p.lines ?? []).forEach((line) => {
            text(110, y, line, { 'text-anchor': 'middle', 'font-size': 13, class: p.blink ? 'fg-blink' : '' });
            y += 19;
        });
        if (p.editor) {
            const e = p.editor;
            const width = e.chars.length * 13;
            e.chars.forEach((c, i) => {
                const x = 110 - width / 2 + i * 13;
                text(x, y + 4, c, { 'font-size': 15 });
                if (i === e.cursor) el('rect', { x: x - 1, y: y + 8, width: 11, height: 2.5, fill: LCD_INK }, lcd);
            });
            const rowWidth = e.row.length * 15;
            e.row.forEach((c, i) => {
                const x = 110 - rowWidth / 2 + i * 15;
                if (i === e.sel) el('rect', { x: x - 2, y: y + 18, width: 14, height: 18, fill: LCD_INK }, lcd);
                text(x + 5, y + 32, c, { 'text-anchor': 'middle', 'font-size': 12, fill: i === e.sel ? LCD_ON : LCD_INK });
            });
        }
        if (typeof p.progress === 'number') {
            el('rect', { x: 20, y: 150, width: 180, height: 12, fill: 'none', stroke: LCD_INK, 'stroke-width': 2 }, lcd);
            el('rect', { x: 22, y: 152, width: 176 * p.progress, height: 8, fill: LCD_INK }, lcd);
        }
    }

    function drawDisplay(d) {
        lcd.replaceChildren();
        softkeys.forEach((g, i) => {
            const label = d.softkeys?.[i] ?? '';
            const aria = label ? `Softkey ${i + 1}: ${label}` : `Softkey ${i + 1}`;
            if (g.getAttribute('aria-label') !== aria) g.setAttribute('aria-label', aria);
        });
        light.set(d.on);
        if (! d.on) return;
        const st = d.status;
        drawSoftkeyBar(d);

        if (d.panel) {
            drawPanel(d.panel);

            return;
        }

        // Kopfzeilen: eigene MMSI und Akku, darunter Leistung und Kanalgruppe
        text(4, 13, `MMSI:${d.mmsi ?? '---------'}`, { 'font-size': 11 });
        el('rect', { x: 196, y: 4, width: 18, height: 9, rx: 1.5, fill: 'none', stroke: LCD_INK, 'stroke-width': 1.2 }, lcd);
        el('rect', { x: 198, y: 6, width: 14, height: 5, fill: LCD_INK }, lcd);
        el('rect', { x: 214, y: 6.5, width: 2, height: 4, fill: LCD_INK }, lcd);
        const state = st.tx ? 'TX' : st.busy ? 'BUSY' : st.monitor ? 'MONI' : '';
        if (state) inverted(4, 17, state.length * 8 + 8, 14, state, 10);
        text(110, 29, [st.power, st.group].filter(Boolean).join('  '), { 'text-anchor': 'middle', 'font-size': 12, 'font-weight': 700 });
        if (st.mail) text(176, 29, '✉', { 'font-size': 12 });

        inverted(4, 50, 40, 16, d.mode ?? 'STBY', 10.5);
        if (d.watch) inverted(4, 72, 52, 15, d.watch, 9.5);
        const channel = String(d.channel ?? '');
        el('text', { x: 0, y: 0, transform: 'translate(170 116) scale(0.86 1)', 'text-anchor': 'end', 'font-size': channel.length <= 2 ? 92 : 58, 'font-weight': 700, 'font-family': SANS }, lcd, channel);
        if (st.tag) text(176, 58, '★', { 'font-size': 20 });
        [st.call ? 'CALL' : '', st.dup ? 'DUP' : ''].filter(Boolean).forEach((label, i) => text(216, 80 + i * 15, label, { 'text-anchor': 'end', 'font-size': 10.5, 'font-weight': 700 }));
        text(110, 136, d.channelName ?? '', { 'text-anchor': 'middle', 'font-size': 14, 'font-weight': 700 });
        el('rect', { x: 14, y: 142, width: 192, height: 56, rx: 3, fill: 'none', stroke: LCD_INK, 'stroke-width': 1.3 }, lcd);
        (d.lines ?? []).slice(0, 3).forEach((line, i) => text(110, 158 + i * 17, line, { 'text-anchor': 'middle', 'font-size': 12.5 }));

        if (d.window) {
            el('rect', { x: 12, y: 60, width: 196, height: 76, rx: 5, fill: LCD_ON, stroke: LCD_INK, 'stroke-width': 1.5 }, lcd);
            text(110, 86, `${d.window.label} ${d.window.value}`, { 'text-anchor': 'middle', 'font-size': 15, 'font-weight': 700 });
            el('rect', { x: 24, y: 100, width: 172, height: 12, fill: 'none', stroke: LCD_INK, 'stroke-width': 1.5 }, lcd);
            el('rect', { x: 26, y: 102, width: 168 * d.window.level, height: 8, fill: LCD_INK }, lcd);
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
            Object.entries(radioState.coverOpen ? coverOpen : coverClosed).forEach(([name, value]) => coverLid.setAttribute(name, value));

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

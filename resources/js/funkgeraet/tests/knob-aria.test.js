// Screenreader-Werte an den Drehknöpfen (role="slider"): aria-valuenow,
// -min, -max und -valuetext folgen dem Gerätezustand (WCAG 2.2, 4.1.2).

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { installFakeDom } from './support/fake-dom.js';
import { createIcM505 } from '../devices/icm505.js';
import { createIcM503 } from '../devices/icm503.js';
import { createIcM323 } from '../devices/icm323.js';
import { createIcM330 } from '../devices/icm330.js';
import { renderDevice } from '../ui/device-svg.js';
import { renderDeviceM503 } from '../ui/device-svg-m503.js';
import { renderDeviceM323 } from '../ui/device-svg-m323.js';
import { renderDeviceM330 } from '../ui/device-svg-m330.js';
import { channelAria, setKnobAria } from '../ui/knob-aria.js';

const own = { name: 'KORMORAN', callsign: 'DH4721', mmsi: '211472100', atis: '9211234721' };

function rig(create, render) {
    let now = 0;
    const container = installFakeDom();
    const radio = create({ own, clock: () => now });
    radio.setup({ power: true, vol: 5, sql: 3, channel: 16 });
    const device = render(container);
    const draw = () => device.update(radio.display(), radio.state, null);
    const press = (key, ms = 100) => { radio.keyDown(key); now += ms; radio.tick(); radio.keyUp(key); };
    draw();

    return { radio, svg: device.svg, draw, press, knob: (id) => device.svg.querySelector(`[data-knob="${id}"]`) };
}

const DEVICES = [
    ['IC-M505', createIcM505, renderDevice, 'vol', 10],
    ['IC-M503', createIcM503, renderDeviceM503, 'vol', 10],
    ['IC-M323', createIcM323, (c) => renderDeviceM323(c), 'dial', 20],
    ['IC-M330', createIcM330, renderDeviceM330, 'dial', 20],
];

for (const [name, create, render, volKnob, volMax] of DEVICES) {
    test(`${name}: jeder Drehknopf hat nach dem ersten Zeichnen einen Wert`, () => {
        const { svg } = rig(create, render);
        const sliders = svg.querySelectorAll('[data-knob][role="slider"]');
        assert.ok(sliders.length > 0);
        for (const slider of sliders) {
            assert.notEqual(slider.getAttribute('aria-valuenow'), null, slider.getAttribute('data-knob'));
            assert.notEqual(slider.getAttribute('aria-valuetext'), null);
        }
    });

    test(`${name}: Lautstärke steigt mit dem Drehen um 1`, () => {
        const { radio, draw, knob } = rig(create, render);
        const before = Number(knob(volKnob).getAttribute('aria-valuenow'));
        radio.turn(volKnob, 1);
        draw();
        const node = knob(volKnob);
        assert.equal(Number(node.getAttribute('aria-valuenow')), before + 1);
        assert.equal(node.getAttribute('aria-valuemin'), '0');
        assert.equal(node.getAttribute('aria-valuemax'), String(volMax));
        assert.match(node.getAttribute('aria-valuetext'), new RegExp(`^Lautstärke ${before + 1} von ${volMax}$`));
    });
}

test('IC-M505: Kanalknopf nennt den Kanal', () => {
    const { knob } = rig(createIcM505, renderDevice);
    assert.equal(knob('channel').getAttribute('aria-valuenow'), '16');
    assert.equal(knob('channel').getAttribute('aria-valuetext'), 'Kanal 16');
    assert.equal(knob('sql').getAttribute('aria-valuetext'), 'Rauschsperre 3 von 10');
});

test('IC-M323: Knopfkappe wechselt Lautstärke, Rauschsperre und Kanal', () => {
    const { draw, press, knob } = rig(createIcM323, renderDeviceM323);
    assert.match(knob('dial').getAttribute('aria-valuetext'), /^Lautstärke /);
    press('knob');
    draw();
    assert.match(knob('dial').getAttribute('aria-valuetext'), /^Rauschsperre 3 von 10/);
    press('knob');
    draw();
    assert.equal(knob('dial').getAttribute('aria-valuetext'), 'Kanal 16');
});

test('Ausgeschaltetes Gerät und andere Kanalgruppe werden genannt', () => {
    const aria = channelAria({ power: false, group: 'ATIS', channel: 10 });
    assert.equal(aria.text, 'Kanal 10 (ATIS), Gerät aus');
    assert.ok(aria.min <= 10 && aria.max >= 10);
});

test('Unveränderte Werte werden nicht erneut gesetzt', () => {
    const attrs = {};
    let writes = 0;
    const node = { getAttribute: (n) => attrs[n] ?? null, setAttribute: (n, v) => { writes++; attrs[n] = v; } };
    setKnobAria(node, { now: 1, min: 0, max: 10, text: 'x' });
    setKnobAria(node, { now: 1, min: 0, max: 10, text: 'x' });
    assert.equal(writes, 4);
});

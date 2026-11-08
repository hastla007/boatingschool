// Kleines Ersatz-DOM für node:test: genug, um die Geräte-SVGs zu zeichnen
// und Attribute abzufragen (createElementNS, Attribute, Kinder, einfache
// Attribut-Selektoren). Kein Layout -- getBBox liefert eine feste Box.

class FakeNode {
    constructor(tagName) {
        this.tagName = tagName;
        this.attributes = new Map();
        this.children = [];
        this.parentNode = null;
        this.text = '';
        this.dataset = new Proxy({}, {
            get: (_, key) => this.getAttribute(`data-${String(key).replace(/[A-Z]/g, (c) => `-${c.toLowerCase()}`)}`) ?? undefined,
        });
    }

    setAttribute(name, value) { this.attributes.set(name, String(value)); }
    getAttribute(name) { return this.attributes.has(name) ? this.attributes.get(name) : null; }
    hasAttribute(name) { return this.attributes.has(name); }
    removeAttribute(name) { this.attributes.delete(name); }

    appendChild(child) {
        child.remove?.();
        child.parentNode = this;
        this.children.push(child);

        return child;
    }

    append(...nodes) {
        nodes.forEach((node) => (typeof node === 'string' ? (this.text += node) : this.appendChild(node)));
    }

    replaceChildren(...nodes) {
        this.children.forEach((child) => { child.parentNode = null; });
        this.children = [];
        this.text = '';
        this.append(...nodes);
    }

    remove() {
        if (! this.parentNode) return;
        this.parentNode.children = this.parentNode.children.filter((c) => c !== this);
        this.parentNode = null;
    }

    get textContent() { return this.text + this.children.map((c) => c.textContent).join(''); }
    set textContent(value) { this.replaceChildren(); this.text = String(value); }

    getBBox() { return { x: 0, y: 0, width: 10, height: 10 }; }

    *descendants() {
        for (const child of this.children) {
            yield child;
            yield* child.descendants();
        }
    }

    querySelectorAll(selector) {
        const tests = selector.split(',').map((part) => compile(part.trim()));

        return [...this.descendants()].filter((node) => tests.some((t) => t(node)));
    }

    querySelector(selector) {
        return this.querySelectorAll(selector)[0] ?? null;
    }
}

// Unterstützt: tag, [attr], [attr="wert"] und Kombinationen daraus (ohne Leerzeichen).
function compile(selector) {
    const match = selector.match(/^([a-zA-Z][\w-]*)?((?:\[[^\]]+\])*)$/);
    if (! match) throw new Error(`Selektor nicht unterstützt: ${selector}`);
    const [, tag, attrPart] = match;
    const attrs = [...attrPart.matchAll(/\[([\w-]+)(?:="([^"]*)")?\]/g)].map(([, name, value]) => ({ name, value }));

    return (node) => (! tag || node.tagName === tag)
        && attrs.every(({ name, value }) => (value === undefined ? node.hasAttribute(name) : node.getAttribute(name) === value));
}

export function installFakeDom() {
    globalThis.document = { createElementNS: (_ns, name) => new FakeNode(name) };

    return new FakeNode('div');
}

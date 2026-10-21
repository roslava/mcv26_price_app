const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../public/assets/draft-column-resize.js'), 'utf8');

function setup(saved = null) {
    const values = new Map();
    const storageKey = 'mcv26:column-widths:v1:/admin/:draft-table';
    if (saved !== null) values.set(storageKey, JSON.stringify(saved));
    let writes = 0;
    const storage = {
        getItem(key) { return values.get(key) ?? null; },
        setItem(key, value) { writes++; values.set(key, value); },
        removeItem(key) { values.delete(key); },
    };
    class Element {
        constructor() {
            this.style = {};
            this.dataset = {};
            this.attributes = new Map();
            this.listeners = new Map();
            this.children = [];
            this.captured = null;
            const classes = new Set();
            this.classList = {toggle(name, enabled) { if (enabled) classes.add(name); else classes.delete(name); },
                add(name) { classes.add(name); }, remove(name) { classes.delete(name); },
                contains(name) { return classes.has(name); }};
        }
        addEventListener(type, listener) { this.listeners.set(type, listener); }
        fire(type, values = {}) {
            this.listeners.get(type)?.({preventDefault() {}, ...values});
        }
        setAttribute(name, value) { this.attributes.set(name, value); }
        getAttribute(name) { return this.attributes.get(name) ?? null; }
        append(...children) { this.children.push(...children); }
        prepend(child) { this.group = child; }
        setPointerCapture(id) { this.captured = id; }
        hasPointerCapture(id) { return this.captured === id; }
        releasePointerCapture() { this.captured = null; this.fire('lostpointercapture'); }
    }
    const headers = ['№', 'Код', 'Наименование услуги', 'Начальная цена', 'Текущая цена', '+/- %', ''].map((name) => {
        const header = new Element();
        header.textContent = name;
        if (!name) header.setAttribute('aria-label', 'Действия');
        return header;
    });
    const wrap = {clientWidth: 1200};
    const table = new Element();
    table.tHead = {rows: [{cells: headers}]};
    table.closest = () => wrap;
    const reset = new Element();
    const viewToggle = new Element();
    viewToggle.setAttribute('aria-expanded', 'true');
    viewToggle.click = () => viewToggle.setAttribute('aria-expanded', 'false');
    viewToggle.focus = () => {};
    const basePath = {dataset: {adminBasePath: '/admin/'}};
    const bodyClasses = new Set();
    let resizeCallback;
    class ResizeObserver {
        constructor(callback) { resizeCallback = callback; }
        observe() {}
    }
    const document = {
        body: {classList: {add: (name) => bodyClasses.add(name), remove: (name) => bodyClasses.delete(name)}},
        querySelector(selector) {
            return ({'[data-draft-editor] .draft-table': table, '[data-reset-column-widths]': reset,
                '[data-admin-base-path]': basePath, '[data-menu-view]': viewToggle})[selector] ?? null;
        },
        createElement: () => new Element(),
    };
    vm.runInNewContext(source, {document, window: {localStorage: storage, ResizeObserver}, ResizeObserver});
    return {table, headers, handles: headers.slice(0, -1).map((header) => header.children[0]), reset, storageKey, values,
        bodyClasses, resize(width) { wrap.clientWidth = width; resizeCallback(); },
        get writes() { return writes; }};
}

test('pointer resize transfers width to its neighbor and stores only after pointerup', () => {
    const ui = setup();
    const handle = ui.handles[2];
    const before = Number.parseInt(ui.table.group.children[2].style.width, 10);
    const neighbor = Number.parseInt(ui.table.group.children[3].style.width, 10);
    const total = Number.parseInt(ui.table.style.width, 10);
    handle.fire('pointerdown', {pointerId: 7, pointerType: 'mouse', button: 0, clientX: 100});
    handle.fire('pointermove', {pointerId: 7, clientX: 110});
    assert.equal(Number.parseInt(ui.table.group.children[2].style.width, 10), before + 10);
    assert.equal(Number.parseInt(ui.table.group.children[3].style.width, 10), neighbor - 10);
    assert.equal(Number.parseInt(ui.table.style.width, 10), total);
    assert.equal(ui.writes, 0);
    assert.equal(ui.bodyClasses.has('draft-column-resizing'), true);
    assert.equal(handle.classList.contains('is-dragging'), true);
    handle.fire('pointerup', {pointerId: 7});
    assert.equal(ui.writes, 1);
    assert.equal(ui.bodyClasses.has('draft-column-resizing'), false);
    assert.equal(handle.classList.contains('is-dragging'), false);
    assert.equal(JSON.parse(ui.values.get(ui.storageKey)).version, 2);
    assert.equal(ui.handles.length, 6);
    assert.equal(ui.headers[6].children.length, 0);
    assert.equal(ui.handles.every((item) => item.title === 'Потяните, чтобы изменить ширину'), true);
});

test('pair limits, pointercancel, keyboard, and reset preserve the table width', () => {
    const ui = setup();
    const handle = ui.handles[0];
    const before = Number(handle.getAttribute('aria-valuenow'));
    const neighbor = Number.parseInt(ui.table.group.children[1].style.width, 10);
    handle.fire('pointerdown', {pointerId: 8, pointerType: 'touch', clientX: 100});
    handle.fire('pointermove', {pointerId: 8, clientX: 150});
    assert.equal(Number(handle.getAttribute('aria-valuenow')), before + neighbor - 80);
    handle.fire('pointercancel', {pointerId: 8});
    assert.equal(Number(handle.getAttribute('aria-valuenow')), before);
    assert.equal(ui.writes, 0);
    handle.fire('keydown', {key: 'ArrowRight'});
    assert.equal(Number(handle.getAttribute('aria-valuenow')), before + 10);
    assert.equal(ui.writes, 0);
    handle.fire('keyup', {key: 'ArrowRight'});
    assert.equal(ui.writes, 1);
    ui.reset.fire('click');
    assert.equal(ui.values.has(ui.storageKey), false);
    assert.equal(Number(handle.getAttribute('aria-valuenow')), before);
});

test('legacy widths and container resizing cannot leave a gap or overflow outside compact mode', () => {
    const ui = setup([300, 500, 1800, 500, 500, 400, 240]);
    const widths = () => ui.table.group.children.map((column) => Number.parseInt(column.style.width, 10));
    assert.equal(widths().reduce((sum, width) => sum + width, 0), 1200);
    ui.resize(900);
    assert.equal(widths().reduce((sum, width) => sum + width, 0), 900);
    assert.equal(ui.table.style.width, '900px');
    ui.resize(650);
    assert.equal(widths().reduce((sum, width) => sum + width, 0), 650);
    assert.equal(ui.table.classList.contains('is-compact-columns'), true);
    ui.resize(480);
    assert.equal(widths().reduce((sum, width) => sum + width, 0), 560);
    assert.equal(widths().every((width) => width > 0), true);
    assert.equal(ui.handles.every((handle) => handle.hidden), true);
});

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../public/assets/admin-draft.js'), 'utf8');
const code = source.slice(source.indexOf('    const deleteDialog ='), source.indexOf("    editor.addEventListener('keydown'", source.indexOf('    const deleteDialog =')));
test('moving focus between category menu commands keeps the menu open through the click', async () => {
    const menuCode = source.slice(source.indexOf("    actionMenu.addEventListener('focusout'"), source.indexOf("    document.addEventListener('pointerdown'", source.indexOf("    actionMenu.addEventListener('focusout'")));
    let focusout;
    let closes = 0;
    const nextCommand = {};
    const context = vm.createContext({
        actionMenu: {
            addEventListener(type, handler) { if (type === 'focusout') focusout = handler; },
            contains(element) { return element === nextCommand; },
        },
        document: {activeElement: nextCommand},
        activeActionToggle: {},
        closeActionMenu() { closes++; },
        queueMicrotask,
    });
    vm.runInContext(menuCode, context);
    focusout({relatedTarget: nextCommand});
    await Promise.resolve();
    assert.equal(closes, 0);
});
function setup(kind, options = {}) {
    const listeners = {};
    const elements = {};
    for (const name of ['dialog','title','description','confirm','cancel']) {
        elements[name] = {handlers: {}, textContent: '', addEventListener(type, handler) {this.handlers[type] = handler;}, focus() {}};
    }
    elements.dialog.showModal = () => { elements.dialog.open = true; };
    elements.dialog.close = () => { elements.dialog.open = false; elements.dialog.handlers.close(); };
    const row = {dataset: {serviceId: '1'}, hidden: false, querySelector: () => ({textContent: 'Service'})};
    const pendingRow = {isConnected: true, remove() { this.isConnected = false; removed++; }};
    const existingRows = options.newCategory ? [] : [row];
    const category = {dataset: options.newCategory ? {newCategoryIndex: '0'} : {categoryId: '10', categoryName: 'Category'},
        hidden: false, querySelectorAll: () => existingRows, querySelector: () => ({textContent: 'Category'}),
        remove() { this.isConnected = false; }};
    const toggle = {closest: (selector) => selector === '.draft-category' ? category
        : selector === '[data-pending-service]' ? pendingRow : row, focus() {}};
    const button = {matches: (selector) => selector === '[data-delete-' + kind + ']'};
    const actionSources = new WeakMap([[button, toggle]]);
    let updates = 0, backups = 0, removed = 0;
    const newServices = options.onlyExisting || options.newCategory ? []
        : [{category_id: '10', name: 'Pending', row: pendingRow}, {category_id: '20'}];
    const newCategories = options.newCategory
        ? [{name: 'Category', services: [{name: 'Pending', row: pendingRow}], removed: false}] : [];
    const context = vm.createContext({
        editor: {querySelector(selector) {
            if (selector === '.draft-table-wrap') return {focus() {}};
            return elements[selector.match(/data-delete-(\w+)/)[1]];
        }, contains: () => false},
        document: {addEventListener(type, handler) {listeners[type] = handler;}},
        actionMenu: {contains: () => true},
        actionSources, activeActionToggle: null, closeActionMenu() {}, openActionMenu() {},
        isSaving: false, deletedServiceIds: new Set(options.onlyExisting || options.newCategory ? [] : ['1']), deletedCategoryIds: new Set(),
        newServices, newCategories, activeNewCategories: () => newCategories.filter((item) => !item.removed),
        update() {updates++;}, flushRecovery() {backups++;},
    });
    vm.runInContext(code, context);
    return {context, elements, row, pendingRow, category, existingRows, get updates() {return updates;}, get backups() {return backups;},
        get removed() {return removed;}, click: () => listeners.click({target: {closest: () => button}})};
}
test('delete entire existing category after confirmation, including pending services', async () => {
    const flow = setup('category');
    const pending = flow.click();
    assert.equal(flow.elements.dialog.open, true);
    assert.equal(flow.category.hidden, false);
    flow.elements.confirm.handlers.click();
    await pending;
    assert.equal(flow.category.hidden, true);
    assert.equal(flow.category.dataset.structurallyDeleted, 'true');
    assert.equal(flow.row.dataset.structurallyDeleted, 'category');
    assert.equal(flow.context.deletedCategoryIds.has('10'), true);
    assert.equal(flow.context.deletedServiceIds.has('1'), false);
    assert.equal(flow.context.newServices.length, 1);
    assert.equal(flow.removed, 1);
    assert.equal(flow.backups, 1);
});
test('cancel category deletion leaves the draft untouched', async () => {
    const flow = setup('category');
    const pending = flow.click();
    flow.elements.cancel.handlers.click();
    await pending;
    assert.equal(flow.category.hidden, false);
    assert.equal(flow.context.deletedCategoryIds.size, 0);
    assert.equal(flow.removed, 0);
    assert.equal(flow.updates, 0);
});
test('service deletion requires confirmation and immediately backs up the edit', async () => {
    const flow = setup('service');
    const pending = flow.click();
    assert.equal(flow.row.hidden, false);
    flow.elements.confirm.handlers.click();
    await pending;
    assert.equal(flow.row.hidden, true);
    assert.equal(flow.row.dataset.structurallyDeleted, 'true');
    assert.equal(flow.backups, 1);
});
test('Escape/native dialog close cancels service deletion', async () => {
    const flow = setup('service');
    const pending = flow.click();
    flow.elements.dialog.close();
    await pending;
    assert.equal(flow.row.hidden, false);
    assert.equal(flow.updates, 0);
});

test('last existing service deletes its category with one confirmation and no service ID', async () => {
    const flow = setup('service', {onlyExisting: true});
    const pending = flow.click();
    assert.equal(flow.elements.description.textContent,
        'Это последняя услуга в разделе „Category“. Удалить услугу вместе с разделом?');
    assert.equal(flow.category.hidden, false);
    flow.elements.confirm.handlers.click();
    await pending;
    assert.equal(flow.category.hidden, true);
    assert.equal(flow.context.deletedCategoryIds.has('10'), true);
    assert.equal(flow.context.deletedServiceIds.size, 0);
    assert.equal(flow.row.dataset.structurallyDeleted, 'category');
    assert.equal(flow.backups, 1);
});

test('cancel and Escape leave a last existing service and category intact', async () => {
    for (const action of ['cancel', 'close']) {
        const flow = setup('service', {onlyExisting: true});
        const pending = flow.click();
        if (action === 'cancel') flow.elements.cancel.handlers.click();
        else flow.elements.dialog.close();
        await pending;
        assert.equal(flow.category.hidden, false);
        assert.equal(flow.context.deletedCategoryIds.size, 0);
        assert.equal(flow.context.deletedServiceIds.size, 0);
        assert.equal(flow.updates, 0);
    }
});

test('existing service keeps its category when a pending service remains', async () => {
    const flow = setup('service');
    flow.context.deletedServiceIds.clear();
    const pending = flow.click();
    assert.equal(flow.elements.description.textContent.includes('последняя услуга'), false);
    flow.elements.confirm.handlers.click();
    await pending;
    assert.equal(flow.context.deletedServiceIds.has('1'), true);
    assert.equal(flow.context.deletedCategoryIds.size, 0);
    assert.equal(flow.category.hidden, false);
});

test('last pending service in an existing category clears earlier service deletions', async () => {
    const flow = setup('pending-service');
    flow.row.dataset.structurallyDeleted = 'true';
    const pending = flow.click();
    assert.equal(flow.elements.description.textContent.includes('последняя услуга'), true);
    flow.elements.confirm.handlers.click();
    await pending;
    assert.equal(flow.category.hidden, true);
    assert.equal(flow.context.deletedServiceIds.size, 0);
    assert.equal(flow.context.deletedCategoryIds.has('10'), true);
    assert.equal(flow.context.newServices.length, 1);
    assert.equal(flow.pendingRow.isConnected, false);
});

test('last pending service in a new category removes the unsaved category', async () => {
    const flow = setup('pending-service', {newCategory: true});
    const pending = flow.click();
    assert.equal(flow.elements.description.textContent.includes('последняя услуга'), true);
    flow.elements.confirm.handlers.click();
    await pending;
    assert.equal(flow.context.newCategories[0].removed, true);
    assert.equal(flow.context.newCategories[0].services.length, 0);
    assert.equal(flow.category.isConnected, false);
    assert.equal(flow.context.deletedCategoryIds.size, 0);
    assert.equal(flow.backups, 1);
});

test('filter keeps a category visible when its only remaining service is pending', () => {
    const start = source.indexOf('    const filterServices = () => {');
    const filterCode = source.slice(start, source.indexOf("    searchInput.addEventListener('input'", start));
    const existing = {dataset: {structurallyDeleted: 'true'}, hidden: true};
    const pending = {hidden: false};
    const category = {
        dataset: {}, hidden: false,
        querySelectorAll(selector) { return selector === '[data-price-row]' ? [existing] : [pending]; },
    };
    const context = vm.createContext({
        searchInput: {value: ''}, searchClear: {}, searchEmpty: {hidden: true},
        searchableRows: [{row: existing, text: 'service'}], categories: [category],
        activeNewCategories: () => [], changedOnly: false, hasChangedPrice: () => false,
        scheduleRecovery() {},
    });
    vm.runInContext(filterCode + '\nfilterServices();', context);
    assert.equal(category.hidden, false);
    assert.equal(context.searchEmpty.hidden, true);
});

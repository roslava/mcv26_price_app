const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../public/assets/admin-draft.js'), 'utf8');
const exitStart = source.indexOf('    const setExitDialogMessage =');
const exitEnd = source.indexOf("    publishButton.addEventListener('click'", exitStart);
const unloadStart = source.indexOf("    window.addEventListener('beforeunload'");
const unloadEnd = source.indexOf('\n    });', unloadStart) + '\n    });'.length;
assert.ok(exitStart >= 0 && exitEnd > exitStart && unloadStart >= 0 && unloadEnd > unloadStart);
const exitCode = source.slice(exitStart, exitEnd) + source.slice(unloadStart, unloadEnd);

function element() {
    return {
        handlers: {},
        disabled: false,
        addEventListener(type, handler) { this.handlers[type] = handler; },
        emit(type, event = {}) { return this.handlers[type]?.(event); },
    };
}

function setup({dirty = false, invalid = 0, save = async () => true} = {}) {
    const assigned = [];
    const menuChanges = [];
    const exitLink = element();
    exitLink.href = 'https://example.test/subpath/admin/';
    exitLink.focus = () => { exitLink.focused = true; };
    const exitDialog = element();
    exitDialog.showModal = () => { exitDialog.open = true; };
    exitDialog.close = () => {
        exitDialog.open = false;
        exitDialog.emit('close');
    };
    const exitDialogMessage = {
        textContent: '', hidden: true,
        classList: {toggle() {}},
    };
    const exitSaveButton = element();
    const exitDiscardButton = element();
    const exitCancelButton = element();
    const window = {
        handlers: {},
        addEventListener(type, handler) { this.handlers[type] = handler; },
        location: {assign(url) { assigned.push(url); }},
    };
    const context = vm.createContext({
        exitLink, exitDialog, exitDialogMessage, exitSaveButton, exitDiscardButton,
        exitCancelButton, window, saveMessage: {textContent: ''},
        hasValidUnsavedChanges: dirty, invalidCount: invalid,
        isSaving: false, isExitSaving: false, isExitConfirmed: false,
        flushRecovery() {}, clearRecovery() {},
        update() {}, saveDraft: save,
        setDocumentMenuOpen(open) { menuChanges.push(open); },
    });
    vm.runInContext(exitCode, context);
    const clickBack = () => {
        const event = {
            defaultPrevented: false, button: 0,
            preventDefault() { this.defaultPrevented = true; },
        };
        exitLink.emit('click', event);
        return event;
    };
    const beforeUnload = () => {
        const event = {preventDefault() { this.prevented = true; }};
        window.handlers.beforeunload(event);
        return event;
    };
    return {context, exitLink, exitDialog, exitDialogMessage, exitSaveButton,
        exitDiscardButton, exitCancelButton, assigned, menuChanges, clickBack, beforeUnload};
}

test('clean editor follows the existing link immediately', () => {
    const flow = setup();
    assert.equal(flow.clickBack().defaultPrevented, false);
    assert.equal(flow.exitDialog.open, undefined);
    assert.deepEqual(flow.menuChanges, [false]);
    assert.equal(flow.beforeUnload().prevented, undefined);
});

test('cancel and Escape close the dialog and restore focus', () => {
    const flow = setup({dirty: true});
    assert.equal(flow.clickBack().defaultPrevented, true);
    flow.exitCancelButton.emit('click');
    assert.equal(flow.exitDialog.open, false);
    assert.equal(flow.exitLink.focused, true);
    assert.deepEqual(flow.menuChanges, [false, true]);
    flow.clickBack();
    const cancel = {preventDefault() { this.prevented = true; }};
    flow.exitDialog.emit('cancel', cancel);
    if (!cancel.prevented) flow.exitDialog.close();
    assert.equal(flow.exitDialog.open, false);
    assert.equal(flow.beforeUnload().prevented, true);
});

test('discard leaves through the actual link without another warning', () => {
    const flow = setup({dirty: true});
    flow.clickBack();
    flow.exitDiscardButton.emit('click');
    assert.deepEqual(flow.assigned, [flow.exitLink.href]);
    assert.equal(flow.beforeUnload().prevented, undefined);
});

test('successful save navigates once and skips structural reload', async () => {
    const options = [];
    const flow = setup({dirty: true, save: async (value) => { options.push(value); return true; }});
    flow.clickBack();
    await flow.exitSaveButton.emit('click');
    assert.equal(options.length, 1);
    assert.equal(options[0].reloadOnStructure, false);
    assert.deepEqual(flow.assigned, [flow.exitLink.href]);
    assert.equal(flow.beforeUnload().prevented, undefined);
});

test('failed save keeps edits and permits retry or discard', async () => {
    const flow = setup({dirty: true, save: async () => {
        flow.context.saveMessage.textContent = 'Не удалось сохранить изменения.';
        return false;
    }});
    flow.clickBack();
    await flow.exitSaveButton.emit('click');
    assert.equal(flow.exitDialog.open, true);
    assert.deepEqual(flow.assigned, []);
    assert.equal(flow.exitDialogMessage.textContent, 'Не удалось сохранить изменения.');
    assert.equal(flow.exitSaveButton.disabled, false);
    assert.equal(flow.exitDiscardButton.disabled, false);
    assert.equal(flow.beforeUnload().prevented, true);
});

test('invalid price blocks save but allows cancel and discard', () => {
    const flow = setup({invalid: 1});
    flow.clickBack();
    assert.equal(flow.exitDialog.open, true);
    assert.equal(flow.exitSaveButton.disabled, true);
    assert.match(flow.exitDialogMessage.textContent, /Некорректных цен: 1/);
    assert.equal(flow.exitDiscardButton.disabled, false);
    assert.equal(flow.exitCancelButton.disabled, false);
    assert.equal(flow.beforeUnload().prevented, true);
});

test('structural changes use the save response before leaving and prevent duplicate requests', async () => {
    let resolveSave;
    let calls = 0;
    const flow = setup({dirty: true, save: () => {
        calls++;
        return new Promise((resolve) => { resolveSave = resolve; });
    }});
    flow.clickBack();
    const saving = flow.exitSaveButton.emit('click');
    await flow.exitSaveButton.emit('click');
    assert.equal(calls, 1);
    assert.equal(flow.exitDiscardButton.disabled, true);
    resolveSave(true);
    await saving;
    assert.deepEqual(flow.assigned, [flow.exitLink.href]);
});

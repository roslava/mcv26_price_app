const {test} = require('node:test');
const assert = require('node:assert/strict');
const {validate, create} = require('../../public/assets/draft-recovery.js');
const services = new Set(['1', '2']);
const categories = new Set(['10']);
const service = () => ({service_number: '3', code: 'A', name: 'Новая услуга', current_price_minor: '12500'});
const state = () => ({
    schema_version: 1, version_id: '29', revision: '4', updated_at: new Date().toISOString(),
    prices: [{service_id: '1', price: '12,', percent: '-'}],
    deleted_service_ids: ['2'], deleted_category_ids: [],
    new_services: [{...service(), category_id: '10'}],
    new_categories: [{name: 'Новый раздел', services: [service()]}],
});
const check = (data, revision = '4') => validate(data, '29', revision, services, categories);
test('mixed price and structural edits, including incomplete input, survive JSON round trip', () => {
    const data = state();
    const copy = JSON.parse(JSON.stringify(data));
    assert.equal(check(copy), 'ready');
    assert.deepEqual(copy, data);
});
test('changed server revision prevents recovery', () => assert.equal(check(state(), '5'), 'conflict'));
test('other document and unknown schemas cannot be restored', () => {
    assert.equal(check({...state(), version_id: '30'}), 'invalid');
    assert.equal(check({...state(), schema_version: 2}), 'invalid');
});
test('unknown IDs, duplicate edits and invalid structures are rejected before DOM changes', () => {
    for (const data of [
        {...state(), deleted_service_ids: ['999']},
        {...state(), deleted_category_ids: ['999']},
        {...state(), prices: [...state().prices, ...state().prices]},
        {...state(), new_categories: [{name: 'Empty', services: []}]},
        {...state(), new_services: [{...service(), category_id: '999'}]},
        {...state(), new_services: [{...service(), category_id: '10'}], deleted_category_ids: ['10']},
    ]) assert.equal(check(data), 'invalid');
});
test('storage round trip and explicit clearing leave other drafts untouched', () => {
    const data = new Map();
    const storage = () => ({
        getItem: (key) => data.get(key) ?? null,
        setItem: (key, value) => data.set(key, value),
        removeItem: (key) => data.delete(key),
    });
    const a = create(storage, 'draft:29', assert.fail);
    const b = create(storage, 'draft:30', assert.fail);
    a.write(state()); b.write({...state(), version_id: '30'});
    assert.equal(check(a.read()), 'ready');
    a.clear();
    assert.equal(a.read(), null);
    assert.equal(b.read().version_id, '30');
});
test('storage restrictions and quota failures warn once and never break editor', () => {
    let warnings = 0;
    const store = create(() => { throw new Error('SecurityError'); }, 'draft', () => warnings++);
    assert.equal(store.read(), null);
    store.write(state()); store.clear();
    assert.equal(warnings, 1);
    const full = create(() => ({setItem() {throw new Error('QuotaExceededError');}}), 'draft', () => warnings++);
    assert.doesNotThrow(() => full.write(state()));
    assert.equal(warnings, 2);
});
test('malformed local JSON is reported as invalid data', () => {
    const store = create(() => ({getItem: () => '{broken'}), 'draft', assert.fail);
    assert.equal(check(store.read()), 'invalid');
});

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const editorSource = fs.readFileSync(path.join(__dirname, '../../public/assets/admin-draft.js'), 'utf8');
const captureCode = editorSource.slice(editorSource.indexOf('    function captureRecovery()'), editorSource.indexOf('    function flushRecovery()'));
const restoreCode = editorSource.slice(editorSource.indexOf('    function restoreRecovery(data)'), editorSource.indexOf('    function initializeRecovery()'));
test('editor restores mixed structural edits and captures data without DOM references', () => {
    const row1 = {dataset: {serviceId: '1'}};
    const row2 = {dataset: {serviceId: '2'}};
    const row3 = {dataset: {serviceId: '3'}};
    const cat1 = {dataset: {categoryId: '10'}, querySelectorAll: () => [row1, row2]};
    const cat2 = {dataset: {categoryId: '20'}, querySelectorAll: () => [row3]};
    const added = [];
    const context = vm.createContext({
        editor: {dataset: {versionId: '29', revision: '4'}},
        rows: [row1, row2, row3], categories: [cat1, cat2],
        inputs: [{value: '100'}, {value: '100'}, {value: '100'}],
        percentInputs: [{value: '0'}, {value: '0'}, {value: '0'}],
        baselineFields: new Map(['1','2','3'].map((id) => [id, {price: '100', percent: '0'}])),
        rawPercentEdits: new Map(), deletedServiceIds: new Set(), deletedCategoryIds: new Set(),
        newServices: [], newCategories: [],
        update() {},
        appendPendingServiceRow(category, service) { added.push(category.dataset.categoryId); service.row = {dom: true}; },
        appendPendingCategory(category) { category.body = {dom: true}; },
    });
    vm.runInContext(`
        const activeNewCategories = () => newCategories.filter((item) => !item.removed);
        const cleanService = ({service_number, code, name, current_price_minor, category_id}) => ({
            service_number, code, name, current_price_minor, ...(category_id ? {category_id} : {}),
        });
    ` + captureCode + restoreCode, context);
    context.saved = {...state(), deleted_category_ids: ['20']};
    vm.runInContext('restoreRecovery(saved); result = captureRecovery();', context);
    assert.equal(context.inputs[0].value, '12,');
    assert.equal(context.percentInputs[0].value, '-');
    assert.equal(context.rawPercentEdits.get(0), '-');
    assert.equal(row2.dataset.structurallyDeleted, 'true');
    assert.equal(row3.dataset.structurallyDeleted, 'category');
    assert.equal(cat2.dataset.structurallyDeleted, 'true');
    assert.deepEqual(added, ['10']);
    const result = JSON.parse(JSON.stringify(context.result));
    assert.equal(result.new_services[0].row, undefined);
    assert.equal(result.new_categories[0].body, undefined);
    assert.deepEqual(result.new_categories[0].services, [service()]);
    assert.equal(check({...result, deleted_category_ids: []}), 'ready');
});

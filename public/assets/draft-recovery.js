/* Browser-only recovery; never contains credentials or document HTML. */
(function (root) {
    'use strict';
    const text = (value, limit = 2048) => typeof value === 'string' && value.length <= limit;
    const id = (value) => typeof value === 'string' && /^[1-9]\d*$/.test(value);
    const list = (value) => Array.isArray(value) && value.length <= 5000;
    const service = (value) => value && id(value.service_number)
        && text(value.code, 191) && value.code.trim() !== ''
        && text(value.name, 1024) && value.name.trim() !== ''
        && id(value.current_price_minor) && value.current_price_minor.length <= 19;
    function validate(data, versionId, revision, serviceIds, categoryIds) {
        if (!data || data.schema_version !== 1 || data.version_id !== versionId
            || !text(data.revision, 32) || !text(data.updated_at, 64)
            || !list(data.prices) || !list(data.deleted_service_ids) || !list(data.deleted_category_ids)
            || !list(data.new_services) || !list(data.new_categories)) return 'invalid';
        const uniqueKnown = (values, known) => new Set(values).size === values.length
            && values.every((value) => id(value) && known.has(value));
        if (!uniqueKnown(data.deleted_service_ids, serviceIds)
            || !uniqueKnown(data.deleted_category_ids, categoryIds)
            || new Set(data.prices.map((entry) => entry?.service_id)).size !== data.prices.length
            || !data.prices.every((entry) => entry && serviceIds.has(entry.service_id)
                && text(entry.price, 128) && text(entry.percent, 128))
            || !data.new_services.every((entry) => service(entry) && categoryIds.has(entry.category_id)
                && !data.deleted_category_ids.includes(entry.category_id))
            || !data.new_categories.every((entry) => entry && text(entry.name, 512)
                && entry.name.trim() !== '' && list(entry.services) && entry.services.length > 0
                && entry.services.every(service))) return 'invalid';
        return data.revision === revision ? 'ready' : 'conflict';
    }
    function create(storage, key, onError) {
        let failed = false;
        const attempt = (operation, fallback) => {
            if (failed) return fallback;
            try { return operation(); } catch {
                failed = true;
                onError();
                return fallback;
            }
        };
        return {
            read: () => attempt(() => {
                const raw = storage().getItem(key);
                if (raw === null) return null;
                try { return JSON.parse(raw); } catch { return {schema_version: -1}; }
            }, null),
            write: (value) => attempt(() => storage().setItem(key, JSON.stringify(value))),
            clear: () => attempt(() => storage().removeItem(key)),
        };
    }
    const api = {validate, create};
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.DraftRecovery = api;
})(globalThis);

(() => {
    'use strict';

    const table = document.querySelector('[data-draft-editor] .draft-table');
    const reset = document.querySelector('[data-reset-column-widths]');
    if (!table || !reset) return;

    const wrap = table.closest('.draft-table-wrap');
    const headers = [...table.tHead.rows[0].cells];
    const normalMinimums = [46, 80, 220, 112, 112, 94, 64];
    const compactMinimums = [38, 64, 150, 88, 88, 76, 56];
    if (headers.length !== normalMinimums.length) return;

    const basePath = document.querySelector('[data-admin-base-path]')?.dataset.adminBasePath || '/admin/';
    const storageKey = `mcv26:column-widths:v1:${basePath}:draft-table`;
    const sum = (values) => values.reduce((total, value) => total + value, 0);
    const proportions = (values) => values.map((value) => value / sum(values));
    const defaultProportions = (available) => {
        const fixed = [52, 90, 125, 125, 110, 70];
        const name = Math.max(240, available - sum(fixed));
        return proportions([fixed[0], fixed[1], name, ...fixed.slice(2)]);
    };
    const readSaved = () => {
        try {
            const saved = JSON.parse(window.localStorage.getItem(storageKey));
            const values = Array.isArray(saved) ? saved : saved?.version === 2 ? saved.proportions : null;
            if (!Array.isArray(values) || values.length !== headers.length) return null;
            const valid = Array.isArray(saved)
                ? values.every((value) => Number.isInteger(value) && value > 0 && value <= 10000)
                : values.every((value) => Number.isFinite(value) && value > 0 && value <= 1);
            return valid ? proportions(values) : null;
        } catch (_) {
            return null;
        }
    };
    const save = () => {
        try { window.localStorage.setItem(storageKey, JSON.stringify({version: 2, proportions: weights})); }
        catch (_) { /* Widths remain usable without storage. */ }
    };
    const clearSaved = () => {
        try { window.localStorage.removeItem(storageKey); } catch (_) { /* Widths remain usable. */ }
    };

    // Allocate every pixel, fixing columns at their minimum before dividing the rest.
    const fit = (target, minimums, ratios) => {
        const result = Array(ratios.length).fill(0);
        const active = new Set(ratios.map((_, index) => index));
        let remaining = target;
        while (active.size) {
            const weight = sum([...active].map((index) => ratios[index]));
            const constrained = [...active].filter((index) => remaining * ratios[index] / weight < minimums[index]);
            if (constrained.length === 0) break;
            constrained.forEach((index) => {
                result[index] = minimums[index];
                remaining -= minimums[index];
                active.delete(index);
            });
        }
        if (active.size) {
            const weight = sum([...active].map((index) => ratios[index]));
            const fractions = [...active].map((index) => {
                const exact = remaining * ratios[index] / weight;
                result[index] = Math.floor(exact);
                return {index, fraction: exact - result[index]};
            }).sort((a, b) => b.fraction - a.fraction);
            let leftover = target - sum(result);
            for (let index = 0; index < leftover; index++) result[fractions[index].index]++;
        }
        return result;
    };

    const group = document.createElement('colgroup');
    const columns = headers.map(() => document.createElement('col'));
    group.append(...columns);
    table.prepend(group);
    const handles = [];
    let weights = readSaved() || defaultProportions(wrap.clientWidth);
    let widths = [];
    let minimums = normalMinimums;
    let availableWidth = 0;
    let targetWidth = 0;
    let drag = null;
    let keyboardDirty = false;

    const apply = () => {
        columns.forEach((column, index) => { column.style.width = `${widths[index]}px`; });
        table.style.width = `${targetWidth}px`;
        handles.forEach((handle, index) => {
            handle.hidden = widths[index] + widths[index + 1] <= minimums[index] + minimums[index + 1];
            handle.setAttribute('aria-valuemin', String(minimums[index]));
            handle.setAttribute('aria-valuemax', String(widths[index] + widths[index + 1] - minimums[index + 1]));
            handle.setAttribute('aria-valuenow', String(widths[index]));
            handle.setAttribute('aria-valuetext', `${widths[index]} пикселей`);
        });
    };
    const layout = () => {
        const available = wrap.clientWidth;
        if (available <= 0) return;
        availableWidth = available;
        const compact = available < sum(normalMinimums);
        minimums = compact ? compactMinimums : normalMinimums;
        targetWidth = Math.max(available, sum(minimums));
        table.classList.toggle('is-compact-columns', compact);
        widths = fit(targetWidth, minimums, weights);
        apply();
    };
    const commit = () => {
        weights = proportions(widths);
        save();
    };
    const finishDrag = (cancelled) => {
        if (!drag) return;
        const {index, pointerId, handle, left, right} = drag;
        drag = null;
        if (cancelled) {
            widths[index] = left;
            widths[index + 1] = right;
            apply();
        } else if (widths[index] !== left) commit();
        document.body.classList.remove('draft-column-resizing');
        handle.classList.remove('is-dragging');
        if (handle.hasPointerCapture(pointerId)) handle.releasePointerCapture(pointerId);
    };
    layout();

    headers.slice(0, -1).forEach((header, index) => {
        header.dataset.resizableColumn = '';
        const label = header.textContent.trim() || header.getAttribute('aria-label') || `Колонка ${index + 1}`;
        const handle = document.createElement('button');
        handle.type = 'button';
        handle.className = 'draft-column-resizer';
        handle.setAttribute('role', 'separator');
        handle.setAttribute('aria-orientation', 'vertical');
        handle.setAttribute('aria-label', `Ширина колонки «${label}»`);
        handle.title = 'Потяните, чтобы изменить ширину';
        header.append(handle);
        handles.push(handle);
        apply();

        handle.addEventListener('pointerdown', (event) => {
            if (drag || (event.pointerType === 'mouse' && event.button !== 0)) return;
            event.preventDefault();
            drag = {index, pointerId: event.pointerId, handle, startX: event.clientX,
                left: widths[index], right: widths[index + 1]};
            handle.setPointerCapture(event.pointerId);
            document.body.classList.add('draft-column-resizing');
            handle.classList.add('is-dragging');
        });
        handle.addEventListener('pointermove', (event) => {
            if (!drag || drag.handle !== handle || drag.pointerId !== event.pointerId) return;
            event.preventDefault();
            const lower = minimums[index] - drag.left;
            const upper = drag.right - minimums[index + 1];
            const delta = Math.max(lower, Math.min(upper, event.clientX - drag.startX));
            widths[index] = drag.left + delta;
            widths[index + 1] = drag.right - delta;
            apply();
        });
        handle.addEventListener('pointerup', (event) => {
            if (drag?.pointerId === event.pointerId) finishDrag(false);
        });
        handle.addEventListener('pointercancel', (event) => {
            if (drag?.pointerId === event.pointerId) finishDrag(true);
        });
        handle.addEventListener('lostpointercapture', () => finishDrag(false));

        const finishKeyboard = () => {
            if (!keyboardDirty) return;
            keyboardDirty = false;
            commit();
        };
        handle.addEventListener('keydown', (event) => {
            const direction = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
            if (!direction) return;
            event.preventDefault();
            const delta = direction * (event.shiftKey ? 1 : 10);
            const moved = Math.max(minimums[index] - widths[index],
                Math.min(widths[index + 1] - minimums[index + 1], delta));
            if (!moved) return;
            widths[index] += moved;
            widths[index + 1] -= moved;
            keyboardDirty = true;
            apply();
        });
        handle.addEventListener('keyup', (event) => {
            if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') finishKeyboard();
        });
        handle.addEventListener('blur', finishKeyboard);
    });

    if ('ResizeObserver' in window) {
        new ResizeObserver(() => {
            if (wrap.clientWidth === availableWidth) return;
            finishDrag(true);
            if (keyboardDirty) {
                keyboardDirty = false;
                commit();
            }
            layout();
        }).observe(wrap);
    } else window.addEventListener('resize', layout);

    reset.addEventListener('click', () => {
        finishDrag(true);
        keyboardDirty = false;
        weights = defaultProportions(wrap.clientWidth);
        layout();
        clearSaved();
        const viewToggle = document.querySelector('[data-menu-view]');
        if (viewToggle?.getAttribute('aria-expanded') === 'true') viewToggle.click();
        viewToggle?.focus();
    });
})();

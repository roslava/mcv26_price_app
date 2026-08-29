(() => {
    'use strict';

    const editor = document.querySelector('[data-draft-editor]');
    if (!editor) return;

    const MAX_MINOR = 9223372036854775807n;
    const rows = Array.from(editor.querySelectorAll('[data-price-row]'));
    const inputs = rows.map((row) => row.querySelector('.price-input'));
    const saveButton = editor.querySelector('[data-save-prices]');
    const downloadButton = editor.querySelector('[data-download-xlsx]');
    const saveMessage = editor.querySelector('[data-save-message]');
    const exportDialog = editor.querySelector('[data-export-dialog]');
    const saveDownloadButton = editor.querySelector('[data-save-download]');
    const downloadSavedButton = editor.querySelector('[data-download-saved]');
    const exportDialogText = editor.querySelector('[data-export-dialog-text]');
    const summary = {
        changed: editor.querySelector('[data-summary-changed]'),
        increased: editor.querySelector('[data-summary-increased]'),
        decreased: editor.querySelector('[data-summary-decreased]'),
        original: editor.querySelector('[data-summary-original]'),
        current: editor.querySelector('[data-summary-current]'),
        percent: editor.querySelector('[data-summary-percent]'),
        state: editor.querySelector('[data-draft-state]'),
    };
    let hasValidUnsavedChanges = false;
    let invalidCount = 0;
    let isSaving = false;

    function parseMinor(text) {
        const normalized = text.trim().replace(',', '.');
        const match = /^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/.exec(normalized);
        if (!match) return null;
        const fraction = (match[2] || '').padEnd(2, '0');
        const minor = BigInt(match[1]) * 100n + BigInt(fraction || '0');
        return minor > 0n && minor <= MAX_MINOR ? minor : null;
    }

    function decimal(minor) {
        const whole = minor / 100n;
        const fraction = minor % 100n;
        return fraction === 0n ? String(whole) : `${whole},${String(fraction).padStart(2, '0')}`;
    }

    function money(minor) {
        const whole = String(minor / 100n).replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0');
        const fraction = minor % 100n;
        return `${whole}${fraction === 0n ? '' : `,${String(fraction).padStart(2, '0')}`}\u00a0₽`;
    }

    function roundedDivide(numerator, denominator) {
        const negative = numerator < 0n;
        const absolute = negative ? -numerator : numerator;
        const result = (absolute + denominator / 2n) / denominator;
        return negative ? -result : result;
    }

    function percent(current, imported) {
        const hundredths = roundedDivide((current - imported) * 10000n, imported);
        const sign = hundredths > 0n ? '+' : hundredths < 0n ? '−' : '';
        const absolute = hundredths < 0n ? -hundredths : hundredths;
        const fraction = String(absolute % 100n).padStart(2, '0').replace(/0+$/, '');
        return `${sign}${absolute / 100n}${fraction ? `,${fraction}` : ''}%`;
    }

    function update() {
        let originalTotal = 0n;
        let currentTotal = 0n;
        let changed = 0;
        let increased = 0;
        let decreased = 0;
        let invalid = 0;

        rows.forEach((row, index) => {
            const imported = BigInt(row.dataset.importedMinor);
            const loaded = BigInt(row.dataset.loadedMinor);
            const input = inputs[index];
            const current = parseMinor(input.value);
            originalTotal += imported;
            row.classList.remove('price-increased', 'price-decreased', 'price-unchanged', 'price-invalid');
            input.removeAttribute('aria-invalid');

            if (current === null) {
                invalid++;
                row.classList.add('price-invalid');
                input.setAttribute('aria-invalid', 'true');
                row.querySelector('[data-row-percent]').textContent = 'Ошибка';
                return;
            }
            currentTotal += current;
            row.querySelector('[data-row-percent]').textContent = percent(current, imported);
            if (current > imported) {
                increased++;
                row.classList.add('price-increased');
            } else if (current < imported) {
                decreased++;
                row.classList.add('price-decreased');
            } else {
                row.classList.add('price-unchanged');
            }
            if (current !== loaded) changed++;
        });

        // Invalid rows do not count as changes, but must not suppress a warning for other valid edits.
        hasValidUnsavedChanges = changed > 0;
        invalidCount = invalid;
        summary.changed.textContent = String(changed);
        summary.increased.textContent = String(increased);
        summary.decreased.textContent = String(decreased);
        summary.original.textContent = money(originalTotal);
        summary.current.textContent = invalid === 0 ? money(currentTotal) : '—';
        summary.percent.textContent = invalid === 0 ? percent(currentTotal, originalTotal) : '—';
        summary.state.textContent = invalid > 0
            ? `Ошибок в ценах: ${invalid}`
            : changed > 0 ? `Несохранённых изменений: ${changed}` : 'Нет несохранённых изменений';
        summary.state.classList.toggle('has-errors', invalid > 0);
        summary.state.classList.toggle('is-modified', changed > 0 && invalid === 0);
        editor.classList.toggle('is-modified', changed > 0);
        saveButton.disabled = changed === 0 || invalid > 0 || isSaving;
    }

    inputs.forEach((input, index) => {
        input.addEventListener('input', update);
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                input.value = decimal(BigInt(rows[index].dataset.loadedMinor));
                input.blur();
                update();
            } else if (event.key === 'Enter') {
                event.preventDefault();
                const minor = parseMinor(input.value);
                if (minor === null) {
                    update();
                    return;
                }
                input.value = decimal(minor);
                update();
                inputs[index + 1]?.focus();
                inputs[index + 1]?.select();
            }
        });
        input.addEventListener('blur', () => {
            const minor = parseMinor(input.value);
            if (minor !== null) input.value = decimal(minor);
            update();
        });
    });

    editor.querySelector('[data-reset-prices]').addEventListener('click', () => {
        rows.forEach((row, index) => {
            inputs[index].value = decimal(BigInt(row.dataset.loadedMinor));
        });
        update();
        saveMessage.textContent = '';
    });

    async function saveDraft() {
        update();
        if (!hasValidUnsavedChanges || invalidCount > 0 || isSaving) return false;

        const prices = rows.map((row, index) => ({
            service_id: row.dataset.serviceId,
            current_price_minor: String(parseMinor(inputs[index].value)),
        }));
        isSaving = true;
        saveMessage.textContent = 'Сохранение…';
        saveMessage.className = 'draft-save-message';
        update();
        try {
            const response = await fetch('/admin/save-draft.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': editor.dataset.csrfToken,
                },
                body: JSON.stringify({
                    version_id: editor.dataset.versionId,
                    expected_revision: editor.dataset.revision,
                    prices,
                }),
            });
            const result = await response.json().catch(() => null);
            if (!response.ok || !result?.ok) {
                const conflict = response.status === 409 && result?.error === 'revision_conflict';
                saveMessage.textContent = conflict
                    ? 'Черновик изменён в другой вкладке. Перезагрузите страницу перед сохранением.'
                    : result?.message || 'Не удалось сохранить изменения.';
                saveMessage.classList.add('has-errors');
                return false;
            }

            rows.forEach((row, index) => {
                const current = parseMinor(inputs[index].value);
                row.dataset.loadedMinor = String(current);
                inputs[index].value = decimal(current);
            });
            editor.dataset.revision = String(result.revision);
            saveMessage.textContent = `Сохранено. Новая ревизия: ${result.revision}.`;
            saveMessage.classList.add('is-success');
            return true;
        } catch (error) {
            saveMessage.textContent = 'Сеть недоступна. Изменения не сохранены.';
            saveMessage.classList.add('has-errors');
            return false;
        } finally {
            isSaving = false;
            update();
        }
    }

    function downloadSavedVersion() {
        let frame = document.querySelector('[data-export-frame]');
        if (!frame) {
            frame = document.createElement('iframe');
            frame.hidden = true;
            frame.dataset.exportFrame = '';
            document.body.appendChild(frame);
        }
        frame.src = `/admin/export-version.php?id=${encodeURIComponent(editor.dataset.versionId)}&request=${Date.now()}`;
    }

    saveButton.addEventListener('click', async () => {
        await saveDraft();
    });

    downloadButton.addEventListener('click', () => {
        update();
        if (!hasValidUnsavedChanges && invalidCount === 0) {
            downloadSavedVersion();
            return;
        }
        saveDownloadButton.disabled = invalidCount > 0;
        exportDialogText.textContent = invalidCount > 0
            ? 'Есть некорректные цены. Можно скачать только последнюю сохранённую версию.'
            : 'В черновике есть несохранённые изменения. Выберите, что скачать.';
        exportDialog.showModal();
    });

    saveDownloadButton.addEventListener('click', async () => {
        if (invalidCount > 0) return;
        saveDownloadButton.disabled = true;
        const saved = await saveDraft();
        saveDownloadButton.disabled = false;
        if (!saved) return;
        exportDialog.close();
        downloadSavedVersion();
    });

    downloadSavedButton.addEventListener('click', () => {
        exportDialog.close();
        downloadSavedVersion();
    });

    window.addEventListener('beforeunload', (event) => {
        if (!hasValidUnsavedChanges) return;
        event.preventDefault();
        event.returnValue = '';
    });

    update();
})();

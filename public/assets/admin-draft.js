(() => {
    'use strict';

    const editor = document.querySelector('[data-draft-editor]');
    if (!editor) return;
    const basePath = document.querySelector('[data-admin-base-path]')?.dataset.adminBasePath || '/admin/';
    const appUrl = (path) => `${basePath.replace(/\/$/, '')}/${String(path).replace(/^\/+/, '')}`;

    const aboutToggle = editor.querySelector('[data-draft-about-toggle]');
    const aboutContent = editor.querySelector('[data-draft-about-content]');
    if (aboutToggle && aboutContent) {
        aboutToggle.addEventListener('click', () => {
            const expanded = aboutToggle.getAttribute('aria-expanded') !== 'true';
            aboutToggle.setAttribute('aria-expanded', String(expanded));
            aboutContent.hidden = !expanded;
        });
    }

    const MAX_MINOR = 9223372036854775807n;
    const rows = Array.from(editor.querySelectorAll('[data-price-row]'));
    const inputs = rows.map((row) => row.querySelector('.price-input'));
    const searchInput = document.querySelector('[data-service-search]');
    const searchClear = document.querySelector('[data-service-search-clear]');
    const searchEmpty = editor.querySelector('[data-service-search-empty]');
    const categories = Array.from(editor.querySelectorAll('.draft-category'));
    const searchableRows = rows.map((row) => ({
        row,
        text: [row.querySelector('.service-number'), row.querySelector('.service-code'), row.querySelector('.service-name')]
            .map((cell) => cell?.textContent || '')
            .join(' ')
            .toLocaleLowerCase('ru-RU'),
    }));
    const saveButton = editor.querySelector('[data-save-prices]');
    const publishButton = editor.querySelector('[data-publish-prices]');
    const publicationState = editor.querySelector('[data-publication-state]');
    const draftStatus = editor.querySelector('[data-draft-status]');
    const downloadButton = editor.querySelector('[data-download-xlsx]');
    const resetButton = editor.querySelector('[data-reset-prices]');
    const saveMessage = editor.querySelector('[data-save-message]');
    const exportDialog = editor.querySelector('[data-export-dialog]');
    const saveDownloadButton = editor.querySelector('[data-save-download]');
    const downloadSavedButton = editor.querySelector('[data-download-saved]');
    const exportDialogText = editor.querySelector('[data-export-dialog-text]');
    const publishDialog = editor.querySelector('[data-publish-dialog]');
    const publishCancelButton = editor.querySelector('[data-publish-cancel]');
    const publishConfirmButton = editor.querySelector('[data-publish-confirm]');
    const publishDialogMessage = editor.querySelector('[data-publish-dialog-message]');
    const summary = {
        changed: editor.querySelector('[data-summary-changed]'),
        increased: editor.querySelector('[data-summary-increased]'),
        decreased: editor.querySelector('[data-summary-decreased]'),
        original: editor.querySelector('[data-summary-original]'),
        current: editor.querySelector('[data-summary-current]'),
        percent: editor.querySelector('[data-summary-percent]'),
        state: editor.querySelector('[data-draft-state]'),
        section: editor.querySelector('[data-summary-section]'),
        changedRow: editor.querySelector('[data-summary-changed-row]'),
        increasedRow: editor.querySelector('[data-summary-increased-row]'),
        decreasedRow: editor.querySelector('[data-summary-decreased-row]'),
        percentRow: editor.querySelector('[data-summary-percent-row]'),
    };
    let hasValidUnsavedChanges = false;
    let invalidCount = 0;
    let isSaving = false;
    let isPublishing = false;
    let publicationCompleted = false;
    const isCurrentPublishedClone = editor.dataset.currentPublishedClone === 'true';

    const filterServices = () => {
        const query = searchInput.value.trim().toLocaleLowerCase('ru-RU');
        let visibleCount = 0;
        searchableRows.forEach(({row, text}) => {
            const matches = query === '' || text.includes(query);
            row.hidden = !matches;
            if (matches) visibleCount++;
        });
        categories.forEach((category) => {
            category.hidden = !Array.from(category.querySelectorAll('[data-price-row]'))
                .some((row) => !row.hidden);
        });
        searchClear.hidden = query === '';
        searchEmpty.hidden = visibleCount > 0;
    };
    searchInput.addEventListener('input', filterServices);
    searchClear.addEventListener('click', () => {
        searchInput.value = '';
        filterServices();
        searchInput.focus();
    });

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
        let unsaved = 0;
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
            if (current !== imported) changed++;
            if (current !== loaded) unsaved++;
        });

        // Invalid rows do not count as changes, but must not suppress a warning for other valid edits.
        hasValidUnsavedChanges = unsaved > 0;
        invalidCount = invalid;
        summary.changed.textContent = String(changed);
        summary.increased.textContent = String(increased);
        summary.decreased.textContent = String(decreased);
        summary.original.textContent = money(originalTotal);
        summary.current.textContent = invalid === 0 ? money(currentTotal) : '—';
        summary.percent.textContent = invalid === 0 ? percent(currentTotal, originalTotal) : '—';
        summary.section.hidden = changed === 0;
        summary.changedRow.hidden = changed === 0;
        summary.increasedRow.hidden = increased === 0;
        summary.decreasedRow.hidden = decreased === 0;
        summary.percentRow.hidden = changed === 0;
        summary.state.textContent = invalid > 0
            ? `Ошибок в ценах: ${invalid}`
            : unsaved > 0 ? `Несохранённых изменений: ${unsaved}` : 'Нет несохранённых изменений.';
        summary.state.classList.toggle('has-errors', invalid > 0);
        summary.state.classList.toggle('is-modified', unsaved > 0 && invalid === 0);
        editor.classList.toggle('is-modified', unsaved > 0);
        saveButton.disabled = unsaved === 0 || invalid > 0 || isSaving;
        saveButton.hidden = unsaved === 0 && invalid === 0;
        publishButton.disabled = unsaved > 0 || invalid > 0 || isSaving;
        const alreadyPublished = isCurrentPublishedClone && changed === 0 && unsaved === 0 && invalid === 0;
        publishButton.hidden = alreadyPublished;
        publicationState.hidden = !alreadyPublished;
        draftStatus.textContent = alreadyPublished ? 'Опубликован' : 'Черновик';
        draftStatus.className = alreadyPublished ? 'status-published-badge' : 'status-draft';
        downloadButton.hidden = unsaved > 0 || invalid > 0 || isSaving;
        resetButton.hidden = (unsaved === 0 && invalid === 0) || isSaving;
        if (publicationCompleted) {
            summary.state.textContent = 'Прайс опубликован.';
            summary.state.classList.remove('has-errors', 'is-modified');
            publishButton.hidden = true;
            saveButton.hidden = true;
            resetButton.hidden = true;
            downloadButton.hidden = false;
            publicationState.hidden = false;
            publicationState.textContent = 'Прайс опубликован на сайте.';
            draftStatus.textContent = 'Опубликован';
            draftStatus.className = 'status-published-badge';
        }
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

    resetButton.addEventListener('click', () => {
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
            const response = await fetch(appUrl('save-draft.php'), {
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
        frame.src = `${appUrl('export-version.php')}?id=${encodeURIComponent(editor.dataset.versionId)}&request=${Date.now()}`;
    }

    saveButton.addEventListener('click', async () => {
        await saveDraft();
    });

    publishButton.addEventListener('click', async () => {
        update();
        if (hasValidUnsavedChanges || invalidCount > 0 || isSaving) return;
        publishDialogMessage.hidden = true;
        publishDialogMessage.textContent = '';
        publishDialog.showModal();
    });

    publishCancelButton.addEventListener('click', () => {
        if (!isPublishing) publishDialog.close();
    });

    publishDialog.addEventListener('click', (event) => {
        if (event.target !== publishDialog || isPublishing) return;
        const bounds = publishDialog.getBoundingClientRect();
        const outside = event.clientX < bounds.left || event.clientX > bounds.right
            || event.clientY < bounds.top || event.clientY > bounds.bottom;
        if (outside) publishDialog.close();
    });

    publishDialog.addEventListener('cancel', (event) => {
        if (isPublishing) event.preventDefault();
    });

    publishConfirmButton.addEventListener('click', async () => {
        if (isPublishing) return;
        isPublishing = true;
        isSaving = true;
        publishConfirmButton.disabled = true;
        publishCancelButton.disabled = true;
        publishConfirmButton.textContent = 'Публикуем…';
        publishDialogMessage.hidden = true;
        update();
        saveMessage.textContent = 'Публикация…';
        saveMessage.className = 'draft-save-message';
        try {
            const response = await fetch(appUrl('publish-version.php'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': editor.dataset.csrfToken},
                body: JSON.stringify({
                    version_id: editor.dataset.versionId,
                    expected_revision: editor.dataset.revision,
                    expected_published_version_id: editor.dataset.publishedVersionId || null,
                }),
            });
            const result = await response.json().catch(() => null);
            if (!response.ok || !result?.ok) {
                const message = result?.message || 'Не удалось опубликовать прайс.';
                publishDialogMessage.textContent = message;
                publishDialogMessage.hidden = false;
                saveMessage.textContent = message;
                saveMessage.classList.add('has-errors');
                return;
            }
            publicationCompleted = true;
            inputs.forEach((input) => { input.disabled = true; });
            publishDialog.close();
            saveMessage.textContent = '';
        } catch (error) {
            const message = 'Сеть недоступна. Прайс не опубликован.';
            publishDialogMessage.textContent = message;
            publishDialogMessage.hidden = false;
            saveMessage.textContent = message;
            saveMessage.classList.add('has-errors');
        } finally {
            isPublishing = false;
            isSaving = false;
            publishConfirmButton.disabled = false;
            publishCancelButton.disabled = false;
            publishConfirmButton.textContent = 'Опубликовать';
            update();
        }
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

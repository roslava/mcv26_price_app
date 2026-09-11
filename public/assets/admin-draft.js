(() => {
    'use strict';

    const editor = document.querySelector('[data-draft-editor]');
    if (!editor) return;
    const basePath = document.querySelector('[data-admin-base-path]')?.dataset.adminBasePath || '/admin/';
    const appUrl = (path) => `${basePath.replace(/\/$/, '')}/${String(path).replace(/^\/+/, '')}`;

    const priceInfo = editor.querySelector('[data-draft-price-info]');
    const priceInfoToggle = editor.querySelector('[data-draft-price-info-toggle]');
    const priceInfoPanel = editor.querySelector('[data-draft-price-info-panel]');
    const setPriceInfoOpen = (open) => {
        if (!priceInfoToggle || !priceInfoPanel) return;
        priceInfoToggle.setAttribute('aria-expanded', String(open));
        priceInfoPanel.hidden = !open;
    };
    if (priceInfo && priceInfoToggle && priceInfoPanel) {
        priceInfoToggle.addEventListener('click', () => {
            setPriceInfoOpen(priceInfoToggle.getAttribute('aria-expanded') !== 'true');
        });
        document.addEventListener('click', (event) => {
            if (!priceInfo.contains(event.target)) setPriceInfoOpen(false);
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') setPriceInfoOpen(false);
        });
    }

    const MAX_MINOR = 9223372036854775807n;
    const rows = Array.from(editor.querySelectorAll('[data-price-row]'));
    const inputs = rows.map((row) => row.querySelector('.price-input'));
    const percentInputs = rows.map((row) => row.querySelector('.percent-input'));
    const massPriceForm = editor.querySelector('[data-mass-price-form]');
    const massPriceInput = editor.querySelector('[data-mass-price-percent]');
    const massPriceApplyButton = editor.querySelector('[data-mass-price-apply]');
    const massPriceMessage = editor.querySelector('[data-mass-price-message]');
    const massPriceDialog = editor.querySelector('[data-mass-price-dialog]');
    const massPriceDialogMessage = editor.querySelector('[data-mass-price-dialog-message]');
    const massPriceCancelButton = editor.querySelector('[data-mass-price-cancel]');
    const massPriceConfirmButton = editor.querySelector('[data-mass-price-confirm]');
    const searchInput = document.querySelector('[data-service-search]');
    const searchClear = document.querySelector('[data-service-search-clear]');
    const searchEmpty = editor.querySelector('[data-service-search-empty]');
    const changedFilter = editor.querySelector('[data-changed-filter]');
    const changedFilterCount = editor.querySelector('[data-changed-filter-count]');
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
    let isRedirectingAfterPublish = false;
    let changedOnly = false;
    const isCurrentPublishedClone = editor.dataset.currentPublishedClone === 'true';

    const filterServices = () => {
        const query = searchInput.value.trim().toLocaleLowerCase('ru-RU');
        let visibleCount = 0;
        searchableRows.forEach(({row, text}) => {
            const matchesSearch = query === '' || text.includes(query);
            const matchesChanged = !changedOnly || hasChangedPrice(row);
            const matches = matchesSearch && matchesChanged;
            row.hidden = !matches;
            if (matches) visibleCount++;
        });
        categories.forEach((category) => {
            category.hidden = !Array.from(category.querySelectorAll('[data-price-row]'))
                .some((row) => !row.hidden);
        });
        searchClear.hidden = query === '';
        searchEmpty.hidden = visibleCount > 0;
        searchEmpty.textContent = changedOnly && query === '' ? 'Нет изменённых позиций.' : 'Услуги не найдены';
    };
    searchInput.addEventListener('input', filterServices);
    searchClear.addEventListener('click', () => {
        searchInput.value = '';
        filterServices();
        searchInput.focus();
    });
    changedFilter.addEventListener('click', () => {
        changedOnly = !changedOnly;
        changedFilter.setAttribute('aria-pressed', String(changedOnly));
        changedFilter.classList.toggle('is-active', changedOnly);
        filterServices();
    });

    function parseMinor(text) {
        const normalized = text.trim().replace(',', '.');
        const match = /^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/.exec(normalized);
        if (!match) return null;
        const fraction = (match[2] || '').padEnd(2, '0');
        const minor = BigInt(match[1]) * 100n + BigInt(fraction || '0');
        return minor > 0n && minor <= MAX_MINOR ? minor : null;
    }

    function hasChangedPrice(row, current = parseMinor(row.querySelector('.price-input').value)) {
        return current !== null && current !== BigInt(row.dataset.importedMinor);
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

    function percentValue(current, imported) {
        const hundredths = roundedDivide((current - imported) * 10000n, imported);
        const absolute = hundredths < 0n ? -hundredths : hundredths;
        const fraction = String(absolute % 100n).padStart(2, '0').replace(/0+$/, '');
        return `${hundredths < 0n ? '-' : ''}${absolute / 100n}${fraction ? `.${fraction}` : ''}`;
    }

    function parsePercent(text) {
        const match = /^([+-]?)(\d{1,12})(?:[.,](\d{1,6}))?$/.exec(text.trim());
        if (!match) return null;
        const scale = 10n ** BigInt((match[3] || '').length);
        const absolute = BigInt(match[2]) * scale + BigInt(match[3] || '0');
        return {value: match[1] === '-' ? -absolute : absolute, scale};
    }

    function priceFromPercent(imported, parsedPercent) {
        const denominator = 100n * parsedPercent.scale;
        const numerator = imported * (denominator + parsedPercent.value);
        if (numerator <= 0n) return null;
        const current = roundedDivide(numerator, denominator);
        return current > 0n && current <= MAX_MINOR ? current : null;
    }

    function formattedPercent(parsedPercent) {
        const absolute = parsedPercent.value < 0n ? -parsedPercent.value : parsedPercent.value;
        const whole = absolute / parsedPercent.scale;
        const fraction = String(absolute % parsedPercent.scale).padStart(String(parsedPercent.scale).length - 1, '0').replace(/0+$/, '');
        return `${whole}${fraction ? `,${fraction}` : ''}`;
    }

    function setMassPriceMessage(message, isError = false) {
        massPriceMessage.textContent = message;
        massPriceMessage.classList.toggle('has-errors', isError);
        massPriceInput.toggleAttribute('aria-invalid', isError);
    }

    function validateMassPriceChange() {
        const parsedPercent = parsePercent(massPriceInput.value);
        if (parsedPercent === null) {
            setMassPriceMessage('Введите корректный процент: например, 5, 10,5 или −5.', true);
            return null;
        }
        if (parsedPercent.value === 0n) {
            setMassPriceMessage('0% не изменяет цены.');
            return null;
        }
        for (let index = 0; index < rows.length; index++) {
            const current = parseMinor(inputs[index].value);
            if (current === null) {
                setMassPriceMessage('Сначала исправьте некорректные цены в таблице.', true);
                return null;
            }
            // Reuse the row percentage calculation and its BigInt rounding for every current price.
            if (priceFromPercent(current, parsedPercent) === null) {
                setMassPriceMessage('Этот процент приводит хотя бы одну цену к недопустимому значению.', true);
                return null;
            }
        }
        return parsedPercent;
    }

    function applyMassPriceChange(parsedPercent) {
        rows.forEach((row, index) => {
            const current = parseMinor(inputs[index].value);
            const next = priceFromPercent(current, parsedPercent);
            inputs[index].value = decimal(next);
            percentInputs[index].value = percentValue(next, BigInt(row.dataset.importedMinor));
        });
        massPriceInput.value = '';
        setMassPriceMessage('');
        update();
    }

    function update(percentSourceIndex = null) {
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
            const percentInput = percentInputs[index];
            const current = parseMinor(input.value);
            originalTotal += imported;
            row.classList.remove('price-increased', 'price-decreased', 'price-unchanged', 'price-changed', 'price-invalid');
            input.removeAttribute('aria-invalid');
            percentInput.removeAttribute('aria-invalid');

            const editedPercent = index === percentSourceIndex ? parsePercent(percentInput.value) : null;
            const priceForEditedPercent = editedPercent === null ? null : priceFromPercent(imported, editedPercent);
            if (current === null || (index === percentSourceIndex && priceForEditedPercent === null)) {
                invalid++;
                row.classList.add('price-invalid');
                (current === null ? input : percentInput).setAttribute('aria-invalid', 'true');
                return;
            }
            currentTotal += current;
            if (index !== percentSourceIndex) percentInput.value = percentValue(current, imported);
            if (current > imported) {
                increased++;
                row.classList.add('price-increased');
            } else if (current < imported) {
                decreased++;
                row.classList.add('price-decreased');
            } else {
                row.classList.add('price-unchanged');
            }
            if (hasChangedPrice(row, current)) {
                changed++;
                row.classList.add('price-changed');
            }
            if (current !== loaded) unsaved++;
        });

        // Invalid rows do not count as changes, but must not suppress a warning for other valid edits.
        hasValidUnsavedChanges = unsaved > 0;
        invalidCount = invalid;
        summary.changed.textContent = String(changed);
        changedFilterCount.textContent = `(${changed})`;
        changedFilter.hidden = changed === 0;
        if (changed === 0 && changedOnly) {
            changedOnly = false;
            changedFilter.setAttribute('aria-pressed', 'false');
            changedFilter.classList.remove('is-active');
        }
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
        massPriceInput.disabled = isSaving;
        massPriceApplyButton.disabled = isSaving;
        massPriceConfirmButton.disabled = isSaving;
        saveButton.disabled = unsaved === 0 || invalid > 0 || isSaving;
        saveButton.hidden = unsaved === 0 && invalid === 0;
        publishButton.disabled = unsaved > 0 || invalid > 0 || isSaving;
        const alreadyPublished = isCurrentPublishedClone && changed === 0 && unsaved === 0 && invalid === 0;
        publishButton.hidden = alreadyPublished || unsaved > 0;
        publicationState.hidden = !alreadyPublished;
        draftStatus.textContent = alreadyPublished ? 'Опубликован' : 'Черновик';
        draftStatus.className = alreadyPublished ? 'status-published-badge' : 'status-draft';
        downloadButton.hidden = unsaved > 0 || invalid > 0 || isSaving;
        resetButton.hidden = (unsaved === 0 && invalid === 0) || isSaving;
        filterServices();
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

    percentInputs.forEach((input, index) => {
        input.addEventListener('input', () => {
            const parsed = parsePercent(input.value);
            const current = parsed === null
                ? null
                : priceFromPercent(BigInt(rows[index].dataset.importedMinor), parsed);
            if (current !== null) inputs[index].value = decimal(current);
            update(index);
        });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                inputs[index].value = decimal(BigInt(rows[index].dataset.loadedMinor));
                input.value = percentValue(
                    BigInt(rows[index].dataset.loadedMinor),
                    BigInt(rows[index].dataset.importedMinor)
                );
                input.blur();
                update();
            } else if (event.key === 'Enter') {
                event.preventDefault();
                input.blur();
                inputs[index + 1]?.focus();
                inputs[index + 1]?.select();
            }
        });
        input.addEventListener('blur', () => update());
    });

    massPriceForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const parsedPercent = validateMassPriceChange();
        if (parsedPercent === null) return;
        const direction = parsedPercent.value > 0n ? 'увеличены' : 'уменьшены';
        massPriceDialogMessage.textContent = `Все текущие цены будут ${direction} на ${formattedPercent(parsedPercent)}%. Будет изменено ${rows.length} позиций.`;
        massPriceDialog.showModal();
    });

    function closeMassPriceDialog() {
        if (!massPriceDialog.open || massPriceDialog.classList.contains('is-closing')) return;

        massPriceDialog.classList.add('is-closing');
        let closeTimer;
        const finishClose = (event) => {
            if (event && (event.target !== massPriceDialog || event.animationName !== 'mass-price-dialog-out')) return;
            window.clearTimeout(closeTimer);
            massPriceDialog.classList.remove('is-closing');
            if (massPriceDialog.open) massPriceDialog.close();
        };
        massPriceDialog.addEventListener('animationend', finishClose, {once: true});
        closeTimer = window.setTimeout(finishClose, 220);
    }

    massPriceCancelButton.addEventListener('click', closeMassPriceDialog);
    massPriceDialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        closeMassPriceDialog();
    });
    massPriceDialog.addEventListener('close', () => massPriceApplyButton.focus());
    massPriceDialog.addEventListener('click', (event) => {
        if (event.target !== massPriceDialog) return;
        const bounds = massPriceDialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) {
            closeMassPriceDialog();
        }
    });
    massPriceConfirmButton.addEventListener('click', () => {
        const parsedPercent = validateMassPriceChange();
        if (parsedPercent === null) {
            closeMassPriceDialog();
            return;
        }
        applyMassPriceChange(parsedPercent);
        closeMassPriceDialog();
    });

    resetButton.addEventListener('click', () => {
        rows.forEach((row, index) => {
            inputs[index].value = decimal(BigInt(row.dataset.loadedMinor));
            percentInputs[index].value = percentValue(
                BigInt(row.dataset.loadedMinor),
                BigInt(row.dataset.importedMinor)
            );
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
            isRedirectingAfterPublish = true;
            window.location.assign(appUrl(''));
            return;
        } catch (error) {
            const message = 'Сеть недоступна. Прайс не опубликован.';
            publishDialogMessage.textContent = message;
            publishDialogMessage.hidden = false;
            saveMessage.textContent = message;
            saveMessage.classList.add('has-errors');
        } finally {
            if (isRedirectingAfterPublish) return;
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

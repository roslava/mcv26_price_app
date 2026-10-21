(() => {
    'use strict';

    const editor = document.querySelector('[data-draft-editor]');
    if (!editor) return;
    const basePath = document.querySelector('[data-admin-base-path]')?.dataset.adminBasePath || '/admin/';
    const appUrl = (path) => `${basePath.replace(/\/$/, '')}/${String(path).replace(/^\/+/, '')}`;

    const priceInfoToggle = document.querySelector('[data-menu-price-info]');
    const priceInfoDialog = editor.querySelector('[data-draft-price-info-dialog]');
    const priceInfoClose = editor.querySelector('[data-draft-price-info-close]');
    if (priceInfoToggle && priceInfoDialog && priceInfoClose) {
        priceInfoToggle.addEventListener('click', () => priceInfoDialog.showModal());
        priceInfoClose.addEventListener('click', () => priceInfoDialog.close());
        priceInfoDialog.addEventListener('click', (event) => {
            if (event.target !== priceInfoDialog) return;
            const bounds = priceInfoDialog.getBoundingClientRect();
            const outside = event.clientX < bounds.left || event.clientX > bounds.right
                || event.clientY < bounds.top || event.clientY > bounds.bottom;
            if (outside) priceInfoDialog.close();
        });
        priceInfoDialog.addEventListener('close', () => priceInfoToggle.focus());
    }
    const desktopMenuClosers = [];
    const setupDesktopMenu = (toggle, popup) => {
        const item = toggle?.closest('.desktop-menu-item');
        if (!toggle || !popup || !item) return () => {};
        const setOpen = (open) => {
            if (open) desktopMenuClosers.forEach((close) => {
                if (close !== setOpen) close(false);
            });
            toggle.setAttribute('aria-expanded', String(open));
            popup.hidden = !open;
            if (open) popup.querySelector('[role^="menuitem"]:not(:disabled)')?.focus();
            else if (popup.contains(document.activeElement)) toggle.focus();
        };
        toggle.addEventListener('click', () => {
            setOpen(toggle.getAttribute('aria-expanded') !== 'true');
        });
        toggle.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                setOpen(true);
                if (event.key === 'ArrowUp') {
                    const items = popup.querySelectorAll('[role^="menuitem"]:not(:disabled)');
                    items[items.length - 1]?.focus();
                }
            }
        });
        popup.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                const items = Array.from(popup.querySelectorAll('[role^="menuitem"]:not(:disabled)'));
                if (items.length === 0) return;
                const index = items.indexOf(document.activeElement);
                const direction = event.key === 'ArrowDown' ? 1 : -1;
                items[(index + direction + items.length) % items.length].focus();
            } else if (event.key === 'Home' || event.key === 'End') {
                event.preventDefault();
                const items = popup.querySelectorAll('[role^="menuitem"]:not(:disabled)');
                items[event.key === 'Home' ? 0 : items.length - 1]?.focus();
            }
        });
        item.addEventListener('focusout', (event) => {
            if (!item.contains(event.relatedTarget)) setOpen(false);
        });
        document.addEventListener('click', (event) => {
            if (!item.contains(event.target)) setOpen(false);
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !popup.hidden) {
                event.preventDefault();
                setOpen(false);
                toggle.focus();
            }
        });
        desktopMenuClosers.push(setOpen);
        return setOpen;
    };
    const setDocumentMenuOpen = setupDesktopMenu(
        document.querySelector('[data-menu-document]'), document.querySelector('[data-menu-document-popup]')
    );
    const setEditMenuOpen = setupDesktopMenu(
        document.querySelector('[data-menu-edit]'), document.querySelector('[data-menu-edit-popup]')
    );
    const setViewMenuOpen = setupDesktopMenu(
        document.querySelector('[data-menu-view]'), document.querySelector('[data-menu-view-popup]')
    );
    const MAX_MINOR = 9223372036854775807n;
    const rows = Array.from(editor.querySelectorAll('[data-price-row]'));
    const inputs = rows.map((row) => row.querySelector('.price-input'));
    const percentInputs = rows.map((row) => row.querySelector('.percent-input'));
    const massPriceForm = editor.querySelector('[data-mass-price-form]');
    const massPriceMenuCommand = document.querySelector('[data-menu-mass-price]');
    const massPriceEntryDialog = editor.querySelector('[data-mass-price-entry-dialog]');
    const massPriceEntryCancelButton = editor.querySelector('[data-mass-price-entry-cancel]');
    const massPriceInput = editor.querySelector('[data-mass-price-percent]');
    const massPriceApplyButton = editor.querySelector('[data-mass-price-apply]');
    const massPriceMessage = editor.querySelector('[data-mass-price-message]');
    const massPriceDialog = editor.querySelector('[data-mass-price-dialog]');
    const massPriceDialogMessage = editor.querySelector('[data-mass-price-dialog-message]');
    const massPriceCancelButton = editor.querySelector('[data-mass-price-cancel]');
    const massPriceConfirmButton = editor.querySelector('[data-mass-price-confirm]');
    let skipMassPriceEntryFocusReturn = false;
    let returnToMassPriceEntry = false;
    const focusMassPriceMenuCommand = () => {
        setEditMenuOpen(true);
        massPriceMenuCommand.focus();
    };
    const searchInput = document.querySelector('[data-service-search]');
    const searchClear = document.querySelector('[data-service-search-clear]');
    const searchEmpty = editor.querySelector('[data-service-search-empty]');
    const changedFilter = document.querySelector('[data-changed-filter]');
    const changedFilterCount = document.querySelector('[data-changed-filter-count]');
    const categories = Array.from(editor.querySelectorAll('.draft-category'));
    const addCategoryButton = document.querySelector('[data-add-category]');
    const serviceDialog = editor.querySelector('[data-service-dialog]');
    const serviceForm = editor.querySelector('[data-service-form]');
    const categoryDialog = editor.querySelector('[data-category-dialog]');
    const categoryForm = editor.querySelector('[data-category-form]');
    const deletedServiceIds = new Set();
    const deletedCategoryIds = new Set();
    const newServices = [];
    const newCategories = [];
    let serviceTarget = null;
    const structureFields = {
        serviceNumber: editor.querySelector('[data-new-service-number]'),
        serviceCode: editor.querySelector('[data-new-service-code]'),
        serviceName: editor.querySelector('[data-new-service-name]'),
        servicePrice: editor.querySelector('[data-new-service-price]'),
        categoryName: editor.querySelector('[data-new-category-name]'),
        categoryServiceNumber: editor.querySelector('[data-new-category-service-number]'),
        categoryServiceCode: editor.querySelector('[data-new-category-service-code]'),
        categoryServiceName: editor.querySelector('[data-new-category-service-name]'),
        categoryServicePrice: editor.querySelector('[data-new-category-service-price]'),
    };
    const searchableRows = rows.map((row) => ({
        row,
        text: [row.querySelector('.service-number'), row.querySelector('.service-code'), row.querySelector('.service-name')]
            .map((cell) => cell?.textContent || '')
            .join(' ')
            .toLocaleLowerCase('ru-RU'),
    }));
    const saveButton = editor.querySelector('[data-save-prices]');
    const exitLink = document.querySelector('[data-editor-exit-link]');
    const exitDialog = editor.querySelector('[data-exit-dialog]');
    const exitDialogMessage = editor.querySelector('[data-exit-dialog-message]');
    const exitSaveButton = editor.querySelector('[data-exit-save]');
    const exitDiscardButton = editor.querySelector('[data-exit-discard]');
    const exitCancelButton = editor.querySelector('[data-exit-cancel]');
    const publishButton = editor.querySelector('[data-publish-prices]');
    const publicationState = document.querySelector('[data-publication-state]');
    const draftStatus = document.querySelector('[data-draft-status]');
    const statusContainer = document.querySelector('[data-status-container]');
    const statusTooltip = document.querySelector('[data-status-tooltip]');
    const statusLive = document.querySelector('[data-status-live]');
    const downloadButton = document.querySelector('[data-download-xlsx]');
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
        increased: editor.querySelector('[data-summary-increased]'),
        decreased: editor.querySelector('[data-summary-decreased]'),
        original: editor.querySelector('[data-summary-original]'),
        current: editor.querySelector('[data-summary-current]'),
        percent: editor.querySelector('[data-summary-percent]'),
        state: document.querySelector('[data-draft-state]'),
        section: editor.querySelector('[data-summary-section]'),
        increasedRow: editor.querySelector('[data-summary-increased-row]'),
        decreasedRow: editor.querySelector('[data-summary-decreased-row]'),
        percentRow: editor.querySelector('[data-summary-percent-row]'),
    };
    const secondaryBar = document.querySelector('.desktop-secondary-bar');
    if (secondaryBar) {
        secondaryBar.append(summary.section, editor.querySelector('.draft-actions'));
    }
    document.querySelectorAll('.draft-summary-details').forEach((details) => {
        const wideScreen = window.matchMedia('(min-width: 1600px)');
        const syncSummary = () => { details.open = wideScreen.matches; };
        syncSummary();
        wideScreen.addEventListener('change', syncSummary);
        document.addEventListener('click', (event) => {
            if (!wideScreen.matches && !details.contains(event.target)) details.open = false;
        });
        details.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !wideScreen.matches) {
                details.open = false;
                details.querySelector('summary').focus();
            }
        });
    });
    let hasValidUnsavedChanges = false;
    let invalidCount = 0;
    let isSaving = false;
    let isExitSaving = false;
    let isExitConfirmed = false;
    let isPublishing = false;
    let isRedirectingAfterPublish = false;
    let changedOnly = false;
    const isCurrentPublishedClone = editor.dataset.currentPublishedClone === 'true';
    let statusHovered = false;
    let statusFocused = false;
    let statusTapped = false;
    let statusDismissed = false;
    let statusPointerType = '';
    let recoveryReady = false;
    let recoverySuspended = false;
    let recoveryTimer;
    const rawPercentEdits = new Map();
    const baselineFields = new Map();
    const recoveryNotice = editor.querySelector('[data-recovery-notice]');
    const recoveryDialog = editor.querySelector('[data-recovery-dialog]');
    const recoveryMessage = editor.querySelector('[data-recovery-message]');
    const recoveryRestore = editor.querySelector('[data-recovery-restore]');
    const recoveryDiscard = editor.querySelector('[data-recovery-discard]');
    const scrollport = editor.querySelector('.draft-table-wrap');
    const recoveryKey = 'mcv26:draft:' + basePath + ':' + editor.dataset.versionId;
    const reportRecoveryError = () => {
        recoveryNotice.hidden = false;
        recoveryNotice.textContent = 'Локальное восстановление недоступно. Сохраните изменения перед обновлением страницы.';
    };
    const recoveryStore = window.DraftRecovery.create(() => window.sessionStorage, recoveryKey, reportRecoveryError);
    const viewStore = window.DraftRecovery.create(() => window.sessionStorage, recoveryKey + ':view', reportRecoveryError);
    const cleanService = ({service_number, code, name, current_price_minor, category_id}) => ({
        service_number, code, name, current_price_minor, ...(category_id ? {category_id} : {}),
    });
    function captureRecovery() {
        return {
            schema_version: 1,
            version_id: editor.dataset.versionId,
            revision: editor.dataset.revision,
            updated_at: new Date().toISOString(),
            prices: rows.flatMap((row, index) => {
                if (row.dataset.structurallyDeleted) return [];
                const original = baselineFields.get(row.dataset.serviceId);
                return original && (inputs[index].value !== original.price || percentInputs[index].value !== original.percent)
                    ? [{service_id: row.dataset.serviceId, price: inputs[index].value, percent: percentInputs[index].value}]
                    : [];
            }),
            deleted_service_ids: [...deletedServiceIds],
            deleted_category_ids: [...deletedCategoryIds],
            new_services: newServices.filter((service) => !deletedCategoryIds.has(service.category_id)).map(cleanService),
            new_categories: activeNewCategories().map((category) => ({
                name: category.name, services: category.services.map(cleanService),
            })),
        };
    }
    function flushRecovery() {
        window.clearTimeout(recoveryTimer);
        if (!recoveryReady || recoverySuspended || isExitConfirmed || isRedirectingAfterPublish) return;
        const data = captureRecovery();
        if (data.prices.length || data.deleted_service_ids.length || data.deleted_category_ids.length
            || data.new_services.length || data.new_categories.length) recoveryStore.write(data);
        else recoveryStore.clear();
        viewStore.write({search: searchInput.value, changedOnly, top: scrollport.scrollTop, left: scrollport.scrollLeft});
    }
    function scheduleRecovery() {
        if (!recoveryReady || recoverySuspended) return;
        window.clearTimeout(recoveryTimer);
        recoveryTimer = window.setTimeout(flushRecovery, 350);
    }
    function clearRecovery() {
        window.clearTimeout(recoveryTimer);
        recoveryStore.clear();
    }
    function rememberBaseline() {
        rows.forEach((row, index) => baselineFields.set(row.dataset.serviceId, {
            price: inputs[index].value, percent: percentInputs[index].value,
        }));
    }
    function restoreRecovery(data) {
        data.prices.forEach((entry) => {
            const index = rows.findIndex((row) => row.dataset.serviceId === entry.service_id);
            inputs[index].value = entry.price;
            percentInputs[index].value = entry.percent;
            rawPercentEdits.set(index, entry.percent);
        });
        data.deleted_service_ids.forEach((id) => {
            deletedServiceIds.add(id);
            rows.find((row) => row.dataset.serviceId === id).dataset.structurallyDeleted = 'true';
        });
        data.deleted_category_ids.forEach((id) => {
            deletedCategoryIds.add(id);
            const category = categories.find((item) => item.dataset.categoryId === id);
            category.dataset.structurallyDeleted = 'true';
            category.querySelectorAll('[data-price-row]').forEach((row) => {
                deletedServiceIds.delete(row.dataset.serviceId);
                row.dataset.structurallyDeleted = 'category';
            });
        });
        data.new_services.forEach((service) => {
            newServices.push(service);
            appendPendingServiceRow(categories.find((item) => item.dataset.categoryId === service.category_id), service);
        });
        data.new_categories.forEach((category) => {
            newCategories.push(category);
            appendPendingCategory(category, newCategories.length - 1);
        });
        update();
    }
    function initializeRecovery() {
        rememberBaseline();
        const data = recoveryStore.read();
        const view = viewStore.read();
        const applyView = () => {
            if (!view || typeof view.search !== 'string' || typeof view.changedOnly !== 'boolean') return;
            searchInput.value = view.search.slice(0, 2048);
            changedOnly = view.changedOnly && !changedFilter.disabled;
            changedFilter.setAttribute('aria-checked', String(changedOnly));
            filterServices();
            window.requestAnimationFrame(() => {
                scrollport.scrollTop = Number.isFinite(view.top) ? Math.max(0, view.top) : 0;
                scrollport.scrollLeft = Number.isFinite(view.left) ? Math.max(0, view.left) : 0;
            });
        };
        if (!data) {
            recoveryReady = true;
            applyView();
            return;
        }
        const status = window.DraftRecovery.validate(data, editor.dataset.versionId, editor.dataset.revision,
            new Set(rows.map((row) => row.dataset.serviceId)), new Set(categories.map((category) => category.dataset.categoryId)));
        if (status === 'ready') {
            restoreRecovery(data);
            recoveryReady = true;
            applyView();
            flushRecovery();
            return;
        }
        recoveryRestore.hidden = true;
        recoveryMessage.textContent = status === 'conflict'
            ? 'Версия документа на сервере изменилась. Локальную копию нельзя автоматически применить.'
            : 'Локальная копия повреждена или несовместима. Восстановить её нельзя.';
        recoveryDiscard.addEventListener('click', () => {
            clearRecovery();
            recoveryReady = true;
            recoveryDialog.close();
            applyView();
        }, {once: true});
        recoveryDialog.addEventListener('cancel', (event) => event.preventDefault());
        recoveryDialog.showModal();
    }

    const visibleStatusMessages = () => [summary.state, publicationState]
        .filter((message) => !message.hidden && message.textContent.trim() !== '');
    const syncStatusTooltip = () => {
        const open = visibleStatusMessages().length > 0 && !statusDismissed
            && (statusHovered || statusFocused || statusTapped);
        statusTooltip.hidden = !open;
        draftStatus.setAttribute('aria-expanded', String(open));
    };
    statusContainer.addEventListener('pointerenter', (event) => {
        if (event.pointerType === 'touch') return;
        statusHovered = true;
        statusDismissed = false;
        syncStatusTooltip();
    });
    statusContainer.addEventListener('pointerleave', (event) => {
        if (event.pointerType === 'touch') return;
        statusHovered = false;
        syncStatusTooltip();
    });
    draftStatus.addEventListener('focus', () => {
        statusFocused = true;
        statusDismissed = false;
        syncStatusTooltip();
    });
    draftStatus.addEventListener('blur', () => {
        statusFocused = false;
        if (statusPointerType === 'touch') statusTapped = false;
        syncStatusTooltip();
    });
    draftStatus.addEventListener('pointerdown', (event) => {
        statusPointerType = event.pointerType;
    });
    draftStatus.addEventListener('click', (event) => {
        if (statusPointerType !== 'touch' && event.detail !== 0) return;
        statusTapped = !statusTapped;
        statusDismissed = !statusTapped;
        syncStatusTooltip();
    });
    draftStatus.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            statusTapped = !statusTapped;
            statusDismissed = !statusTapped;
            syncStatusTooltip();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || statusTooltip.hidden) return;
        statusTapped = false;
        statusDismissed = true;
        syncStatusTooltip();
    });
    document.addEventListener('pointerdown', (event) => {
        if (statusContainer.contains(event.target)) return;
        statusTapped = false;
        statusDismissed = true;
        syncStatusTooltip();
    });

    const filterServices = () => {
        const query = searchInput.value.trim().toLocaleLowerCase('ru-RU');
        let visibleCount = 0;
        searchableRows.forEach(({row, text}) => {
            if (row.dataset.structurallyDeleted) {
                row.hidden = true;
                return;
            }
            const matchesSearch = query === '' || text.includes(query);
            const matchesChanged = !changedOnly || hasChangedPrice(row);
            const matches = matchesSearch && matchesChanged;
            row.hidden = !matches;
            if (matches) visibleCount++;
        });
        categories.forEach((category) => {
            if (category.dataset.structurallyDeleted === 'true') {
                category.hidden = true;
                return;
            }
            const visibleExisting = Array.from(category.querySelectorAll('[data-price-row]'))
                .some((row) => !row.hidden);
            const pendingCount = category.querySelectorAll('[data-pending-service]').length;
            visibleCount += pendingCount;
            category.hidden = !visibleExisting && pendingCount === 0;
        });
        visibleCount += activeNewCategories().reduce((count, category) => count + category.services.length, 0);
        searchClear.hidden = query === '';
        searchEmpty.hidden = visibleCount > 0;
        scheduleRecovery();
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
        changedFilter.setAttribute('aria-checked', String(changedOnly));
        setViewMenuOpen(false);
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

    const activeNewCategories = () => newCategories.filter((category) => !category.removed);
    const structuralChangeCount = () => deletedServiceIds.size + deletedCategoryIds.size
        + newServices.length + activeNewCategories().length;
    const remainingCategoryCount = () => categories.filter((category) => !deletedCategoryIds.has(category.dataset.categoryId)).length
        + activeNewCategories().length;
    const emptyPriceWarning = 'Перед сохранением добавьте новый раздел: прайс не может быть без разделов.';

    const serviceFromFields = (numberField, codeField, nameField, priceField) => {
        const serviceNumber = numberField.value.trim();
        const code = codeField.value.trim();
        const name = nameField.value.trim();
        const price = parseMinor(priceField.value);
        if (!/^[1-9]\d*$/.test(serviceNumber) || code === '' || name === '' || price === null) return null;
        return {service_number: serviceNumber, code, name, current_price_minor: String(price)};
    };

    const actionMenu = document.createElement('div');
    actionMenu.id = 'draft-structure-action-menu';
    actionMenu.className = 'structure-action-menu';
    actionMenu.setAttribute('role', 'menu');
    actionMenu.hidden = true;
    document.body.appendChild(actionMenu);
    let activeActionToggle = null;
    const actionSources = new WeakMap();
    const closeActionMenu = (restoreFocus = false) => {
        if (!activeActionToggle) return;
        const toggle = activeActionToggle;
        activeActionToggle = null;
        actionMenu.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
        if (restoreFocus && toggle.isConnected) toggle.focus({preventScroll: true});
    };
    const positionActionMenu = () => {
        const trigger = activeActionToggle.getBoundingClientRect();
        const menu = actionMenu.getBoundingClientRect();
        const scrollport = editor.querySelector('.draft-table-wrap').getBoundingClientRect();
        const edge = 8;
        const gap = 4;
        const roomBelow = Math.min(scrollport.bottom, window.innerHeight) - trigger.bottom;
        const roomAbove = trigger.top - Math.max(scrollport.top, 0);
        const openUp = roomBelow < menu.height + gap && roomAbove > roomBelow;
        const desiredTop = openUp ? trigger.top - menu.height - gap : trigger.bottom + gap;
        actionMenu.style.top = `${Math.max(edge, Math.min(desiredTop, window.innerHeight - menu.height - edge))}px`;
        actionMenu.style.left = `${Math.max(edge, Math.min(trigger.right - menu.width, window.innerWidth - menu.width - edge))}px`;
    };
    const openActionMenu = (toggle, focusLast = false, toggleIfOpen = true) => {
        if (activeActionToggle === toggle) {
            if (toggleIfOpen) closeActionMenu(true);
            else {
                const menuItems = actionMenu.querySelectorAll('[role="menuitem"]');
                menuItems[focusLast ? menuItems.length - 1 : 0]?.focus({preventScroll: true});
            }
            return;
        }
        closeActionMenu();
        activeActionToggle = toggle;
        toggle.setAttribute('aria-expanded', 'true');
        actionMenu.setAttribute('aria-label', toggle.getAttribute('aria-label'));
        actionMenu.replaceChildren();
        const pending = !!toggle.closest('.pending-structure-row, .pending-structure-category');
        const items = toggle.dataset.structureMenuToggle === 'category'
            ? [['Добавить услугу', 'addService'], ['Удалить раздел', pending ? 'deletePendingCategory' : 'deleteCategory']]
            : [['Удалить услугу', pending ? 'deletePendingService' : 'deleteService']];
        items.forEach(([label, action]) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.setAttribute('role', 'menuitem');
            item.dataset[action] = '';
            actionSources.set(item, toggle);
            item.textContent = label;
            if (action.startsWith('delete')) item.className = 'structure-action-danger';
            actionMenu.appendChild(item);
        });
        actionMenu.style.visibility = 'hidden';
        actionMenu.hidden = false;
        positionActionMenu();
        actionMenu.style.visibility = '';
        const menuItems = actionMenu.querySelectorAll('[role="menuitem"]');
        menuItems[focusLast ? menuItems.length - 1 : 0]?.focus({preventScroll: true});
    };
    const createActionToggle = (type, label) => {
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'structure-action-toggle';
        toggle.dataset.structureMenuToggle = type;
        toggle.setAttribute('aria-label', label);
        toggle.setAttribute('aria-haspopup', 'menu');
        toggle.setAttribute('aria-controls', actionMenu.id);
        toggle.setAttribute('aria-expanded', 'false');
        toggle.textContent = '⋯';
        return toggle;
    };
    actionMenu.addEventListener('keydown', (event) => {
        const items = Array.from(actionMenu.querySelectorAll('[role="menuitem"]'));
        const index = items.indexOf(document.activeElement);
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const direction = event.key === 'ArrowDown' ? 1 : -1;
            items[(index + direction + items.length) % items.length]?.focus({preventScroll: true});
        } else if (event.key === 'Home' || event.key === 'End') {
            event.preventDefault();
            items[event.key === 'Home' ? 0 : items.length - 1]?.focus({preventScroll: true});
        } else if (event.key === 'Tab') {
            event.preventDefault();
            closeActionMenu(true);
        }
    });
    actionMenu.addEventListener('focusout', (event) => {
        if (actionMenu.contains(event.relatedTarget)) return;
        queueMicrotask(() => {
            if (activeActionToggle && !actionMenu.contains(document.activeElement)) closeActionMenu();
        });
    });
    document.addEventListener('pointerdown', (event) => {
        if (activeActionToggle && !actionMenu.contains(event.target) && !activeActionToggle.contains(event.target)) closeActionMenu();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !activeActionToggle) return;
        event.preventDefault();
        event.stopPropagation();
        closeActionMenu(true);
    }, true);
    window.addEventListener('scroll', (event) => {
        if (!actionMenu.contains(event.target)) closeActionMenu();
    }, true);
    window.addEventListener('resize', () => closeActionMenu());

    const appendPendingServiceRow = (category, service) => {
        const row = document.createElement('tr');
        row.className = 'service-edit-row pending-structure-row';
        row.dataset.pendingService = '';
        [service.service_number, service.code, service.name, money(BigInt(service.current_price_minor)),
            money(BigInt(service.current_price_minor)), '0%'].forEach((value) => {
            const cell = document.createElement('td');
            cell.textContent = value;
            row.appendChild(cell);
        });
        const action = document.createElement('td');
        action.className = 'structure-action-cell';
        action.appendChild(createActionToggle('service', `Действия с услугой ${service.name}`));
        row.appendChild(action);
        category.appendChild(row);
        service.row = row;
    };

    const appendPendingCategory = (category, index) => {
        const body = document.createElement('tbody');
        body.className = 'draft-category pending-structure-category';
        body.dataset.newCategoryIndex = String(index);
        const heading = document.createElement('tr');
        heading.className = 'category-row';
        const cell = document.createElement('th');
        cell.colSpan = 6;
        const title = document.createElement('span');
        title.textContent = category.name;
        const action = document.createElement('td');
        action.className = 'structure-action-cell';
        action.appendChild(createActionToggle('category', `Действия с разделом ${category.name}`));
        cell.appendChild(title);
        heading.appendChild(cell);
        heading.appendChild(action);
        body.appendChild(heading);
        editor.querySelector('.draft-table').appendChild(body);
        category.body = body;
        category.services.forEach((service) => appendPendingServiceRow(body, service));
    };

    const deleteDialog = editor.querySelector('[data-delete-dialog]');
    const deleteTitle = editor.querySelector('[data-delete-title]');
    const deleteDescription = editor.querySelector('[data-delete-description]');
    const deleteConfirm = editor.querySelector('[data-delete-confirm]');
    const deleteCancel = editor.querySelector('[data-delete-cancel]');
    let resolveDelete = null;
    let deleteConfirmed = false;
    deleteConfirm.addEventListener('click', () => {
        deleteConfirmed = true;
        deleteDialog.close();
    });
    deleteCancel.addEventListener('click', () => deleteDialog.close());
    deleteDialog.addEventListener('close', () => {
        const resolve = resolveDelete;
        resolveDelete = null;
        resolve?.(deleteConfirmed);
    });
    const confirmDeletion = (kind, name) => {
        if (resolveDelete || isSaving) return Promise.resolve(false);
        deleteConfirmed = false;
        deleteTitle.textContent = kind === 'category' ? 'Удалить раздел?'
            : kind === 'lastService' ? 'Удалить услугу и раздел?' : 'Удалить услугу?';
        deleteDescription.textContent = kind === 'category'
            ? 'Раздел «' + name + '» и все его услуги будут удалены из черновика. Изменения вступят в силу после сохранения.'
            : kind === 'lastService'
                ? `Это последняя услуга в разделе „${name}“. Удалить услугу вместе с разделом?`
            : 'Услуга «' + name + '» будет удалена из черновика. Изменения вступят в силу после сохранения.';
        return new Promise((resolve) => {
            resolveDelete = resolve;
            deleteDialog.showModal();
            deleteCancel.focus();
        });
    };
    const remainingServices = (category) => {
        const existing = [...category.querySelectorAll('[data-price-row]')]
            .filter((row) => !row.dataset.structurallyDeleted).length;
        const pending = category.dataset.categoryId
            ? newServices.filter((service) => service.category_id === category.dataset.categoryId).length
            : newCategories[Number(category.dataset.newCategoryIndex)]?.services.length || 0;
        return existing + pending;
    };
    const removeCategory = (category) => {
        if (category.dataset.categoryId) {
            for (let index = newServices.length - 1; index >= 0; index--) {
                if (newServices[index].category_id === category.dataset.categoryId) {
                    newServices[index].row.remove();
                    newServices.splice(index, 1);
                }
            }
            deletedCategoryIds.add(category.dataset.categoryId);
            category.dataset.structurallyDeleted = 'true';
            category.hidden = true;
            category.querySelectorAll('[data-price-row]').forEach((row) => {
                deletedServiceIds.delete(row.dataset.serviceId);
                row.dataset.structurallyDeleted = 'category';
                row.hidden = true;
            });
        } else {
            const item = newCategories[Number(category.dataset.newCategoryIndex)];
            if (!item) return;
            item.removed = true;
            item.services = [];
            category.remove();
        }
        update();
        flushRecovery();
        editor.querySelector('.draft-table-wrap').focus({preventScroll: true});
    };

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('button');
        if (!button || (!editor.contains(button) && !actionMenu.contains(button))) return;
        if (button.matches('[data-structure-menu-toggle]')) {
            openActionMenu(button);
            return;
        }
        if (!actionMenu.contains(button)) return;
        const source = actionSources.get(button) || activeActionToggle;
        if (!source || isSaving) return;
        const category = source.closest('.draft-category');
        closeActionMenu();
        if (button.matches('[data-add-service]') && category) {
            serviceTarget = category.dataset.categoryId
                ? {type: 'existing', id: category.dataset.categoryId, body: category}
                : {type: 'new', index: Number(category.dataset.newCategoryIndex), body: category};
            serviceForm.reset();
            editor.querySelector('[data-service-category-name]').textContent =
                category.dataset.categoryName || category.querySelector('.category-row span')?.textContent || '';
            editor.querySelector('[data-service-dialog-error]').textContent = '';
            serviceDialog.showModal();
        } else if (button.matches('[data-delete-service]')) {
            const row = source.closest('[data-price-row]');
            if (!row || !category) return;
            const last = remainingServices(category) === 1;
            const categoryName = category.dataset.categoryName || category.querySelector('.category-row span')?.textContent || '';
            if (!await confirmDeletion(last ? 'lastService' : 'service',
                last ? categoryName : row.querySelector('.service-name')?.textContent || '')) {
                source.focus({preventScroll: true});
                return;
            }
            if (last) {
                removeCategory(category);
                return;
            }
            deletedServiceIds.add(row.dataset.serviceId);
            row.dataset.structurallyDeleted = 'true';
            row.hidden = true;
            update();
            flushRecovery();
            editor.querySelector('.draft-table-wrap').focus({preventScroll: true});
        } else if (button.matches('[data-delete-category]') && category) {
            if (!await confirmDeletion('category', category.dataset.categoryName || category.querySelector('.category-row span')?.textContent || '')) {
                source.focus({preventScroll: true});
                return;
            }
            removeCategory(category);
        } else if (button.matches('[data-delete-pending-service]')) {
            const row = source.closest('[data-pending-service]');
            const service = [...newServices, ...activeNewCategories().flatMap((item) => item.services)]
                .find((item) => item.row === row);
            if (!service || !category) return;
            const last = remainingServices(category) === 1;
            const categoryName = category.dataset.categoryName || category.querySelector('.category-row span')?.textContent || '';
            if (!await confirmDeletion(last ? 'lastService' : 'service', last ? categoryName : service.name)) {
                source.focus({preventScroll: true});
                return;
            }
            if (last) {
                removeCategory(category);
                return;
            }
            const directIndex = newServices.indexOf(service);
            if (directIndex >= 0) {
                newServices.splice(directIndex, 1);
                row.remove();
            } else {
                const owner = activeNewCategories().find((item) => item.services.includes(service));
                if (owner) {
                    owner.services.splice(owner.services.indexOf(service), 1);
                    row.remove();
                }
            }
            update();
            flushRecovery();
            if (!row.isConnected) editor.querySelector('.draft-table-wrap').focus({preventScroll: true});
        } else if (button.matches('[data-delete-pending-category]') && category) {
            const item = newCategories[Number(category.dataset.newCategoryIndex)];
            if (item) {
                if (!await confirmDeletion('category', item.name)) {
                    source.focus({preventScroll: true});
                    return;
                }
                removeCategory(category);
            }
        }
    });
    editor.addEventListener('keydown', (event) => {
        const toggle = event.target.closest('[data-structure-menu-toggle]');
        if (!toggle || (event.key !== 'ArrowDown' && event.key !== 'ArrowUp')) return;
        event.preventDefault();
        openActionMenu(toggle, event.key === 'ArrowUp', false);
    });

    addCategoryButton.addEventListener('click', () => {
        setEditMenuOpen(false);
        categoryForm.reset();
        editor.querySelector('[data-category-dialog-error]').textContent = '';
        categoryDialog.showModal();
    });
    editor.querySelector('[data-service-cancel]').addEventListener('click', () => serviceDialog.close());
    editor.querySelector('[data-category-cancel]').addEventListener('click', () => categoryDialog.close());

    serviceForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const service = serviceFromFields(structureFields.serviceNumber, structureFields.serviceCode, structureFields.serviceName, structureFields.servicePrice);
        if (!service || !serviceTarget) {
            editor.querySelector('[data-service-dialog-error]').textContent = 'Заполните все поля корректно.';
            return;
        }
        if (serviceTarget.type === 'existing') {
            service.category_id = serviceTarget.id;
            newServices.push(service);
        } else {
            newCategories[serviceTarget.index].services.push(service);
        }
        appendPendingServiceRow(serviceTarget.body, service);
        serviceDialog.close();
        update();
    });

    categoryForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const name = structureFields.categoryName.value.trim();
        const service = serviceFromFields(structureFields.categoryServiceNumber, structureFields.categoryServiceCode, structureFields.categoryServiceName, structureFields.categoryServicePrice);
        if (name === '' || !service) {
            editor.querySelector('[data-category-dialog-error]').textContent = 'Заполните название раздела и первую услугу.';
            return;
        }
        const category = {name, services: [service], removed: false};
        newCategories.push(category);
        appendPendingCategory(category, newCategories.length - 1);
        categoryDialog.close();
        update();
    });

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
            if (rows[index].dataset.structurallyDeleted) continue;
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
        rawPercentEdits.clear();
        rows.forEach((row, index) => {
            if (row.dataset.structurallyDeleted) return;
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
            if (row.dataset.structurallyDeleted) return;
            const imported = BigInt(row.dataset.importedMinor);
            const loaded = BigInt(row.dataset.loadedMinor);
            const input = inputs[index];
            const percentInput = percentInputs[index];
            const current = parseMinor(input.value);
            originalTotal += imported;
            row.classList.remove('price-increased', 'price-decreased', 'price-unchanged', 'price-changed', 'price-invalid');
            input.removeAttribute('aria-invalid');
            percentInput.removeAttribute('aria-invalid');

            const hasRawPercent = index === percentSourceIndex || rawPercentEdits.has(index);
            const editedPercent = hasRawPercent ? parsePercent(percentInput.value) : null;
            const priceForEditedPercent = editedPercent === null ? null : priceFromPercent(imported, editedPercent);
            if (current === null || (hasRawPercent && priceForEditedPercent === null)) {
                invalid++;
                row.classList.add('price-invalid');
                (current === null ? input : percentInput).setAttribute('aria-invalid', 'true');
                return;
            }
            currentTotal += current;
            if (!hasRawPercent) percentInput.value = percentValue(current, imported);
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
        const structuralChanges = structuralChangeCount();
        const emptyPrice = remainingCategoryCount() === 0;
        hasValidUnsavedChanges = unsaved > 0 || structuralChanges > 0;
        invalidCount = invalid;
        changedFilterCount.textContent = `(${changed})`;
        changedFilter.disabled = changed === 0;
        if (changed === 0 && changedOnly) {
            changedOnly = false;
            changedFilter.setAttribute('aria-checked', 'false');
        }
        summary.increased.textContent = String(increased);
        summary.decreased.textContent = String(decreased);
        summary.original.textContent = money(originalTotal);
        summary.current.textContent = invalid === 0 ? money(currentTotal) : '—';
        summary.percent.textContent = invalid === 0 && originalTotal > 0n ? percent(currentTotal, originalTotal) : '—';
        summary.section.hidden = changed === 0;
        summary.increasedRow.hidden = increased === 0;
        summary.decreasedRow.hidden = decreased === 0;
        summary.percentRow.hidden = changed === 0;
        summary.state.textContent = invalid > 0
            ? `Ошибок в ценах: ${invalid}`
            : emptyPrice ? emptyPriceWarning
            : structuralChanges > 0
                ? `Несохранённых структурных изменений: ${structuralChanges}`
                : unsaved > 0 ? `Несохранённых изменений: ${unsaved}` : 'Нет несохранённых изменений.';
        summary.state.hidden = summary.state.textContent.trim() === '';
        summary.state.classList.toggle('has-errors', invalid > 0);
        summary.state.classList.toggle('is-modified', hasValidUnsavedChanges && invalid === 0);
        editor.classList.toggle('is-modified', hasValidUnsavedChanges);
        if (emptyPrice) {
            saveMessage.textContent = emptyPriceWarning;
            saveMessage.classList.add('has-errors');
        } else if (saveMessage.textContent === emptyPriceWarning) {
            saveMessage.textContent = '';
            saveMessage.classList.remove('has-errors');
        }
        massPriceMenuCommand.disabled = isSaving;
        massPriceInput.disabled = isSaving;
        massPriceApplyButton.disabled = isSaving;
        massPriceConfirmButton.disabled = isSaving;
        saveButton.disabled = !hasValidUnsavedChanges || invalid > 0 || emptyPrice || isSaving;
        saveButton.hidden = !hasValidUnsavedChanges && invalid === 0;
        publishButton.disabled = hasValidUnsavedChanges || invalid > 0 || isSaving;
        const alreadyPublished = isCurrentPublishedClone && changed === 0 && !hasValidUnsavedChanges && invalid === 0;
        publishButton.hidden = alreadyPublished || hasValidUnsavedChanges;
        publicationState.hidden = !alreadyPublished;
        draftStatus.textContent = alreadyPublished ? 'Опубликован' : 'Черновик';
        draftStatus.className = alreadyPublished ? 'status-published-badge' : 'status-draft';
        draftStatus.hidden = false;
        const statusAnnouncement = visibleStatusMessages().map((message) => message.textContent.trim()).join(' ');
        if (statusLive.textContent !== statusAnnouncement) statusLive.textContent = statusAnnouncement;
        syncStatusTooltip();
        downloadButton.disabled = isSaving;
        resetButton.hidden = (!hasValidUnsavedChanges && invalid === 0) || isSaving;
        filterServices();
    }

    inputs.forEach((input, index) => {
        input.addEventListener('input', () => {
            rawPercentEdits.delete(index);
            update();
        });
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
            rawPercentEdits.set(index, input.value);
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
        input.addEventListener('blur', () => {
            if (parsePercent(input.value) !== null) rawPercentEdits.delete(index);
            update();
        });
    });

    massPriceMenuCommand.addEventListener('click', () => {
        setEditMenuOpen(false);
        massPriceInput.value = '';
        setMassPriceMessage('');
        massPriceEntryDialog.showModal();
    });
    massPriceEntryCancelButton.addEventListener('click', () => massPriceEntryDialog.close());
    massPriceEntryDialog.addEventListener('close', () => {
        if (skipMassPriceEntryFocusReturn) {
            skipMassPriceEntryFocusReturn = false;
            return;
        }
        focusMassPriceMenuCommand();
    });

    massPriceForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const parsedPercent = validateMassPriceChange();
        if (parsedPercent === null) return;
        const direction = parsedPercent.value > 0n ? 'увеличены' : 'уменьшены';
        massPriceDialogMessage.textContent = `Все текущие цены будут ${direction} на ${formattedPercent(parsedPercent)}%. Будет изменено ${rows.length} позиций.`;
        skipMassPriceEntryFocusReturn = true;
        massPriceEntryDialog.close();
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

    massPriceCancelButton.addEventListener('click', () => {
        returnToMassPriceEntry = true;
        closeMassPriceDialog();
    });
    massPriceDialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        returnToMassPriceEntry = true;
        closeMassPriceDialog();
    });
    massPriceDialog.addEventListener('close', () => {
        if (returnToMassPriceEntry) {
            returnToMassPriceEntry = false;
            massPriceEntryDialog.showModal();
            massPriceInput.focus();
            return;
        }
        focusMassPriceMenuCommand();
    });
    massPriceDialog.addEventListener('click', (event) => {
        if (event.target !== massPriceDialog) return;
        const bounds = massPriceDialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) {
            returnToMassPriceEntry = true;
            closeMassPriceDialog();
        }
    });
    massPriceConfirmButton.addEventListener('click', () => {
        const parsedPercent = validateMassPriceChange();
        if (parsedPercent === null) {
            returnToMassPriceEntry = true;
            closeMassPriceDialog();
            return;
        }
        applyMassPriceChange(parsedPercent);
        returnToMassPriceEntry = false;
        closeMassPriceDialog();
    });

    resetButton.addEventListener('click', () => {
        clearRecovery();
        rawPercentEdits.clear();
        if (structuralChangeCount() > 0) {
            recoverySuspended = true;
            isExitConfirmed = true;
            window.location.reload();
            return;
        }
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

    async function saveDraft({reloadOnStructure = true} = {}) {
        update();
        if (!hasValidUnsavedChanges || invalidCount > 0 || remainingCategoryCount() === 0 || isSaving) return false;

        const prices = rows
            .filter((row) => !row.dataset.structurallyDeleted)
            .map((row) => {
                const index = rows.indexOf(row);
                return {service_id: row.dataset.serviceId, current_price_minor: String(parseMinor(inputs[index].value))};
            });
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
                    deleted_service_ids: [...deletedServiceIds],
                    deleted_category_ids: [...deletedCategoryIds],
                    new_services: newServices.map(({row, ...service}) => service),
                    new_categories: activeNewCategories().map(({body, removed, ...category}) => ({
                        ...category,
                        services: category.services.map(({row, ...service}) => service),
                    })),
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

            clearRecovery();
            rawPercentEdits.clear();
            if (structuralChangeCount() > 0) {
                recoverySuspended = true;
                isExitConfirmed = reloadOnStructure;
                if (reloadOnStructure) window.location.reload();
                return true;
            }
            rows.forEach((row, index) => {
                const current = parseMinor(inputs[index].value);
                row.dataset.loadedMinor = String(current);
                inputs[index].value = decimal(current);
            });
            editor.dataset.revision = String(result.revision);
            rememberBaseline();
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

    const setExitDialogMessage = (message, isError = false) => {
        exitDialogMessage.textContent = message;
        exitDialogMessage.hidden = message === '';
        exitDialogMessage.classList.toggle('has-errors', isError);
    };
    exitLink.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        setDocumentMenuOpen(false);
        update();
        if (isSaving) {
            event.preventDefault();
            return;
        }
        if (!hasValidUnsavedChanges && invalidCount === 0) return;
        event.preventDefault();
        setExitDialogMessage(invalidCount > 0
            ? `Некорректных цен: ${invalidCount}. Исправьте их перед сохранением.` : '', invalidCount > 0);
        exitSaveButton.disabled = invalidCount > 0;
        exitDialog.showModal();
    });
    exitDialog.addEventListener('close', () => {
        if (!isExitConfirmed) {
            setDocumentMenuOpen(true);
            exitLink.focus();
        }
    });
    exitDialog.addEventListener('cancel', (event) => {
        if (isExitSaving) event.preventDefault();
    });
    exitCancelButton.addEventListener('click', () => {
        if (!isExitSaving) exitDialog.close();
    });
    exitDiscardButton.addEventListener('click', () => {
        if (isExitSaving) return;
        clearRecovery();
        isExitConfirmed = true;
        window.location.assign(exitLink.href);
    });
    exitSaveButton.addEventListener('click', async () => {
        if (isExitSaving || isSaving) return;
        update();
        if (invalidCount > 0) {
            exitSaveButton.disabled = true;
            setExitDialogMessage(`Некорректных цен: ${invalidCount}. Исправьте их перед сохранением.`, true);
            return;
        }
        isExitSaving = true;
        exitSaveButton.disabled = true;
        exitDiscardButton.disabled = true;
        exitCancelButton.disabled = true;
        setExitDialogMessage('Сохраняем изменения…');
        try {
            const saved = await saveDraft({reloadOnStructure: false});
            if (!saved) {
                setExitDialogMessage(saveMessage.textContent || 'Не удалось сохранить изменения. Попробуйте ещё раз.', true);
                return;
            }
            isExitConfirmed = true;
            window.location.assign(exitLink.href);
        } catch (error) {
            isExitConfirmed = false;
            setExitDialogMessage('Не удалось сохранить изменения и выйти. Изменения остались в редакторе.', true);
        } finally {
            if (isExitConfirmed) return;
            isExitSaving = false;
            exitSaveButton.disabled = invalidCount > 0;
            exitDiscardButton.disabled = false;
            exitCancelButton.disabled = false;
        }
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
            clearRecovery();
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
        if (isSaving) return;
        setDocumentMenuOpen(false);
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
        flushRecovery();
        if (isExitConfirmed || (!hasValidUnsavedChanges && invalidCount === 0)) return;
        event.preventDefault();
        event.returnValue = '';
    });

    let scrollbarHideTimer;
    scrollport.addEventListener('scroll', () => {
        scrollport.classList.add('is-scrolling');
        window.clearTimeout(scrollbarHideTimer);
        scrollbarHideTimer = window.setTimeout(() => scrollport.classList.remove('is-scrolling'), 900);
        scheduleRecovery();
    }, {passive: true});
    window.addEventListener('pagehide', flushRecovery);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') flushRecovery();
    });
    update();
    initializeRecovery();
})();

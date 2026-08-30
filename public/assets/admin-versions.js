(() => {
    'use strict';

    const uploadAccordion = document.querySelector('[data-upload-accordion]');
    const uploadToggle = uploadAccordion?.querySelector('[data-upload-accordion-toggle]');
    const uploadContent = uploadAccordion?.querySelector('[data-upload-accordion-content]');
    if (uploadToggle && uploadContent) {
        uploadToggle.addEventListener('click', () => {
            const expanded = uploadToggle.getAttribute('aria-expanded') !== 'true';
            uploadToggle.setAttribute('aria-expanded', String(expanded));
            uploadContent.hidden = !expanded;
        });
    }

    const editAccordion = document.querySelector('[data-edit-accordion]');
    const editToggle = editAccordion?.querySelector('[data-edit-accordion-toggle]');
    const editContent = editAccordion?.querySelector('[data-edit-accordion-content]');
    if (editToggle && editContent) {
        editToggle.addEventListener('click', () => {
            const expanded = editToggle.getAttribute('aria-expanded') !== 'true';
            editToggle.setAttribute('aria-expanded', String(expanded));
            editContent.hidden = !expanded;
        });
    }

    const uploadForm = document.querySelector('[data-upload-form]');
    const fileInput = uploadForm?.querySelector('[data-file-input]');
    const fileName = uploadForm?.querySelector('[data-file-name]');
    const uploadStatus = uploadForm?.querySelector('[data-upload-status]');
    const uploadStatusText = uploadForm?.querySelector('[data-upload-status-text]');
    const uploadSpinner = uploadForm?.querySelector('[data-upload-spinner]');
    const uploadResult = document.querySelector('[data-upload-result]');
    let uploadRequest = 0;
    let uploadController = null;

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
    })[character]);
    const humanDate = (value) => {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return 'не указана';
        const [year, month, day] = value.split('-').map(Number);
        return new Intl.DateTimeFormat('ru-RU', {day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC'})
            .format(new Date(Date.UTC(year, month - 1, day)));
    };
    const setUploadStatus = (state, message) => {
        uploadStatus.hidden = false;
        uploadStatus.className = `upload-check-status is-${state}`;
        uploadStatusText.textContent = message;
        uploadSpinner.hidden = state !== 'checking';
    };
    const renderReview = (review) => {
        if (!review) {
            uploadResult.replaceChildren();
            return;
        }
        const currentText = review.expected_published_version_id
            ? `Прайс ещё не опубликован. На сайте продолжает действовать прайс от ${escapeHtml(humanDate(review.current_price_date))}.`
            : 'Прайс ещё не опубликован. Сейчас на сайте нет прайс-листа.';
        uploadResult.innerHTML = `<div class="upload-review"><h3>Новый прайс готов к публикации</h3>
            <div class="notice success"><strong>Прайс можно опубликовать</strong><br>Структура файла корректна. Ошибок не найдено.</div>
            <dl class="status-grid"><div><dt>Файл</dt><dd>${escapeHtml(review.original_filename)}</dd></div>
            <div><dt>Дата прайса</dt><dd>${escapeHtml(humanDate(review.price_date))}</dd></div>
            <div><dt>Разделов</dt><dd>${escapeHtml(review.sections)}</dd></div>
            <div><dt>Услуг в новом прайсе</dt><dd>${escapeHtml(review.items)}</dd></div>
            <div><dt>Сейчас на сайте</dt><dd>${escapeHtml(review.current_items)} услуг</dd></div></dl>
            <p class="reassurance">${currentText}</p><div class="review-actions">
            <button type="button" data-review-publish data-version-id="${escapeHtml(review.version_id)}" data-revision="${escapeHtml(review.revision)}" data-published-version-id="${escapeHtml(review.expected_published_version_id || '')}">Опубликовать загруженный прайс на сайте</button>
            <a class="button-link button-secondary" href="/admin/">Отменить и вернуться</a></div></div>`;
    };

    if (uploadForm && fileInput && fileName && uploadStatus && uploadStatusText && uploadSpinner && uploadResult) {
        uploadForm.addEventListener('submit', (event) => event.preventDefault());
        fileInput.addEventListener('change', async () => {
            const file = fileInput.files?.[0];
            fileName.textContent = file?.name || 'Файл не выбран';
            uploadController?.abort();
            const request = ++uploadRequest;
            uploadResult.replaceChildren();
            if (!file) {
                uploadStatus.hidden = true;
                return;
            }
            uploadToggle?.setAttribute('aria-expanded', 'true');
            if (uploadContent) uploadContent.hidden = false;
            setUploadStatus('checking', 'Проверяем файл…');
            uploadController = new AbortController();
            try {
                const response = await fetch(uploadForm.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                    body: new FormData(uploadForm),
                    signal: uploadController.signal,
                });
                const result = await response.json().catch(() => null);
                if (request !== uploadRequest) return;
                if (!response.ok || !result?.ok) {
                    setUploadStatus('error', result?.message || 'Не удалось проверить файл. Попробуйте ещё раз.');
                    uploadResult.replaceChildren();
                    return;
                }
                setUploadStatus('success', result.review
                    ? (result.status_message || 'Файл проверен, ошибок не найдено.')
                    : (result.message || 'Файл проверен, публикация не требуется.'));
                renderReview(result.review);
            } catch (error) {
                if (error.name !== 'AbortError' && request === uploadRequest) {
                    setUploadStatus('error', 'Сеть недоступна. Не удалось проверить файл.');
                    uploadResult.replaceChildren();
                }
            }
        });
    }

    const panel = document.querySelector('[data-version-actions]');
    if (!panel) return;
    const message = panel.querySelector('[data-version-message]');
    const uploadPublishDialog = document.querySelector('[data-upload-publish-dialog]');
    const uploadPublishCancel = uploadPublishDialog?.querySelector('[data-upload-publish-cancel]');
    const uploadPublishConfirm = uploadPublishDialog?.querySelector('[data-upload-publish-confirm]');
    const uploadPublishMessage = uploadPublishDialog?.querySelector('[data-upload-publish-message]');
    let pendingReviewButton = null;
    let reviewPublishing = false;

    document.addEventListener('click', (event) => {
        const reviewButton = event.target.closest('[data-review-publish]');
        if (!reviewButton || reviewPublishing) return;
        pendingReviewButton = reviewButton;
        uploadPublishMessage.hidden = true;
        uploadPublishMessage.textContent = '';
        uploadPublishDialog.showModal();
    });

    uploadPublishCancel?.addEventListener('click', () => {
        if (!reviewPublishing) uploadPublishDialog.close();
    });
    uploadPublishDialog?.addEventListener('click', (event) => {
        if (event.target !== uploadPublishDialog || reviewPublishing) return;
        const bounds = uploadPublishDialog.getBoundingClientRect();
        const outside = event.clientX < bounds.left || event.clientX > bounds.right
            || event.clientY < bounds.top || event.clientY > bounds.bottom;
        if (outside) uploadPublishDialog.close();
    });
    uploadPublishDialog?.addEventListener('cancel', (event) => {
        if (reviewPublishing) event.preventDefault();
    });
    uploadPublishConfirm?.addEventListener('click', async () => {
        if (!pendingReviewButton || reviewPublishing) return;
        let publicationCompleted = false;
        reviewPublishing = true;
        pendingReviewButton.disabled = true;
        uploadPublishConfirm.disabled = true;
        uploadPublishCancel.disabled = true;
        uploadPublishConfirm.textContent = 'Публикуем…';
        uploadPublishMessage.hidden = true;
        try {
            const response = await fetch('/admin/publish-version.php', {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': panel.dataset.csrfToken},
                body: JSON.stringify({version_id: pendingReviewButton.dataset.versionId, expected_revision: pendingReviewButton.dataset.revision, expected_published_version_id: pendingReviewButton.dataset.publishedVersionId || null}),
            });
            const result = await response.json().catch(() => null);
            if (!response.ok || !result?.ok) {
                uploadPublishMessage.textContent = result?.message || 'Не удалось опубликовать прайс. Обновите страницу и попробуйте снова.';
                uploadPublishMessage.hidden = false;
                return;
            }
            publicationCompleted = true;
            panel.dataset.publishedVersionId = pendingReviewButton.dataset.versionId;
            pendingReviewButton.textContent = 'Прайс опубликован';
            setUploadStatus('success', 'Прайс опубликован.');
            uploadPublishDialog.close();
        } catch (error) {
            uploadPublishMessage.textContent = 'Сеть недоступна. Прайс не опубликован.';
            uploadPublishMessage.hidden = false;
        } finally {
            reviewPublishing = false;
            if (!publicationCompleted) pendingReviewButton.disabled = false;
            uploadPublishConfirm.disabled = false;
            uploadPublishCancel.disabled = false;
            uploadPublishConfirm.textContent = 'Опубликовать';
        }
    });

    panel.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-version-action]');
        if (!button || button.disabled) return;
        const publish = button.dataset.versionAction === 'publish';
        if (publish && !window.confirm('Опубликовать этот прайс? Текущий прайс на сайте останется в истории.')) return;
        if (!publish && !window.confirm('Подготовить предыдущий прайс для редактирования?')) return;

        button.disabled = true;
        message.textContent = publish ? 'Публикация…' : 'Восстановление…';
        message.className = 'version-message';
        const body = {version_id: button.dataset.versionId};
        if (publish) {
            body.expected_revision = button.dataset.revision;
            body.expected_published_version_id = panel.dataset.publishedVersionId || null;
        }
        try {
            const response = await fetch(publish ? '/admin/publish-version.php' : '/admin/restore-version.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': panel.dataset.csrfToken},
                body: JSON.stringify(body),
            });
            const result = await response.json().catch(() => null);
            if (!response.ok || !result?.ok) {
                message.textContent = result?.message || 'Не удалось выполнить действие.';
                message.classList.add('has-errors');
                button.disabled = false;
                return;
            }
            if (publish) window.location.reload();
            else window.location.assign(`/admin/draft.php?id=${result.draft_version_id}`);
        } catch (error) {
            message.textContent = 'Сеть недоступна. Действие не выполнено.';
            message.classList.add('has-errors');
            button.disabled = false;
        }
    });
})();

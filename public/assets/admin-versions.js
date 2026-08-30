(() => {
    'use strict';

    const fileInput = document.querySelector('[data-file-input]');
    const fileName = document.querySelector('[data-file-name]');
    if (fileInput && fileName) {
        fileInput.addEventListener('change', () => {
            fileName.textContent = fileInput.files?.[0]?.name || 'Файл не выбран';
        });
    }

    const panel = document.querySelector('[data-version-actions]');
    if (!panel) return;
    const message = panel.querySelector('[data-version-message]');

    const reviewButton = document.querySelector('[data-review-publish]');
    if (reviewButton) {
        reviewButton.addEventListener('click', async () => {
            if (!window.confirm('Опубликовать новый прайс на сайте?')) return;
            reviewButton.disabled = true;
            try {
                const response = await fetch('/admin/publish-version.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': panel.dataset.csrfToken},
                    body: JSON.stringify({version_id: reviewButton.dataset.versionId, expected_revision: reviewButton.dataset.revision, expected_published_version_id: reviewButton.dataset.publishedVersionId || null}),
                });
                const result = await response.json().catch(() => null);
                if (!response.ok || !result?.ok) { window.alert(result?.message || 'Не удалось опубликовать прайс. Обновите страницу и попробуйте снова.'); reviewButton.disabled = false; return; }
                window.location.reload();
            } catch (error) { window.alert('Сеть недоступна. Прайс не опубликован.'); reviewButton.disabled = false; }
        });
    }

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

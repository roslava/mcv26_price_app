(() => {
    'use strict';

    const panel = document.querySelector('[data-version-actions]');
    if (!panel) return;
    const message = panel.querySelector('[data-version-message]');

    panel.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-version-action]');
        if (!button || button.disabled) return;
        const publish = button.dataset.versionAction === 'publish';
        if (publish && !window.confirm('Опубликовать этот черновик? Текущая опубликованная версия будет архивирована.')) return;
        if (!publish && !window.confirm('Создать новый черновик из этой архивной версии?')) return;

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

<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/admin_bootstrap.php';

if (!$adminSession->isAuthenticated()) {
    admin_redirect('login.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    admin_page_start('Метод не поддерживается');
    ?>
    <section class="card">
        <h1>Метод не поддерживается</h1>
        <p>Выход выполняется только из административной страницы.</p>
        <a class="button-link" href="<?= admin_e(admin_url('')) ?>">Вернуться</a>
    </section>
    <?php
    admin_page_end();
    exit;
}

if (!$adminSession->validateCsrf($_POST['csrf_token'] ?? null)) {
    $adminSession->setFlash(['type' => 'error', 'message' => 'Не удалось проверить запрос выхода.']);
    admin_redirect('');
}

$adminSession->logout();
admin_redirect('login.php?logged_out=1');

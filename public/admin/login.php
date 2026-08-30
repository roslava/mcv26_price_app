<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/admin_bootstrap.php';

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

if ($adminSession->isAuthenticated()) {
    admin_redirect('');
}

$configurationError = $adminSession->configurationError();
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($configurationError !== null) {
        $error = $configurationError;
    } elseif (!$adminSession->validateCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Сессия формы устарела. Обновите страницу и попробуйте снова.';
    } else {
        $login = is_string($_POST['login'] ?? null) ? $_POST['login'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        if ($adminSession->authenticate($login, $password)) {
            admin_redirect('');
        }
        $error = 'Неверный логин или пароль.';
    }
}

admin_page_start('Вход');
?>
<section class="card login-card">
    <h1>Вход в административную часть</h1>
    <p class="muted">Введите данные администратора медицинского центра.</p>

    <?php if (isset($_GET['logged_out'])): ?>
        <div class="notice success" role="status">Вы вышли из административной части.</div>
    <?php endif; ?>

    <?php if ($configurationError !== null): ?>
        <div class="notice error" role="alert"><?= admin_e($configurationError) ?></div>
    <?php elseif ($error !== null): ?>
        <div class="notice error" role="alert"><?= admin_e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= admin_e(admin_url('login.php')) ?>" class="form-stack">
        <input type="hidden" name="csrf_token" value="<?= admin_e($adminSession->csrfToken()) ?>">
        <label>
            <span>Логин</span>
            <input type="text" name="login" autocomplete="username" required>
        </label>
        <label>
            <span>Пароль</span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button type="submit"<?= $configurationError !== null ? ' disabled' : '' ?>>Войти</button>
    </form>
</section>
<?php
admin_page_end();

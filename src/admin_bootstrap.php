<?php

declare(strict_types=1);

use Mcv26\Price\AdminSession;

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $adminSession = AdminSession::start();
} catch (Throwable $exception) {
    error_log('Admin bootstrap failed: ' . $exception->getMessage());
    http_response_code(500);
    echo '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Ошибка</title>';
    echo '<p>Административная страница временно недоступна.</p>';
    exit;
}

$projectRoot = dirname(__DIR__);
$storageDirectory = $projectRoot . '/storage';

function admin_e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function admin_page_start(
    string $title,
    string $shellClass = '',
    ?string $logoutCsrfToken = null,
    ?string $headerLinkHref = null,
    ?string $headerLinkLabel = null,
    bool $showPriceSearch = false
): void
{
    ?>
    <!doctype html>
    <html lang="ru">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= admin_e($title) ?> — MCV26</title>
        <link rel="stylesheet" href="/assets/admin.css">
    </head>
    <body>
    <main class="admin-shell<?= $shellClass !== '' ? ' ' . admin_e($shellClass) : '' ?>">
        <header class="site-header">
            <div class="site-header-brand">
                <img class="site-logo" src="/assets/mcv26_logo_h.png" alt="Медицинский Центр Власова">
                <span>Управление прайс-листом</span>
            </div>
            <?php if ($showPriceSearch): ?>
                <div class="site-header-search" role="search">
                    <input type="search" placeholder="Поиск по услугам" aria-label="Поиск по услугам" autocomplete="off" data-service-search>
                    <button type="button" aria-label="Очистить поиск" title="Очистить поиск" data-service-search-clear hidden>×</button>
                </div>
            <?php endif; ?>
            <?php if ($logoutCsrfToken !== null): ?>
                <form class="site-header-logout" method="post" action="/admin/logout.php">
                    <input type="hidden" name="csrf_token" value="<?= admin_e($logoutCsrfToken) ?>">
                    <button type="submit" class="button-secondary">Выйти</button>
                </form>
            <?php elseif ($headerLinkHref !== null && $headerLinkLabel !== null): ?>
                <a class="site-header-link" href="<?= admin_e($headerLinkHref) ?>"><?= admin_e($headerLinkLabel) ?></a>
            <?php endif; ?>
        </header>
    <?php
}

function admin_page_end(): void
{
    ?>
    </main>
    </body>
    </html>
    <?php
}

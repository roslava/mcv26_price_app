<?php

declare(strict_types=1);

use Mcv26\Price\AdminSession;

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
    $adminSession = AdminSession::start();
} catch (Throwable $exception) {
    error_log('Admin bootstrap failed: ' . $exception->getMessage());
    http_response_code(500);
    echo '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Ошибка</title>';
    echo '<p>Административная страница временно недоступна.</p>';
    exit;
}

$projectRoot = dirname(__DIR__, 2);
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

function admin_page_start(string $title): void
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
    <main class="admin-shell">
        <header class="site-header">
            <span class="brand-mark" aria-hidden="true">+</span>
            <div>
                <strong>MCV26</strong>
                <span>Управление прайс-листом</span>
            </div>
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

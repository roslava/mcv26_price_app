<?php

declare(strict_types=1);

use Mcv26\Price\Admin\CurrentPublishedVersionEditorStarter;
use Mcv26\Price\Database\DatabaseConfig;
use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\Database\PdoConnectionFactory;
use Mcv26\Price\Exception\VersionActionException;

require dirname(__DIR__, 2) . '/src/admin_bootstrap.php';

if (!$adminSession->isAuthenticated()) admin_redirect('login.php');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
if (!$adminSession->validateCsrf($_POST['csrf_token'] ?? null)) {
    $adminSession->setFlash(['type' => 'error', 'message' => 'Сессия формы устарела. Обновите страницу и попробуйте снова.']);
    admin_redirect('');
}

try {
    $pdo = PdoConnectionFactory::create(DatabaseConfig::fromEnvironment());
    $result = (new CurrentPublishedVersionEditorStarter(
        $pdo,
        new DatabasePriceRepository($pdo)
    ))->start();
    admin_redirect('draft.php?id=' . $result['draft_version_id']);
} catch (VersionActionException $exception) {
    $adminSession->setFlash(['type' => 'error', 'message' => $exception->getMessage()]);
    admin_redirect('');
} catch (Throwable $exception) {
    error_log('Current price edit start failed: ' . $exception->getMessage());
    $adminSession->setFlash(['type' => 'error', 'message' => 'Не удалось открыть прайс для редактирования.']);
    admin_redirect('');
}

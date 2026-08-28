<?php

declare(strict_types=1);

use Mcv26\Price\Admin\DraftEditorPage;
use Mcv26\Price\Database\DatabaseConfig;
use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\Database\PdoConnectionFactory;

require dirname(__DIR__, 2) . '/src/admin_bootstrap.php';

if (!$adminSession->isAuthenticated()) {
    admin_redirect('/admin/login.php');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$submittedId = $_GET['id'] ?? null;
if (!is_string($submittedId) || !preg_match('/^[1-9]\d*$/D', $submittedId)) {
    $adminSession->setFlash(['type' => 'error', 'message' => 'Некорректный номер черновика.']);
    admin_redirect('/admin/');
}

try {
    $pdo = PdoConnectionFactory::create(DatabaseConfig::fromEnvironment());
    $page = new DraftEditorPage(new DatabasePriceRepository($pdo));
    $version = $page->loadDraft((int) $submittedId);
} catch (RuntimeException $exception) {
    $adminSession->setFlash(['type' => 'error', 'message' => 'Черновик не найден или недоступен для редактирования.']);
    admin_redirect('/admin/');
} catch (Throwable $exception) {
    error_log('Draft editor load failed: ' . $exception->getMessage());
    http_response_code(500);
    admin_page_start('Ошибка');
    echo '<section class="notice error" role="alert">Не удалось загрузить черновик.</section>';
    admin_page_end();
    exit;
}

admin_page_start('Редактор черновика', 'admin-shell-wide');
echo $page->render($version);
?>
<script src="/assets/admin-draft.js" defer></script>
<?php
admin_page_end();

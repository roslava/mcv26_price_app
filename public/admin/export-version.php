<?php

declare(strict_types=1);

use Mcv26\Price\Admin\PriceVersionXlsxExporter;
use Mcv26\Price\Admin\VersionActionRequest;
use Mcv26\Price\Database\DatabaseConfig;
use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\Database\PdoConnectionFactory;
use Mcv26\Price\Exception\PriceVersionExportException;
use Mcv26\Price\Exception\VersionActionException;

require dirname(__DIR__, 2) . '/src/admin_bootstrap.php';

if (!$adminSession->isAuthenticated()) {
    admin_redirect('login.php');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo 'Метод не поддерживается.';
    exit;
}
$submittedId = $_GET['id'] ?? null;
try {
    $versionId = VersionActionRequest::positiveInteger($submittedId);
} catch (VersionActionException) {
    http_response_code(404);
    echo 'Версия не найдена.';
    exit;
}

$base = tempnam(sys_get_temp_dir(), 'mcv26_export_');
if ($base === false) {
    http_response_code(500);
    echo 'Не удалось подготовить файл.';
    exit;
}
$temporary = $base . '.xlsx';
@unlink($base);
try {
    $pdo = PdoConnectionFactory::create(DatabaseConfig::fromEnvironment());
    $result = (new PriceVersionXlsxExporter(new DatabasePriceRepository($pdo)))->export(
        $versionId,
        $temporary
    );
    $size = filesize($temporary);
    if ($size === false) {
        throw new RuntimeException('Export size is unavailable.');
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
    header('Content-Length: ' . $size);
    header('X-Content-Type-Options: nosniff');
    if (readfile($temporary) === false) {
        throw new RuntimeException('Could not stream export.');
    }
} catch (PriceVersionExportException $exception) {
    http_response_code(404);
    echo 'Версия не найдена или не содержит данных.';
} catch (Throwable $exception) {
    error_log('Price version export failed: ' . $exception->getMessage());
    http_response_code(500);
    echo 'Не удалось сформировать XLSX.';
} finally {
    @unlink($temporary);
}

<?php

declare(strict_types=1);

use Mcv26\Price\Admin\VersionActionRequest;
use Mcv26\Price\Admin\VersionPublisher;
use Mcv26\Price\Database\DatabaseConfig;
use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\Database\PdoConnectionFactory;
use Mcv26\Price\Exception\VersionActionException;

require dirname(__DIR__, 2) . '/src/admin_bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

function publish_version_response(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!$adminSession->isAuthenticated()) {
    publish_version_response(401, ['ok' => false, 'error' => 'authentication_required', 'message' => 'Требуется вход.']);
}

try {
    $request = VersionActionRequest::parse(
        (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
        (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
        (string) file_get_contents('php://input'),
        $adminSession->validateCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)
    );
    $pdo = PdoConnectionFactory::create(DatabaseConfig::fromEnvironment());
    $result = (new VersionPublisher($pdo, new DatabasePriceRepository($pdo)))->publish(
        VersionActionRequest::positiveInteger($request['version_id'] ?? null),
        VersionActionRequest::nonNegativeInteger($request['expected_revision'] ?? null),
        VersionActionRequest::nullablePositiveInteger($request['expected_published_version_id'] ?? null)
    );
    publish_version_response(200, ['ok' => true] + $result + ['message' => 'Версия опубликована.']);
} catch (VersionActionException $exception) {
    publish_version_response($exception->httpStatus, [
        'ok' => false, 'error' => $exception->errorCode, 'message' => $exception->getMessage(),
    ]);
} catch (Throwable $exception) {
    error_log('Version publication failed: ' . $exception->getMessage());
    publish_version_response(500, ['ok' => false, 'error' => 'internal_error', 'message' => 'Не удалось опубликовать версию.']);
}

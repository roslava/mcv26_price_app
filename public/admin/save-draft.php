<?php

declare(strict_types=1);

use Mcv26\Price\Admin\DraftPriceSaver;
use Mcv26\Price\Admin\DraftSaveRequest;
use Mcv26\Price\Database\DatabaseConfig;
use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\Database\PdoConnectionFactory;
use Mcv26\Price\Exception\DraftSaveException;

require dirname(__DIR__, 2) . '/src/admin_bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

function draft_save_response(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!$adminSession->isAuthenticated() || $adminSession->identity() === null) {
    draft_save_response(401, ['ok' => false, 'error' => 'authentication_required', 'message' => 'Требуется вход.']);
}

try {
    $request = DraftSaveRequest::parse(
        (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
        (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
        (string) file_get_contents('php://input'),
        $adminSession->validateCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)
    );
    $pdo = PdoConnectionFactory::create(DatabaseConfig::fromEnvironment());
    $result = (new DraftPriceSaver($pdo, new DatabasePriceRepository($pdo)))->save(
        $request['version_id'],
        $request['expected_revision'],
        $request['prices'],
        $adminSession->identity()
    );
    draft_save_response(200, [
        'ok' => true,
        'revision' => $result['revision'],
        'changed' => $result['changed'],
        'message' => 'Изменения сохранены.',
    ]);
} catch (DraftSaveException $exception) {
    draft_save_response($exception->httpStatus, [
        'ok' => false,
        'error' => $exception->errorCode,
        'message' => $exception->getMessage(),
    ]);
} catch (Throwable $exception) {
    error_log('Draft save failed: ' . $exception->getMessage());
    draft_save_response(500, [
        'ok' => false,
        'error' => 'internal_error',
        'message' => 'Не удалось сохранить изменения.',
    ]);
}

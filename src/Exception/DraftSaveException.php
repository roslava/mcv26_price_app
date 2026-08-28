<?php

declare(strict_types=1);

namespace Mcv26\Price\Exception;

use RuntimeException;

final class DraftSaveException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $message): self
    {
        return new self('invalid_request', 422, $message);
    }

    public static function notFound(): self
    {
        return new self('draft_not_found', 404, 'Черновик не найден.');
    }

    public static function notDraft(): self
    {
        return new self('not_draft', 409, 'Изменять цены можно только в черновике.');
    }

    public static function conflict(): self
    {
        return new self('revision_conflict', 409, 'Черновик изменён в другой вкладке. Перезагрузите страницу.');
    }
}

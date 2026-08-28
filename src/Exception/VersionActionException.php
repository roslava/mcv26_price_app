<?php

declare(strict_types=1);

namespace Mcv26\Price\Exception;

use RuntimeException;

final class VersionActionException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $message = 'Некорректный запрос.'): self
    {
        return new self('invalid_request', 422, $message);
    }

    public static function notFound(): self
    {
        return new self('version_not_found', 404, 'Версия не найдена.');
    }

    public static function wrongStatus(string $message): self
    {
        return new self('invalid_version_status', 409, $message);
    }

    public static function conflict(string $message): self
    {
        return new self('version_conflict', 409, $message);
    }
}

<?php

declare(strict_types=1);

namespace Mcv26\Price\Exception;

use RuntimeException;

final class PriceVersionExportException extends RuntimeException
{
    public static function invalidVersionId(): self
    {
        return new self('Version ID must be a positive integer.');
    }

    public static function notFound(): self
    {
        return new self('Price version was not found.');
    }

    public static function emptyVersion(): self
    {
        return new self('Price version contains no services.');
    }
}

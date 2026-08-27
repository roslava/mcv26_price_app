<?php

declare(strict_types=1);

namespace Mcv26\Price\Exception;

final class StructureException extends ImportException
{
    public static function atRow(int $row, string $message): self
    {
        return new self(sprintf('Строка %d: %s', $row, $message));
    }
}

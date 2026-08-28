<?php

declare(strict_types=1);

namespace Mcv26\Price\Admin;

use JsonException;
use Mcv26\Price\Exception\VersionActionException;

final class VersionActionRequest
{
    /** @return array<string, mixed> */
    public static function parse(string $method, string $contentType, string $body, bool $csrfValid): array
    {
        if ($method !== 'POST') {
            throw new VersionActionException('method_not_allowed', 405, 'Метод не поддерживается.');
        }
        if (!str_starts_with(strtolower($contentType), 'application/json')) {
            throw new VersionActionException('invalid_content_type', 400, 'Ожидался JSON-запрос.');
        }
        if (!$csrfValid) {
            throw new VersionActionException('csrf_failed', 403, 'Сессия формы устарела. Перезагрузите страницу.');
        }
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new VersionActionException('malformed_json', 400, 'Некорректный JSON-запрос.');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw VersionActionException::invalid();
        }
        return $data;
    }

    public static function positiveInteger(mixed $value): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^[1-9]\d*$/D', $value)) {
            if (strlen($value) > strlen((string) PHP_INT_MAX)
                || (strlen($value) === strlen((string) PHP_INT_MAX)
                    && strcmp($value, (string) PHP_INT_MAX) > 0)
            ) {
                throw VersionActionException::invalid();
            }
            $integer = (int) $value;
        } else {
            throw VersionActionException::invalid();
        }
        if ($integer < 1) {
            throw VersionActionException::invalid();
        }
        return $integer;
    }

    public static function nonNegativeInteger(mixed $value): int
    {
        if ($value === 0 || $value === '0') {
            return 0;
        }
        return self::positiveInteger($value);
    }

    public static function nullablePositiveInteger(mixed $value): ?int
    {
        return $value === null ? null : self::positiveInteger($value);
    }
}

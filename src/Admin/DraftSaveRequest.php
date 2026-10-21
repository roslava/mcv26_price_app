<?php

declare(strict_types=1);

namespace Mcv26\Price\Admin;

use JsonException;
use Mcv26\Price\Exception\DraftSaveException;

final class DraftSaveRequest
{
    /**
     * @return array{
     *   version_id: int, expected_revision: int, prices: list<array<string, mixed>>,
     *   deleted_service_ids: list<mixed>, deleted_category_ids: list<mixed>,
     *   new_services: list<mixed>, new_categories: list<mixed>
     * }
     */
    public static function parse(
        string $method,
        string $contentType,
        string $body,
        bool $csrfValid
    ): array {
        if ($method !== 'POST') {
            throw new DraftSaveException('method_not_allowed', 405, 'Метод не поддерживается.');
        }
        if (!str_starts_with(strtolower($contentType), 'application/json')) {
            throw new DraftSaveException('invalid_content_type', 400, 'Ожидался JSON-запрос.');
        }
        if (!$csrfValid) {
            throw new DraftSaveException('csrf_failed', 403, 'Сессия формы устарела. Перезагрузите страницу.');
        }
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DraftSaveException('malformed_json', 400, 'Некорректный JSON-запрос.');
        }
        if (!is_array($data)
            || !is_array($data['prices'] ?? null)
            || !array_is_list($data['prices'])
            || !self::optionalList($data, 'deleted_service_ids')
            || !self::optionalList($data, 'deleted_category_ids')
            || !self::optionalList($data, 'new_services')
            || !self::optionalList($data, 'new_categories')
        ) {
            throw new DraftSaveException('invalid_request', 422, 'Некорректная структура запроса.');
        }
        return [
            'version_id' => self::integer($data['version_id'] ?? null, false),
            'expected_revision' => self::integer($data['expected_revision'] ?? null, true),
            'prices' => $data['prices'],
            'deleted_service_ids' => $data['deleted_service_ids'] ?? [],
            'deleted_category_ids' => $data['deleted_category_ids'] ?? [],
            'new_services' => $data['new_services'] ?? [],
            'new_categories' => $data['new_categories'] ?? [],
        ];
    }

    private static function optionalList(array $data, string $key): bool
    {
        return !array_key_exists($key, $data) || (is_array($data[$key]) && array_is_list($data[$key]));
    }

    private static function integer(mixed $value, bool $allowZero): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^(0|[1-9]\d*)$/D', $value)) {
            if (strlen($value) > strlen((string) PHP_INT_MAX)
                || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)
            ) {
                throw new DraftSaveException('invalid_request', 422, 'Числовое значение слишком велико.');
            }
            $integer = (int) $value;
        } else {
            throw new DraftSaveException('invalid_request', 422, 'Некорректное числовое значение.');
        }
        if ($integer < ($allowZero ? 0 : 1)) {
            throw new DraftSaveException('invalid_request', 422, 'Некорректное числовое значение.');
        }
        return $integer;
    }
}

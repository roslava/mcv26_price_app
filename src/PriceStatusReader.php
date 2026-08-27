<?php

declare(strict_types=1);

namespace Mcv26\Price;

use JsonException;
use RuntimeException;

final class PriceStatusReader
{
    public function __construct(private readonly string $jsonPath)
    {
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        if (!is_file($this->jsonPath)) {
            return ['published' => false];
        }
        if (!is_readable($this->jsonPath)) {
            throw new RuntimeException('Опубликованный JSON недоступен для чтения.');
        }

        $contents = file_get_contents($this->jsonPath);
        if ($contents === false) {
            throw new RuntimeException('Не удалось прочитать опубликованный JSON.');
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Опубликованный JSON содержит ошибку.', 0, $exception);
        }

        if (!is_array($data)
            || !is_string($data['imported_at'] ?? null)
            || !is_array($data['source'] ?? null)
            || !is_string($data['source']['title'] ?? null)
            || !array_key_exists('price_date', $data['source'])
            || ($data['source']['price_date'] !== null && !is_string($data['source']['price_date']))
            || !is_array($data['stats'] ?? null)
            || !is_int($data['stats']['sections'] ?? null)
            || !is_int($data['stats']['items'] ?? null)
        ) {
            throw new RuntimeException('Опубликованный JSON имеет неожиданную структуру.');
        }

        return [
            'published' => true,
            'imported_at' => $data['imported_at'],
            'title' => $data['source']['title'],
            'price_date' => $data['source']['price_date'],
            'sections' => $data['stats']['sections'],
            'items' => $data['stats']['items'],
        ];
    }
}

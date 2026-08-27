<?php

declare(strict_types=1);

namespace Mcv26\Price;

use JsonException;
use RuntimeException;

final class PublicPriceReader
{
    public function __construct(private readonly string $jsonPath)
    {
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        if (!is_file($this->jsonPath) || !is_readable($this->jsonPath)) {
            throw new RuntimeException('Published price JSON is missing or unreadable.');
        }

        $contents = file_get_contents($this->jsonPath);
        if ($contents === false) {
            throw new RuntimeException('Published price JSON could not be read.');
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Published price JSON is malformed.', 0, $exception);
        }

        if (!is_array($data)
            || ($data['schema_version'] ?? null) !== 1
            || !is_array($data['source'] ?? null)
            || !is_string($data['source']['title'] ?? null)
            || trim($data['source']['title']) === ''
            || !array_key_exists('price_date', $data['source'])
            || ($data['source']['price_date'] !== null && !is_string($data['source']['price_date']))
            || !is_array($data['sections'] ?? null)
            || $data['sections'] === []
        ) {
            throw new RuntimeException('Published price JSON has an unexpected structure.');
        }

        foreach ($data['sections'] as $section) {
            if (!is_array($section)
                || !is_string($section['name'] ?? null)
                || trim($section['name']) === ''
                || !is_array($section['items'] ?? null)
            ) {
                throw new RuntimeException('Published price section is invalid.');
            }

            foreach ($section['items'] as $item) {
                if (!is_array($item)
                    || !is_string($item['code'] ?? null)
                    || trim($item['code']) === ''
                    || !is_string($item['name'] ?? null)
                    || trim($item['name']) === ''
                    || !is_numeric($item['price'] ?? null)
                    || !is_finite((float) $item['price'])
                    || (float) $item['price'] <= 0
                ) {
                    throw new RuntimeException('Published price item is invalid.');
                }
            }
        }

        return $data;
    }
}

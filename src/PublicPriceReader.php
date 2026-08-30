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

    /** @return array<string, mixed>|null */
    public function read(): ?array
    {
        if (!file_exists($this->jsonPath)) {
            return null;
        }
        if (!is_file($this->jsonPath) || !is_readable($this->jsonPath)) {
            throw new RuntimeException('Published price JSON is unreadable.');
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

        foreach ($data['sections'] as $sectionIndex => $section) {
            if (!is_array($section)
                || !is_string($section['name'] ?? null)
                || trim($section['name']) === ''
                || !is_array($section['items'] ?? null)
            ) {
                throw new RuntimeException('Published price section is invalid.');
            }

            foreach ($section['items'] as $itemIndex => $item) {
                if (!is_array($item)
                    || !is_string($item['code'] ?? null)
                    || trim($item['code']) === ''
                    || !is_string($item['name'] ?? null)
                    || trim($item['name']) === ''
                ) {
                    throw new RuntimeException('Published price item is invalid.');
                }

                $minor = array_key_exists('price_minor', $item)
                    ? $this->validateMinorUnits($item['price_minor'])
                    : $this->legacyPriceToMinorUnits($item['price'] ?? null);
                $data['sections'][$sectionIndex]['items'][$itemIndex]['price_minor'] = $minor;

                // Keep the existing view contract while persisted data remains integer-only.
                $data['sections'][$sectionIndex]['items'][$itemIndex]['price'] = $this->formatMajorUnits(
                    $minor
                );
            }
        }

        return $data;
    }

    private function validateMinorUnits(mixed $value): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new RuntimeException('Published price item is invalid.');
        }
        return $value;
    }

    private function legacyPriceToMinorUnits(mixed $value): int
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw new RuntimeException('Published price item is invalid.');
        }

        $normalized = trim((string) $value);
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/D', $normalized, $matches)) {
            throw new RuntimeException('Published price item is invalid.');
        }
        $whole = ltrim($matches[1], '0');
        $whole = $whole === '' ? '0' : $whole;
        $minorText = $whole . str_pad($matches[2] ?? '', 2, '0');
        if (strlen($minorText) > strlen((string) PHP_INT_MAX)
            || (strlen($minorText) === strlen((string) PHP_INT_MAX) && strcmp($minorText, (string) PHP_INT_MAX) > 0)
        ) {
            throw new RuntimeException('Published price item is invalid.');
        }

        return $this->validateMinorUnits((int) $minorText);
    }

    private function formatMajorUnits(int $minor): string
    {
        $whole = intdiv($minor, 100);
        $fraction = $minor % 100;
        return $fraction === 0
            ? (string) $whole
            : sprintf('%d.%s', $whole, rtrim(sprintf('%02d', $fraction), '0'));
    }
}

<?php

declare(strict_types=1);

namespace Mcv26\Price\Database;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class DatabasePublicPriceReader
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        $published = $this->pdo->query(
            "SELECT id, title, price_date FROM price_versions WHERE status = 'published' ORDER BY id"
        )->fetchAll();
        if (count($published) !== 1) {
            throw new RuntimeException('Expected exactly one published price version.');
        }

        $version = $published[0];
        if (!is_string($version['title'] ?? null) || trim($version['title']) === '') {
            throw new RuntimeException('Published price title is invalid.');
        }
        $priceDate = $version['price_date'] ?? null;
        if ($priceDate !== null) {
            if (!is_string($priceDate) || !self::isDate($priceDate)) {
                throw new RuntimeException('Published price date is invalid.');
            }
        }

        $categories = $this->pdo->prepare(
            'SELECT id, position, name FROM categories WHERE price_version_id = ? ORDER BY position, id'
        );
        $categories->execute([(int) $version['id']]);
        $sections = [];
        while (($category = $categories->fetch()) !== false) {
            if (!is_string($category['name'] ?? null) || trim($category['name']) === '') {
                throw new RuntimeException('Published price category is invalid.');
            }
            $services = $this->pdo->prepare(
                'SELECT service_number, code, name, current_price_minor '
                . 'FROM services WHERE category_id = ? ORDER BY position, id'
            );
            $services->execute([(int) $category['id']]);
            $items = [];
            while (($service = $services->fetch()) !== false) {
                if (!is_string($service['code'] ?? null) || trim($service['code']) === ''
                    || !is_string($service['name'] ?? null) || trim($service['name']) === '') {
                    throw new RuntimeException('Published price service is invalid.');
                }
                $number = self::positiveInteger($service['service_number'] ?? null);
                $minor = self::positiveInteger($service['current_price_minor'] ?? null);
                $items[] = [
                    'number' => $number,
                    'code' => $service['code'],
                    'name' => $service['name'],
                    'price_minor' => $minor,
                    'price' => self::formatMajorUnits($minor),
                ];
            }
            if ($items === []) {
                throw new RuntimeException('Published price category contains no services.');
            }
            $sections[] = ['name' => $category['name'], 'items' => $items];
        }
        if ($sections === []) {
            throw new RuntimeException('Published price contains no categories.');
        }

        return [
            'schema_version' => 1,
            'source' => ['title' => $version['title'], 'price_date' => $priceDate],
            'currency' => 'RUB',
            'sections' => $sections,
            'stats' => ['sections' => count($sections), 'items' => array_sum(array_map(
                static fn (array $section): int => count($section['items']),
                $sections
            ))],
        ];
    }

    private static function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        return $date !== false && $date->format('Y-m-d') === $value
            && (!is_array($errors) || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }

    private static function positiveInteger(mixed $value): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : throw new RuntimeException('Published price integer is invalid.');
        }
        if (!is_string($value) || !preg_match('/^[1-9]\d*$/D', $value)) {
            throw new RuntimeException('Published price integer is invalid.');
        }
        if (strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX)
                && strcmp($value, (string) PHP_INT_MAX) > 0)
        ) {
            throw new RuntimeException('Published price integer is invalid.');
        }
        return (int) $value;
    }

    private static function formatMajorUnits(int $minor): string
    {
        $whole = intdiv($minor, 100);
        $fraction = $minor % 100;
        return $fraction === 0
            ? (string) $whole
            : sprintf('%d.%02d', $whole, $fraction);
    }
}

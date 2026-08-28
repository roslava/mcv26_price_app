<?php

declare(strict_types=1);

namespace Mcv26\Price\Database;

use PDO;
use RuntimeException;
use Throwable;

final class DatabasePriceRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function transactional(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation($this);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $version */
    public function createVersion(array $version): int
    {
        return $this->transactional(function () use ($version): int {
            $statement = $this->pdo->prepare(
                'INSERT INTO price_versions '
                . '(status, title, price_date, original_filename, stored_xlsx_name, imported_at, created_at) '
                . "VALUES ('draft', :title, :price_date, :original_filename, :stored_xlsx_name, :imported_at, UTC_TIMESTAMP(6))"
            );
            $statement->execute([
                'title' => $version['title'] ?? null,
                'price_date' => $version['price_date'] ?? null,
                'original_filename' => $version['original_filename'] ?? null,
                'stored_xlsx_name' => $version['stored_xlsx_name'] ?? null,
                'imported_at' => $version['imported_at'] ?? null,
            ]);
            return (int) $this->pdo->lastInsertId();
        });
    }

    public function createCategory(int $versionId, int $position, string $name): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO categories (price_version_id, position, name) VALUES (?, ?, ?)'
        );
        $statement->execute([$versionId, $position, $name]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $service */
    public function createService(int $categoryId, array $service): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO services '
            . '(category_id, position, service_number, code, name, imported_price_minor, current_price_minor) '
            . 'VALUES (:category_id, :position, :service_number, :code, :name, '
            . ':imported_price_minor, :current_price_minor)'
        );
        $statement->execute([
            'category_id' => $categoryId,
            'position' => $service['position'] ?? null,
            'service_number' => $service['service_number'] ?? null,
            'code' => $service['code'] ?? null,
            'name' => $service['name'] ?? null,
            'imported_price_minor' => $service['imported_price_minor'] ?? null,
            'current_price_minor' => $service['current_price_minor'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function loadVersion(int $versionId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM price_versions WHERE id = ?');
        $statement->execute([$versionId]);
        $version = $statement->fetch();
        if (!is_array($version)) {
            return null;
        }

        $categories = $this->pdo->prepare(
            'SELECT * FROM categories WHERE price_version_id = ? ORDER BY position, id'
        );
        $categories->execute([$versionId]);
        $version['categories'] = [];
        while (($category = $categories->fetch()) !== false) {
            $services = $this->pdo->prepare(
                'SELECT * FROM services WHERE category_id = ? ORDER BY position, id'
            );
            $services->execute([$category['id']]);
            $category['services'] = $services->fetchAll();
            $version['categories'][] = $category;
        }
        return $version;
    }

    public function publishVersion(int $versionId): void
    {
        $this->transactional(function () use ($versionId): void {
            // The indexed locking read serializes competing publishers, including the no-row gap.
            $published = $this->pdo->query(
                "SELECT id FROM price_versions WHERE status = 'published' ORDER BY id FOR UPDATE"
            )->fetchAll(PDO::FETCH_COLUMN);

            $target = $this->pdo->prepare('SELECT status FROM price_versions WHERE id = ? FOR UPDATE');
            $target->execute([$versionId]);
            $status = $target->fetchColumn();
            if ($status === false) {
                throw new RuntimeException('Price version not found.');
            }
            if ($published !== [] && !in_array((string) $versionId, array_map('strval', $published), true)) {
                throw new RuntimeException('Another price version is already published.');
            }

            // Re-check under the same locks immediately before the state change.
            $recheck = $this->pdo->query(
                "SELECT id FROM price_versions WHERE status = 'published' ORDER BY id FOR UPDATE"
            )->fetchAll(PDO::FETCH_COLUMN);
            if ($recheck !== [] && !in_array((string) $versionId, array_map('strval', $recheck), true)) {
                throw new RuntimeException('Another price version is already published.');
            }
            if ($status !== 'published') {
                $update = $this->pdo->prepare(
                    "UPDATE price_versions SET status = 'published', published_at = UTC_TIMESTAMP(6) WHERE id = ?"
                );
                $update->execute([$versionId]);
            }
        });
    }
}

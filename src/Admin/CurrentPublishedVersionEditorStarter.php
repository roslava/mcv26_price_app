<?php

declare(strict_types=1);

namespace Mcv26\Price\Admin;

use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\Exception\VersionActionException;
use PDO;

final class CurrentPublishedVersionEditorStarter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DatabasePriceRepository $repository
    ) {
    }

    /** @return array{draft_version_id: int, created: bool, source_version_id: int} */
    public function start(): array
    {
        return $this->repository->transactional(function (): array {
            $versions = $this->pdo->query(
                'SELECT * FROM price_versions ORDER BY id FOR UPDATE'
            )->fetchAll();

            $editable = [];
            $published = [];
            foreach ($versions as $version) {
                if ($version['status'] === 'draft') $editable[] = $version;
                if ($version['status'] === 'published') $published[] = $version;
            }

            if (count($published) > 1) {
                throw VersionActionException::conflict('Не удалось однозначно определить текущий прайс.');
            }
            if ($editable !== []) {
                $target = $editable[array_key_last($editable)];
                return [
                    'draft_version_id' => (int) $target['id'],
                    'created' => false,
                    'source_version_id' => (int) ($target['restored_from_version_id'] ?? $target['id']),
                ];
            }
            if ($published === []) {
                throw VersionActionException::conflict('Текущий прайс не найден.');
            }
            $source = $published[0];
            $sourceId = (int) $source['id'];
            $draftId = $this->repository->createVersion([
                'title' => $source['title'],
                'price_date' => $source['price_date'],
                'original_filename' => $source['original_filename'],
                'stored_xlsx_name' => $source['stored_xlsx_name'],
                'source_xlsx_sha256' => $source['source_xlsx_sha256'],
                'source_json_sha256' => null,
                'source_identity' => null,
                'imported_at' => gmdate('Y-m-d H:i:s.u'),
                'restored_from_version_id' => $sourceId,
            ]);

            $categories = $this->pdo->prepare(
                'SELECT * FROM categories WHERE price_version_id = ? ORDER BY position, id FOR UPDATE'
            );
            $categories->execute([$sourceId]);
            foreach ($categories->fetchAll() as $category) {
                $newCategoryId = $this->repository->createCategory(
                    $draftId,
                    (int) $category['position'],
                    (string) $category['name']
                );
                $services = $this->pdo->prepare(
                    'SELECT * FROM services WHERE category_id = ? ORDER BY position, id FOR UPDATE'
                );
                $services->execute([$category['id']]);
                foreach ($services->fetchAll() as $service) {
                    $price = (int) $service['current_price_minor'];
                    $this->repository->createService($newCategoryId, [
                        'position' => (int) $service['position'],
                        'service_number' => (int) $service['service_number'],
                        'code' => (string) $service['code'],
                        'name' => (string) $service['name'],
                        'imported_price_minor' => $price,
                        'current_price_minor' => $price,
                    ]);
                }
            }

            return [
                'draft_version_id' => $draftId,
                'created' => true,
                'source_version_id' => $sourceId,
            ];
        });
    }
}

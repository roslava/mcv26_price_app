<?php

declare(strict_types=1);

namespace Mcv26\Price\Admin;

use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\Exception\VersionActionException;
use PDO;

final class ArchivedVersionRestorer
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DatabasePriceRepository $repository
    ) {
    }

    /** @return array{draft_version_id: int, restored_from_version_id: int, revision: int} */
    public function restore(int $archivedVersionId): array
    {
        if ($archivedVersionId < 1) {
            throw VersionActionException::invalid();
        }
        return $this->repository->transactional(function () use ($archivedVersionId): array {
            $sourceStatement = $this->pdo->prepare('SELECT * FROM price_versions WHERE id = ? FOR UPDATE');
            $sourceStatement->execute([$archivedVersionId]);
            $source = $sourceStatement->fetch();
            if (!is_array($source)) {
                throw VersionActionException::notFound();
            }
            if ($source['status'] !== 'archived') {
                throw VersionActionException::wrongStatus('Восстановить можно только архивную версию.');
            }

            $draftId = $this->repository->createVersion([
                'title' => $source['title'],
                'price_date' => $source['price_date'],
                'original_filename' => $source['original_filename'],
                'stored_xlsx_name' => $source['stored_xlsx_name'],
                'source_xlsx_sha256' => $source['source_xlsx_sha256'],
                'source_json_sha256' => null,
                'source_identity' => null,
                'imported_at' => gmdate('Y-m-d H:i:s.u'),
                'restored_from_version_id' => $archivedVersionId,
            ]);

            $categories = $this->pdo->prepare(
                'SELECT * FROM categories WHERE price_version_id = ? ORDER BY position, id FOR UPDATE'
            );
            $categories->execute([$archivedVersionId]);
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
                    $baseline = (int) $service['current_price_minor'];
                    $this->repository->createService($newCategoryId, [
                        'position' => (int) $service['position'],
                        'service_number' => (int) $service['service_number'],
                        'code' => (string) $service['code'],
                        'name' => (string) $service['name'],
                        'imported_price_minor' => $baseline,
                        'current_price_minor' => $baseline,
                    ]);
                }
            }
            return [
                'draft_version_id' => $draftId,
                'restored_from_version_id' => $archivedVersionId,
                'revision' => 0,
            ];
        });
    }
}

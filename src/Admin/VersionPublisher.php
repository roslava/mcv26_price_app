<?php

declare(strict_types=1);

namespace Mcv26\Price\Admin;

use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\Exception\VersionActionException;
use PDO;

final class VersionPublisher
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DatabasePriceRepository $repository,
        private readonly mixed $afterArchive = null
    ) {
    }

    /** @return array{published_version_id: int, archived_version_id: int|null, revision: int} */
    public function publish(int $draftId, int $expectedRevision, ?int $expectedPublishedVersionId): array
    {
        if ($draftId < 1 || $expectedRevision < 0) {
            throw VersionActionException::invalid();
        }

        return $this->repository->transactional(function () use (
            $draftId,
            $expectedRevision,
            $expectedPublishedVersionId
        ): array {
            $rows = $this->pdo->query(
                'SELECT id, status, revision FROM price_versions ORDER BY id FOR UPDATE'
            )->fetchAll();
            $target = null;
            $published = [];
            foreach ($rows as $row) {
                if ((int) $row['id'] === $draftId) {
                    $target = $row;
                }
                if ($row['status'] === 'published') {
                    $published[] = (int) $row['id'];
                }
            }
            if ($target === null) {
                throw VersionActionException::notFound();
            }
            if ($target['status'] !== 'draft') {
                throw VersionActionException::wrongStatus('Опубликовать можно только черновик.');
            }
            if ((int) $target['revision'] !== $expectedRevision) {
                throw VersionActionException::conflict('Черновик изменён. Перезагрузите страницу.');
            }
            if (count($published) > 1) {
                throw VersionActionException::conflict('Нарушен инвариант опубликованной версии.');
            }
            $currentPublishedId = $published[0] ?? null;
            if ($currentPublishedId !== $expectedPublishedVersionId) {
                throw VersionActionException::conflict('Опубликованная версия изменилась. Перезагрузите страницу.');
            }

            if ($currentPublishedId !== null) {
                $archive = $this->pdo->prepare(
                    "UPDATE price_versions SET status = 'archived' WHERE id = ? AND status = 'published'"
                );
                $archive->execute([$currentPublishedId]);
                if ($archive->rowCount() !== 1) {
                    throw VersionActionException::conflict('Опубликованная версия изменилась.');
                }
            }
            if (is_callable($this->afterArchive)) {
                ($this->afterArchive)();
            }
            $publish = $this->pdo->prepare(
                "UPDATE price_versions SET status = 'published', published_at = UTC_TIMESTAMP(6) "
                . "WHERE id = ? AND status = 'draft' AND revision = ?"
            );
            $publish->execute([$draftId, $expectedRevision]);
            if ($publish->rowCount() !== 1) {
                throw VersionActionException::conflict('Черновик изменён.');
            }
            return [
                'published_version_id' => $draftId,
                'archived_version_id' => $currentPublishedId,
                'revision' => $expectedRevision,
            ];
        });
    }
}

<?php

declare(strict_types=1);

namespace Mcv26\Price\Admin;

use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\Exception\DraftSaveException;
use PDO;

final class DraftPriceSaver
{
    public const MAX_PRICE_MINOR = 9_000_000_000_000_000;

    public function __construct(
        private readonly PDO $pdo,
        private readonly DatabasePriceRepository $repository
    ) {
    }

    /**
     * @param list<array<string, mixed>> $submittedPrices
     * @return array{revision: int, changed: int}
     */
    public function save(
        int $versionId,
        int $expectedRevision,
        array $submittedPrices,
        string $changedBy
    ): array {
        if ($versionId < 1 || $expectedRevision < 0 || $changedBy === '' || strlen($changedBy) > 191) {
            throw DraftSaveException::invalid('Некорректные параметры сохранения.');
        }

        return $this->repository->transactional(function () use (
            $versionId,
            $expectedRevision,
            $submittedPrices,
            $changedBy
        ): array {
            $versionStatement = $this->pdo->prepare(
                'SELECT status, revision FROM price_versions WHERE id = ? FOR UPDATE'
            );
            $versionStatement->execute([$versionId]);
            $version = $versionStatement->fetch();
            if (!is_array($version)) {
                throw DraftSaveException::notFound();
            }
            if ($version['status'] !== 'draft') {
                throw DraftSaveException::notDraft();
            }
            if ((int) $version['revision'] !== $expectedRevision) {
                throw DraftSaveException::conflict();
            }

            $servicesStatement = $this->pdo->prepare(
                'SELECT s.id, s.imported_price_minor, s.current_price_minor '
                . 'FROM services s JOIN categories c ON c.id = s.category_id '
                . 'WHERE c.price_version_id = ? ORDER BY c.position, s.position, s.id FOR UPDATE'
            );
            $servicesStatement->execute([$versionId]);
            $services = $servicesStatement->fetchAll();
            if ($services === []) {
                throw DraftSaveException::invalid('Черновик не содержит услуг.');
            }
            $submitted = $this->validateCompletePrices($submittedPrices, $services);

            $update = $this->pdo->prepare('UPDATE services SET current_price_minor = ? WHERE id = ?');
            $audit = $this->pdo->prepare(
                'INSERT INTO price_changes '
                . '(version_id, service_id, old_price_minor, new_price_minor, changed_at, changed_by) '
                . 'VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), ?)'
            );
            $changed = 0;
            foreach ($services as $service) {
                $serviceId = (int) $service['id'];
                $old = (int) $service['current_price_minor'];
                $new = $submitted[$serviceId];
                if ($old === $new) {
                    continue;
                }
                $update->execute([$new, $serviceId]);
                $audit->execute([$versionId, $serviceId, $old, $new, $changedBy]);
                $changed++;
            }

            $newRevision = $expectedRevision + 1;
            $revisionUpdate = $this->pdo->prepare(
                'UPDATE price_versions SET revision = ? WHERE id = ? AND revision = ?'
            );
            $revisionUpdate->execute([$newRevision, $versionId, $expectedRevision]);
            if ($revisionUpdate->rowCount() !== 1) {
                throw DraftSaveException::conflict();
            }
            return ['revision' => $newRevision, 'changed' => $changed];
        });
    }

    /**
     * @param list<array<string, mixed>> $prices
     * @param list<array<string, mixed>> $services
     * @return array<int, int>
     */
    private function validateCompletePrices(array $prices, array $services): array
    {
        $known = [];
        foreach ($services as $service) {
            $known[(int) $service['id']] = true;
        }
        $submitted = [];
        foreach ($prices as $entry) {
            if (!is_array($entry)) {
                throw DraftSaveException::invalid('Некорректный идентификатор услуги.');
            }
            $serviceId = $this->parseServiceId($entry['service_id'] ?? null);
            if ($serviceId < 1 || !isset($known[$serviceId]) || isset($submitted[$serviceId])) {
                throw DraftSaveException::invalid('Список услуг не соответствует черновику.');
            }
            $submitted[$serviceId] = $this->parseMinor($entry['current_price_minor'] ?? null);
        }
        if (count($submitted) !== count($known)) {
            throw DraftSaveException::invalid('Необходимо передать цены всех услуг черновика.');
        }
        return $submitted;
    }

    private function parseMinor(mixed $value): int
    {
        if (is_int($value)) {
            $minor = $value;
        } elseif (is_string($value) && preg_match('/^[1-9]\d*$/D', $value)) {
            if (strlen($value) > strlen((string) self::MAX_PRICE_MINOR)
                || (strlen($value) === strlen((string) self::MAX_PRICE_MINOR)
                    && strcmp($value, (string) self::MAX_PRICE_MINOR) > 0)
            ) {
                throw DraftSaveException::invalid('Цена превышает допустимое значение.');
            }
            $minor = (int) $value;
        } else {
            throw DraftSaveException::invalid('Цена должна быть положительным целым числом копеек.');
        }
        if ($minor < 1 || $minor > self::MAX_PRICE_MINOR) {
            throw DraftSaveException::invalid('Цена выходит за допустимые пределы.');
        }
        return $minor;
    }

    private function parseServiceId(mixed $value): int
    {
        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && preg_match('/^[1-9]\d*$/D', $value)) {
            if (strlen($value) > strlen((string) PHP_INT_MAX)
                || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)
            ) {
                throw DraftSaveException::invalid('Некорректный идентификатор услуги.');
            }
            $id = (int) $value;
        } else {
            throw DraftSaveException::invalid('Некорректный идентификатор услуги.');
        }
        if ($id < 1) {
            throw DraftSaveException::invalid('Некорректный идентификатор услуги.');
        }
        return $id;
    }
}

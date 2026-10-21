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
     * @param list<mixed> $deletedServiceIds
     * @param list<mixed> $deletedCategoryIds
     * @param list<mixed> $newServices
     * @param list<mixed> $newCategories
     * @return array{revision: int, changed: int, structural_changed: int}
     */
    public function save(
        int $versionId,
        int $expectedRevision,
        array $submittedPrices,
        string $changedBy,
        array $deletedServiceIds = [],
        array $deletedCategoryIds = [],
        array $newServices = [],
        array $newCategories = []
    ): array {
        if ($versionId < 1 || $expectedRevision < 0 || $changedBy === '' || strlen($changedBy) > 191) {
            throw DraftSaveException::invalid('Некорректные параметры сохранения.');
        }

        return $this->repository->transactional(function () use (
            $versionId,
            $expectedRevision,
            $submittedPrices,
            $changedBy,
            $deletedServiceIds,
            $deletedCategoryIds,
            $newServices,
            $newCategories
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
                'SELECT s.id, s.category_id, s.position, s.imported_price_minor, s.current_price_minor '
                . 'FROM services s JOIN categories c ON c.id = s.category_id '
                . 'WHERE c.price_version_id = ? ORDER BY c.position, s.position, s.id FOR UPDATE'
            );
            $servicesStatement->execute([$versionId]);
            $services = $servicesStatement->fetchAll();

            $categoriesStatement = $this->pdo->prepare(
                'SELECT id, position FROM categories WHERE price_version_id = ? ORDER BY position, id FOR UPDATE'
            );
            $categoriesStatement->execute([$versionId]);
            $categories = $categoriesStatement->fetchAll();

            $knownCategories = [];
            foreach ($categories as $category) {
                $knownCategories[(int) $category['id']] = (int) $category['position'];
            }
            $deletedCategories = $this->validateIds($deletedCategoryIds, $knownCategories, 'разделов');

            $knownServices = [];
            foreach ($services as $service) {
                $knownServices[(int) $service['id']] = (int) $service['category_id'];
            }
            $deletedServices = $this->validateIds($deletedServiceIds, $knownServices, 'услуг');
            foreach ($deletedServices as $serviceId => $_) {
                if (isset($deletedCategories[$knownServices[$serviceId]])) {
                    throw DraftSaveException::invalid('Услуга и её раздел не должны удаляться одновременно.');
                }
            }

            $retainedServices = array_values(array_filter(
                $services,
                static fn (array $service): bool => !isset($deletedServices[(int) $service['id']])
                    && !isset($deletedCategories[(int) $service['category_id']])
            ));
            $submitted = $this->validateCompletePrices($submittedPrices, $retainedServices);
            $validatedNewServices = $this->validateNewServices($newServices, $knownCategories, $deletedCategories);
            $validatedNewCategories = $this->validateNewCategories($newCategories);
            $this->validateResultingStructure(
                $categories,
                $retainedServices,
                $deletedCategories,
                $validatedNewServices,
                $validatedNewCategories
            );

            $update = $this->pdo->prepare('UPDATE services SET current_price_minor = ? WHERE id = ?');
            $audit = $this->pdo->prepare(
                'INSERT INTO price_changes '
                . '(version_id, service_id, old_price_minor, new_price_minor, changed_at, changed_by) '
                . 'VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), ?)'
            );
            $changed = 0;
            foreach ($retainedServices as $service) {
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

            if ($deletedServices !== []) {
                $deleteService = $this->pdo->prepare('DELETE FROM services WHERE id = ?');
                foreach (array_keys($deletedServices) as $serviceId) {
                    $deleteService->execute([$serviceId]);
                }
            }
            if ($deletedCategories !== []) {
                $deleteCategory = $this->pdo->prepare('DELETE FROM categories WHERE id = ?');
                foreach (array_keys($deletedCategories) as $categoryId) {
                    $deleteCategory->execute([$categoryId]);
                }
            }

            $nextPositions = [];
            foreach ($services as $service) {
                $categoryId = (int) $service['category_id'];
                $nextPositions[$categoryId] = max($nextPositions[$categoryId] ?? 0, (int) $service['position']);
            }
            foreach ($validatedNewServices as $service) {
                $categoryId = $service['category_id'];
                $this->repository->createService($categoryId, [
                    'position' => ++$nextPositions[$categoryId],
                    'service_number' => $service['service_number'],
                    'code' => $service['code'],
                    'name' => $service['name'],
                    'imported_price_minor' => $service['current_price_minor'],
                    'current_price_minor' => $service['current_price_minor'],
                ]);
            }

            $nextCategoryPosition = $categories === []
                ? 0
                : max(array_map(static fn (array $category): int => (int) $category['position'], $categories));
            foreach ($validatedNewCategories as $category) {
                $categoryId = $this->repository->createCategory(
                    $versionId,
                    ++$nextCategoryPosition,
                    $category['name']
                );
                foreach ($category['services'] as $offset => $service) {
                    $this->repository->createService($categoryId, [
                        'position' => $offset + 1,
                        'service_number' => $service['service_number'],
                        'code' => $service['code'],
                        'name' => $service['name'],
                        'imported_price_minor' => $service['current_price_minor'],
                        'current_price_minor' => $service['current_price_minor'],
                    ]);
                }
            }

            $newRevision = $expectedRevision + 1;
            $revisionUpdate = $this->pdo->prepare(
                'UPDATE price_versions SET revision = ? WHERE id = ? AND revision = ?'
            );
            $revisionUpdate->execute([$newRevision, $versionId, $expectedRevision]);
            if ($revisionUpdate->rowCount() !== 1) {
                throw DraftSaveException::conflict();
            }
            return [
                'revision' => $newRevision,
                'changed' => $changed,
                'structural_changed' => count($deletedServices)
                    + count($deletedCategories)
                    + count($validatedNewServices)
                    + count($validatedNewCategories),
            ];
        });
    }

    /** @param list<mixed> $ids @param array<int, int> $known @return array<int, true> */
    private function validateIds(array $ids, array $known, string $label): array
    {
        $validated = [];
        foreach ($ids as $value) {
            $id = $this->parseServiceId($value);
            if (!isset($known[$id]) || isset($validated[$id])) {
                throw DraftSaveException::invalid('Список удаляемых ' . $label . ' не соответствует черновику.');
            }
            $validated[$id] = true;
        }
        return $validated;
    }

    /** @param list<mixed> $services @param array<int, int> $knownCategories @param array<int, true> $deletedCategories */
    private function validateNewServices(array $services, array $knownCategories, array $deletedCategories): array
    {
        $validated = [];
        foreach ($services as $service) {
            if (!is_array($service)) {
                throw DraftSaveException::invalid('Некорректная новая услуга.');
            }
            $categoryId = $this->parseServiceId($service['category_id'] ?? null);
            if (!isset($knownCategories[$categoryId]) || isset($deletedCategories[$categoryId])) {
                throw DraftSaveException::invalid('Раздел новой услуги не существует.');
            }
            $validated[] = $this->validateNewService($service) + ['category_id' => $categoryId];
        }
        return $validated;
    }

    /** @param list<mixed> $categories */
    private function validateNewCategories(array $categories): array
    {
        $validated = [];
        foreach ($categories as $category) {
            if (!is_array($category) || !is_array($category['services'] ?? null)
                || !array_is_list($category['services']) || $category['services'] === []
            ) {
                throw DraftSaveException::invalid('Новый раздел должен содержать хотя бы одну услугу.');
            }
            $name = $this->nonEmptyText($category['name'] ?? null, 512, 'Название раздела');
            $services = [];
            foreach ($category['services'] as $service) {
                if (!is_array($service)) {
                    throw DraftSaveException::invalid('Некорректная новая услуга.');
                }
                $services[] = $this->validateNewService($service);
            }
            $validated[] = ['name' => $name, 'services' => $services];
        }
        return $validated;
    }

    /** @param array<string, mixed> $service */
    private function validateNewService(array $service): array
    {
        return [
            'service_number' => $this->parseServiceId($service['service_number'] ?? null),
            'code' => $this->nonEmptyText($service['code'] ?? null, 191, 'Код услуги'),
            'name' => $this->nonEmptyText($service['name'] ?? null, 1024, 'Название услуги'),
            'current_price_minor' => $this->parseMinor($service['current_price_minor'] ?? null),
        ];
    }

    private function nonEmptyText(mixed $value, int $maxLength, string $label): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $maxLength) {
            throw DraftSaveException::invalid($label . ' заполнено некорректно.');
        }
        return $value;
    }

    /** @param list<array<string, mixed>> $categories @param list<array<string, mixed>> $retainedServices */
    private function validateResultingStructure(
        array $categories,
        array $retainedServices,
        array $deletedCategories,
        array $newServices,
        array $newCategories
    ): void {
        $counts = [];
        foreach ($retainedServices as $service) {
            $categoryId = (int) $service['category_id'];
            $counts[$categoryId] = ($counts[$categoryId] ?? 0) + 1;
        }
        foreach ($newServices as $service) {
            $categoryId = $service['category_id'];
            $counts[$categoryId] = ($counts[$categoryId] ?? 0) + 1;
        }
        $remainingCategories = 0;
        foreach ($categories as $category) {
            $categoryId = (int) $category['id'];
            if (isset($deletedCategories[$categoryId])) {
                continue;
            }
            $remainingCategories++;
            if (($counts[$categoryId] ?? 0) === 0) {
                throw DraftSaveException::invalid('Раздел не может оставаться без услуг.');
            }
        }
        if ($remainingCategories + count($newCategories) === 0) {
            throw DraftSaveException::invalid('Прайс должен содержать хотя бы один раздел.');
        }
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

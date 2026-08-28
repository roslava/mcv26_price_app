<?php

declare(strict_types=1);

namespace Mcv26\Price\Migration;

use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\PriceImporter;
use Mcv26\Price\PublicPriceReader;
use Mcv26\Price\Storage\OriginalXlsxStorage;
use PDO;
use RuntimeException;
use Throwable;

final class CurrentPublicationMigrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DatabasePriceRepository $repository,
        private readonly PriceImporter $importer,
        private readonly OriginalXlsxStorage $originalStorage
    ) {
    }

    /** @return array{version_id: int, created: bool, title: string, price_date: ?string, categories: int, services: int} */
    public function migrate(string $xlsxPath, string $jsonPath): array
    {
        $xlsx = $this->importer->import($xlsxPath, basename($xlsxPath));
        $json = (new PublicPriceReader($jsonPath))->read();
        $this->assertPublicationsMatch($xlsx, $json);

        $xlsxHash = hash_file('sha256', $xlsxPath);
        $jsonHash = hash_file('sha256', $jsonPath);
        if (!is_string($xlsxHash) || !is_string($jsonHash)) {
            throw new RuntimeException('Could not fingerprint current publication files.');
        }

        $newStoredFilename = null;
        $sourceIdentity = 'initial:' . $xlsxHash;
        try {
            return $this->repository->transactional(
                function () use (
                    $xlsx,
                    $xlsxPath,
                    $xlsxHash,
                    $jsonHash,
                    $sourceIdentity,
                    &$newStoredFilename
                ): array {
                    $existingId = $this->findVersionIdByIdentity($sourceIdentity);
                    if ($existingId !== null) {
                        $this->assertPersistedVersionMatches($existingId, $xlsx, $xlsxHash, $jsonHash);
                        return $this->result($existingId, false, $xlsx);
                    }

                    $newStoredFilename = $this->originalStorage->store(
                        $xlsxPath,
                        $xlsx['source']['stored_filename']
                    );
                    $versionId = $this->repository->createVersion([
                        'title' => $xlsx['source']['title'],
                        'price_date' => $xlsx['source']['price_date'],
                        'original_filename' => $xlsx['source']['stored_filename'],
                        'stored_xlsx_name' => $newStoredFilename,
                        'source_xlsx_sha256' => $xlsxHash,
                        'source_json_sha256' => $jsonHash,
                        'source_identity' => $sourceIdentity,
                        'imported_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                            ->format('Y-m-d H:i:s.u'),
                    ]);
                    foreach ($xlsx['sections'] as $categoryOffset => $section) {
                        $categoryId = $this->repository->createCategory(
                            $versionId,
                            $categoryOffset + 1,
                            $section['name']
                        );
                        foreach ($section['items'] as $serviceOffset => $item) {
                            $this->repository->createService($categoryId, [
                                'position' => $serviceOffset + 1,
                                'service_number' => $item['number'],
                                'code' => $item['code'],
                                'name' => $item['name'],
                                'imported_price_minor' => $item['price_minor'],
                                'current_price_minor' => $item['price_minor'],
                            ]);
                        }
                    }
                    $this->repository->publishVersion($versionId);
                    $this->assertPersistedVersionMatches($versionId, $xlsx, $xlsxHash, $jsonHash);
                    return $this->result($versionId, true, $xlsx);
                }
            );
        } catch (Throwable $exception) {
            if ($newStoredFilename !== null) {
                $this->originalStorage->remove($newStoredFilename);
            }
            throw $exception;
        }
    }

    private function findVersionIdByIdentity(string $identity): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM price_versions WHERE source_identity = ? FOR UPDATE'
        );
        $statement->execute([$identity]);
        $id = $statement->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @param array<string, mixed> $xlsx @param array<string, mixed> $json */
    private function assertPublicationsMatch(array $xlsx, array $json): void
    {
        if (($xlsx['source']['title'] ?? null) !== ($json['source']['title'] ?? null)
            || ($xlsx['source']['price_date'] ?? null) !== ($json['source']['price_date'] ?? null)
            || ($xlsx['source']['stored_filename'] ?? null) !== ($json['source']['stored_filename'] ?? null)
            || $this->orderedContent($xlsx) !== $this->orderedContent($json)
        ) {
            throw new RuntimeException('Current XLSX and published JSON contain different price data.');
        }
    }

    /** @param array<string, mixed> $publication @return list<array<string, mixed>> */
    private function orderedContent(array $publication): array
    {
        $content = [];
        foreach ($publication['sections'] ?? [] as $section) {
            $items = [];
            foreach ($section['items'] ?? [] as $item) {
                $items[] = [
                    'number' => $item['number'] ?? null,
                    'code' => $item['code'] ?? null,
                    'name' => $item['name'] ?? null,
                    'price_minor' => $item['price_minor'] ?? null,
                ];
            }
            $content[] = ['name' => $section['name'] ?? null, 'items' => $items];
        }
        return $content;
    }

    /** @param array<string, mixed> $xlsx */
    private function assertPersistedVersionMatches(
        int $versionId,
        array $xlsx,
        string $xlsxHash,
        string $jsonHash
    ): void {
        $stored = $this->repository->loadVersion($versionId);
        if ($stored === null
            || $stored['status'] !== 'published'
            || $stored['title'] !== $xlsx['source']['title']
            || $stored['price_date'] !== $xlsx['source']['price_date']
            || $stored['source_xlsx_sha256'] !== $xlsxHash
            || $stored['source_json_sha256'] !== $jsonHash
            || !$this->originalStorage->matches($stored['stored_xlsx_name'], $xlsxHash)
            || count($stored['categories']) !== count($xlsx['sections'])
        ) {
            throw new RuntimeException('Persisted initial price version does not match the current publication.');
        }
        foreach ($xlsx['sections'] as $categoryOffset => $section) {
            $category = $stored['categories'][$categoryOffset] ?? null;
            if (!is_array($category)
                || (int) $category['position'] !== $categoryOffset + 1
                || $category['name'] !== $section['name']
                || count($category['services']) !== count($section['items'])
            ) {
                throw new RuntimeException('Persisted initial price version does not match the current publication.');
            }
            foreach ($section['items'] as $serviceOffset => $item) {
                $service = $category['services'][$serviceOffset] ?? null;
                if (!is_array($service)
                    || (int) $service['position'] !== $serviceOffset + 1
                    || (int) $service['service_number'] !== $item['number']
                    || $service['code'] !== $item['code']
                    || $service['name'] !== $item['name']
                    || (int) $service['imported_price_minor'] !== $item['price_minor']
                    || (int) $service['current_price_minor'] !== $item['price_minor']
                ) {
                    throw new RuntimeException('Persisted initial price version does not match the current publication.');
                }
            }
        }
        $published = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM price_versions WHERE status = 'published'"
        )->fetchColumn();
        if ($published !== 1) {
            throw new RuntimeException('Database must contain exactly one published price version.');
        }
    }

    /** @param array<string, mixed> $xlsx */
    private function result(int $versionId, bool $created, array $xlsx): array
    {
        return [
            'version_id' => $versionId,
            'created' => $created,
            'title' => $xlsx['source']['title'],
            'price_date' => $xlsx['source']['price_date'],
            'categories' => $xlsx['stats']['sections'],
            'services' => $xlsx['stats']['items'],
        ];
    }
}

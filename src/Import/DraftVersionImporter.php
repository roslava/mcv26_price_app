<?php

declare(strict_types=1);

namespace Mcv26\Price\Import;

use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\PriceImporter;
use Mcv26\Price\Storage\OriginalXlsxStorage;
use Mcv26\Price\UploadValidator;
use PDO;
use RuntimeException;
use Throwable;

final class DraftVersionImporter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DatabasePriceRepository $repository,
        private readonly UploadValidator $validator,
        private readonly PriceImporter $importer,
        private readonly OriginalXlsxStorage $originalStorage
    ) {
    }

    /** @return array{version_id: int, created: bool, status: string, outcome: string, categories: int, services: int, stored_xlsx_name: string} */
    public function import(string $sourcePath, string $originalFilename): array
    {
        $originalFilename = basename($originalFilename);
        $this->validator->validateSource($sourcePath, $originalFilename);
        $data = $this->parseUploadedSource($sourcePath, $originalFilename);
        $hash = hash_file('sha256', $sourcePath);
        if (!is_string($hash)) {
            throw new RuntimeException('Could not fingerprint uploaded XLSX.');
        }
        $newStoredFilename = null;
        try {
            return $this->repository->transactional(function () use (
                $sourcePath,
                $originalFilename,
                $data,
                $hash,
                &$newStoredFilename
            ): array {
                $matching = $this->matchingVersions($hash);
                foreach ($matching as $version) {
                    if ($version['status'] === 'draft') {
                        return $this->verifiedResult(
                            (int) $version['id'], false, 'existing_draft', $data, $hash, false
                        );
                    }
                }
                foreach ($matching as $version) {
                    if ($version['status'] === 'published'
                        && $this->graphMatches((int) $version['id'], $data, $hash, true)
                    ) {
                        return $this->verifiedResult(
                            (int) $version['id'], false, 'unchanged_published', $data, $hash, true
                        );
                    }
                }

                $newStoredFilename = $this->originalStorage->store($sourcePath, $originalFilename);
                $versionId = $this->repository->createVersion([
                    'title' => $data['source']['title'],
                    'price_date' => $data['source']['price_date'],
                    'original_filename' => $originalFilename,
                    'stored_xlsx_name' => $newStoredFilename,
                    'source_xlsx_sha256' => $hash,
                    'source_json_sha256' => null,
                    'source_identity' => $this->newUploadIdentity($hash),
                    'imported_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                        ->format('Y-m-d H:i:s.u'),
                ]);
                foreach ($data['sections'] as $categoryOffset => $section) {
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
                return $this->verifiedResult($versionId, true, 'created', $data, $hash, false);
            });
        } catch (Throwable $exception) {
            if ($newStoredFilename !== null) {
                $this->originalStorage->remove($newStoredFilename);
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function parseUploadedSource(string $sourcePath, string $originalFilename): array
    {
        $temporary = tempnam(sys_get_temp_dir(), 'mcv26_draft_');
        if ($temporary === false) {
            throw new RuntimeException('Could not create temporary XLSX for validation.');
        }
        $xlsxPath = $temporary . '.xlsx';
        try {
            if (!rename($temporary, $xlsxPath) || !copy($sourcePath, $xlsxPath)) {
                throw new RuntimeException('Could not stage XLSX for validation.');
            }
            return $this->importer->import($xlsxPath, $originalFilename);
        } finally {
            @unlink($temporary);
            @unlink($xlsxPath);
        }
    }

    /** @return list<array<string, mixed>> */
    private function matchingVersions(string $hash): array
    {
        $rows = $this->pdo->query(
            'SELECT id, status, source_xlsx_sha256 FROM price_versions ORDER BY id FOR UPDATE'
        )->fetchAll();
        $matching = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['source_xlsx_sha256'] === $hash
        ));
        return array_reverse($matching);
    }

    private function newUploadIdentity(string $hash): string
    {
        return 'upload:' . substr($hash, 0, 40) . ':' . bin2hex(random_bytes(16));
    }

    /** @param array<string, mixed> $data */
    private function verifiedResult(
        int $versionId,
        bool $created,
        string $outcome,
        array $data,
        string $hash,
        bool $compareCurrent
    ): array
    {
        $stored = $this->repository->loadVersion($versionId);
        if ($stored === null
            || ($compareCurrent ? $stored['status'] !== 'published' : $stored['status'] !== 'draft')
            || !$this->graphMatches($versionId, $data, $hash, $compareCurrent, $stored)
        ) {
            throw new RuntimeException('Persisted price version does not match uploaded XLSX.');
        }
        $serviceCount = 0;
        foreach ($data['sections'] as $section) $serviceCount += count($section['items']);
        return [
            'version_id' => $versionId,
            'created' => $created,
            'status' => $compareCurrent ? 'published' : 'draft',
            'outcome' => $outcome,
            'categories' => count($data['sections']),
            'services' => $serviceCount,
            'stored_xlsx_name' => $stored['stored_xlsx_name'],
        ];
    }

    /** @param array<string, mixed> $data @param array<string, mixed>|null $stored */
    private function graphMatches(
        int $versionId,
        array $data,
        string $hash,
        bool $compareCurrent,
        ?array $stored = null
    ): bool {
        $stored ??= $this->repository->loadVersion($versionId);
        if ($stored === null
            || $stored['title'] !== $data['source']['title']
            || $stored['price_date'] !== $data['source']['price_date']
            || $stored['source_xlsx_sha256'] !== $hash
            || !$this->originalStorage->matches($stored['stored_xlsx_name'], $hash)
            || count($stored['categories']) !== count($data['sections'])
        ) return false;
        foreach ($data['sections'] as $categoryOffset => $section) {
            $category = $stored['categories'][$categoryOffset] ?? null;
            if (!is_array($category)
                || (int) $category['position'] !== $categoryOffset + 1
                || $category['name'] !== $section['name']
                || count($category['services']) !== count($section['items'])
            ) return false;
            foreach ($section['items'] as $serviceOffset => $item) {
                $service = $category['services'][$serviceOffset] ?? null;
                $priceColumn = $compareCurrent ? 'current_price_minor' : 'imported_price_minor';
                if (!is_array($service)
                    || (int) $service['position'] !== $serviceOffset + 1
                    || (int) $service['service_number'] !== $item['number']
                    || $service['code'] !== $item['code']
                    || $service['name'] !== $item['name']
                    || (int) $service[$priceColumn] !== $item['price_minor']
                ) return false;
            }
        }
        return true;
    }
}

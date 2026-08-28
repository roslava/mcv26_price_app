<?php

declare(strict_types=1);

namespace Mcv26\Price\Import;

use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\PriceImporter;
use Mcv26\Price\Storage\OriginalXlsxStorage;
use Mcv26\Price\UploadValidator;
use PDO;
use PDOException;
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

    /** @return array{version_id: int, created: bool, status: string, categories: int, services: int, stored_xlsx_name: string} */
    public function import(string $sourcePath, string $originalFilename): array
    {
        $originalFilename = basename($originalFilename);
        $this->validator->validateSource($sourcePath, $originalFilename);
        $data = $this->parseUploadedSource($sourcePath, $originalFilename);
        $hash = hash_file('sha256', $sourcePath);
        if (!is_string($hash)) {
            throw new RuntimeException('Could not fingerprint uploaded XLSX.');
        }
        $identity = 'draft:' . $hash;

        $newStoredFilename = null;
        try {
            return $this->repository->transactional(function () use (
                $sourcePath,
                $originalFilename,
                $data,
                $hash,
                $identity,
                &$newStoredFilename
            ): array {
                $existingId = $this->findDraftId($identity);
                if ($existingId !== null) {
                    return $this->verifiedResult($existingId, false, $data, $hash);
                }

                $newStoredFilename = $this->originalStorage->store($sourcePath, $originalFilename);
                $versionId = $this->repository->createVersion([
                    'title' => $data['source']['title'],
                    'price_date' => $data['source']['price_date'],
                    'original_filename' => $originalFilename,
                    'stored_xlsx_name' => $newStoredFilename,
                    'source_xlsx_sha256' => $hash,
                    'source_json_sha256' => null,
                    'source_identity' => $identity,
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
                return $this->verifiedResult($versionId, true, $data, $hash);
            });
        } catch (Throwable $exception) {
            if ($newStoredFilename !== null) {
                $this->originalStorage->remove($newStoredFilename);
            }
            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                $existingId = $this->findDraftIdWithoutLock($identity);
                if ($existingId !== null) {
                    return $this->verifiedResult($existingId, false, $data, $hash);
                }
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

    private function findDraftId(string $identity): ?int
    {
        $statement = $this->pdo->prepare(
            "SELECT id FROM price_versions WHERE source_identity = ? AND status = 'draft' FOR UPDATE"
        );
        $statement->execute([$identity]);
        $id = $statement->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function findDraftIdWithoutLock(string $identity): ?int
    {
        $statement = $this->pdo->prepare(
            "SELECT id FROM price_versions WHERE source_identity = ? AND status = 'draft'"
        );
        $statement->execute([$identity]);
        $id = $statement->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @param array<string, mixed> $data */
    private function verifiedResult(int $versionId, bool $created, array $data, string $hash): array
    {
        $stored = $this->repository->loadVersion($versionId);
        if ($stored === null
            || $stored['status'] !== 'draft'
            || $stored['title'] !== $data['source']['title']
            || $stored['price_date'] !== $data['source']['price_date']
            || $stored['source_xlsx_sha256'] !== $hash
            || !$this->originalStorage->matches($stored['stored_xlsx_name'], $hash)
            || count($stored['categories']) !== count($data['sections'])
        ) {
            throw new RuntimeException('Persisted draft does not match uploaded XLSX.');
        }
        $serviceCount = 0;
        foreach ($data['sections'] as $categoryOffset => $section) {
            $category = $stored['categories'][$categoryOffset] ?? null;
            if (!is_array($category)
                || (int) $category['position'] !== $categoryOffset + 1
                || $category['name'] !== $section['name']
                || count($category['services']) !== count($section['items'])
            ) {
                throw new RuntimeException('Persisted draft does not match uploaded XLSX.');
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
                    throw new RuntimeException('Persisted draft does not match uploaded XLSX.');
                }
                $serviceCount++;
            }
        }
        return [
            'version_id' => $versionId,
            'created' => $created,
            'status' => 'draft',
            'categories' => count($data['sections']),
            'services' => $serviceCount,
            'stored_xlsx_name' => $stored['stored_xlsx_name'],
        ];
    }
}

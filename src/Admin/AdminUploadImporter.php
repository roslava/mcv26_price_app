<?php

declare(strict_types=1);

namespace Mcv26\Price\Admin;

use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\Import\DraftVersionImporter;
use Mcv26\Price\PriceImporter;
use Mcv26\Price\Storage\OriginalXlsxStorage;
use Mcv26\Price\UploadValidator;
use PDO;

/** Small composition seam used by the admin upload entry point. */
final class AdminUploadImporter
{
    public function __construct(private readonly PDO $pdo, private readonly string $storageDirectory, private readonly string $publicDirectory)
    {
    }

    /** @return array{version_id: int, created: bool, status: string, categories: int, services: int, stored_xlsx_name: string} */
    public function import(string $sourcePath, string $originalFilename): array
    {
        $validator = new UploadValidator();
        $repository = new DatabasePriceRepository($this->pdo);
        return (new DraftVersionImporter(
            $this->pdo,
            $repository,
            $validator,
            new PriceImporter($validator),
            new OriginalXlsxStorage($this->storageDirectory . '/originals', $this->publicDirectory, $validator)
        ))->import($sourcePath, $originalFilename);
    }
}

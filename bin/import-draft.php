#!/usr/bin/env php
<?php

declare(strict_types=1);

use Mcv26\Price\Database\DatabaseConfig;
use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\Database\MigrationRunner;
use Mcv26\Price\Database\PdoConnectionFactory;
use Mcv26\Price\Import\DraftVersionImporter;
use Mcv26\Price\PriceImporter;
use Mcv26\Price\Storage\OriginalXlsxStorage;
use Mcv26\Price\UploadValidator;

require dirname(__DIR__) . '/vendor/autoload.php';

if ($argc < 2 || $argc > 3) {
    fwrite(STDERR, "Usage: php bin/import-draft.php <source.xlsx> [original-filename.xlsx]\n");
    exit(2);
}

try {
    $root = dirname(__DIR__);
    $source = $argv[1];
    $originalFilename = $argv[2] ?? basename($source);
    $pdo = PdoConnectionFactory::create(DatabaseConfig::fromEnvironment());
    (new MigrationRunner($pdo, $root . '/migrations'))->migrate();
    $validator = new UploadValidator();
    $result = (new DraftVersionImporter(
        $pdo,
        new DatabasePriceRepository($pdo),
        $validator,
        new PriceImporter($validator),
        new OriginalXlsxStorage($root . '/storage/originals', $root . '/public', $validator)
    ))->import($source, $originalFilename);
    printf(
        "%s draft version %d: %d categories, %d services, stored as %s.\n",
        $result['created'] ? 'Created' : 'Existing',
        $result['version_id'],
        $result['categories'],
        $result['services'],
        $result['stored_xlsx_name']
    );
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Draft import failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

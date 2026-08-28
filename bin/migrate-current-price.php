#!/usr/bin/env php
<?php

declare(strict_types=1);

use Mcv26\Price\Database\DatabaseConfig;
use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\Database\MigrationRunner;
use Mcv26\Price\Database\PdoConnectionFactory;
use Mcv26\Price\Migration\CurrentPublicationMigrator;
use Mcv26\Price\PriceImporter;
use Mcv26\Price\Storage\OriginalXlsxStorage;
use Mcv26\Price\UploadValidator;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $root = dirname(__DIR__);
    $pdo = PdoConnectionFactory::create(DatabaseConfig::fromEnvironment());
    (new MigrationRunner($pdo, $root . '/migrations'))->migrate();
    $validator = new UploadValidator();
    $result = (new CurrentPublicationMigrator(
        $pdo,
        new DatabasePriceRepository($pdo),
        new PriceImporter($validator),
        new OriginalXlsxStorage($root . '/storage/originals', $root . '/public', $validator)
    ))->migrate($root . '/storage/uploads/current.xlsx', $root . '/storage/data/price.json');

    printf(
        "%s version %d: %d categories, %d services, price date %s.\n",
        $result['created'] ? 'Created' : 'Already migrated',
        $result['version_id'],
        $result['categories'],
        $result['services'],
        $result['price_date'] ?? 'none'
    );
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Current publication migration failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

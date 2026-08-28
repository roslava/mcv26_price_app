#!/usr/bin/env php
<?php

declare(strict_types=1);

use Mcv26\Price\Database\DatabaseConfig;
use Mcv26\Price\Database\MigrationRunner;
use Mcv26\Price\Database\PdoConnectionFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $pdo = PdoConnectionFactory::create(DatabaseConfig::fromEnvironment());
    $applied = (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->migrate();
    foreach ($applied as $version) {
        fwrite(STDOUT, sprintf("Applied migration: %s\n", $version));
    }
    if ($applied === []) {
        fwrite(STDOUT, "Database schema is up to date.\n");
    }
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

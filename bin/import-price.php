#!/usr/bin/env php
<?php

declare(strict_types=1);

use Mcv26\Price\Exception\ImportException;
use Mcv26\Price\PriceImporter;
use Mcv26\Price\PriceRepository;
use Mcv26\Price\UploadValidator;

require dirname(__DIR__) . '/vendor/autoload.php';

if ($argc !== 2) {
    fwrite(STDERR, "Использование: php bin/import-price.php <файл.xlsx>\n");
    exit(2);
}

$input = $argv[1];
if (!str_starts_with($input, DIRECTORY_SEPARATOR)) {
    $input = getcwd() . DIRECTORY_SEPARATOR . $input;
}

try {
    $validator = new UploadValidator();
    $importer = new PriceImporter($validator);
    $repository = new PriceRepository(dirname(__DIR__) . '/storage');
    $data = $repository->importAndPublish($input, $validator, $importer);

    printf("Разделов: %d\n", $data['stats']['sections']);
    printf("Услуг: %d\n", $data['stats']['items']);
    printf("Предупреждений: %d\n", count($data['warnings']));
    foreach ($data['warnings'] as $warning) {
        printf("- Строка %d: %s [%s]\n", $warning['row'], $warning['message'], $warning['code']);
    }
    fwrite(STDOUT, "Прайс успешно опубликован.\n");
    exit(0);
} catch (ImportException $exception) {
    fwrite(STDERR, 'Ошибка импорта: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Непредвиденная ошибка импорта: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

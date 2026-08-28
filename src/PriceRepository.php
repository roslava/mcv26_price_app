<?php

declare(strict_types=1);

namespace Mcv26\Price;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Mcv26\Price\Exception\ImportException;
use Throwable;

final class PriceRepository
{
    private readonly string $storageDirectory;
    private readonly string $uploadsDirectory;
    private readonly string $archiveDirectory;
    private readonly string $dataDirectory;

    public function __construct(string $storageDirectory)
    {
        $storageDirectory = rtrim($storageDirectory, DIRECTORY_SEPARATOR);
        $this->storageDirectory = $storageDirectory;
        $this->uploadsDirectory = $storageDirectory . DIRECTORY_SEPARATOR . 'uploads';
        $this->archiveDirectory = $storageDirectory . DIRECTORY_SEPARATOR . 'archive';
        $this->dataDirectory = $storageDirectory . DIRECTORY_SEPARATOR . 'data';
    }

    /** @return array<string, mixed> */
    public function importAndPublish(
        string $sourceXlsx,
        UploadValidator $validator,
        PriceImporter $importer,
        ?string $sourceFilename = null
    ): array {
        $this->ensureDirectories();

        $lockHandle = fopen($this->storageDirectory . DIRECTORY_SEPARATOR . 'import.lock', 'c');
        if ($lockHandle === false) {
            throw new ImportException('Не удалось открыть файл блокировки импорта.');
        }

        $locked = false;
        $stagedXlsx = null;
        try {
            if (!flock($lockHandle, LOCK_EX)) {
                throw new ImportException('Не удалось получить блокировку импорта.');
            }
            $locked = true;

            $validator->validateSource($sourceXlsx, $sourceFilename);
            $stagedXlsx = $this->createTemporaryFile($this->uploadsDirectory, '.xlsx');
            $this->copySourceToManagedFile($sourceXlsx, $stagedXlsx);

            // After this copy, validation, parsing and publication use only the managed file.
            $data = $importer->import($stagedXlsx, 'current.xlsx');
            $this->publishManaged($stagedXlsx, $data);
            $stagedXlsx = null;

            return $data;
        } finally {
            if ($stagedXlsx !== null) {
                $this->cleanupTemporaryFile($stagedXlsx);
            }
            if ($locked && !flock($lockHandle, LOCK_UN)) {
                error_log('Не удалось явно освободить блокировку импорта.');
            }
            fclose($lockHandle);
        }
    }

    /** @param array<string, mixed> $data */
    private function publishManaged(string $stagedXlsx, array $data): void
    {
        $this->validateData($data);

        $stagedJson = $this->createTemporaryFile($this->dataDirectory, '.json');
        $currentXlsx = $this->uploadsDirectory . DIRECTORY_SEPARATOR . 'current.xlsx';
        $currentJson = $this->dataDirectory . DIRECTORY_SEPARATOR . 'price.json';
        $archivedXlsx = null;
        $newCurrentInstalled = false;

        try {
            try {
                $json = json_encode(
                    $data,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ) . PHP_EOL;
            } catch (JsonException $exception) {
                throw new ImportException('Не удалось сформировать JSON прайса.', 0, $exception);
            }

            if (file_put_contents($stagedJson, $json, LOCK_EX) === false) {
                throw new ImportException('Не удалось записать временный JSON прайса.');
            }

            try {
                $decoded = json_decode((string) file_get_contents($stagedJson), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new ImportException('Проверка временного JSON завершилась ошибкой.', 0, $exception);
            }
            if (!is_array($decoded)) {
                throw new ImportException('Проверка временного JSON завершилась ошибкой.');
            }
            $this->validateData($decoded);

            if (is_file($currentXlsx)) {
                $archivedXlsx = $this->nextArchivePath();
                if (!rename($currentXlsx, $archivedXlsx)) {
                    throw new ImportException('Не удалось переместить предыдущий current.xlsx в архив.');
                }
            }

            if (!rename($stagedXlsx, $currentXlsx)) {
                throw new ImportException('Не удалось опубликовать новый current.xlsx.');
            }
            $newCurrentInstalled = true;

            if (!rename($stagedJson, $currentJson)) {
                throw new ImportException('Не удалось атомарно опубликовать price.json.');
            }
        } catch (Throwable $exception) {
            $rollbackError = null;
            if ($newCurrentInstalled && is_file($currentXlsx) && !unlink($currentXlsx)) {
                $rollbackError = 'Не удалось удалить новый current.xlsx при откате.';
            }
            if ($archivedXlsx !== null && is_file($archivedXlsx) && !rename($archivedXlsx, $currentXlsx)) {
                $rollbackError = 'Не удалось восстановить предыдущий current.xlsx при откате.';
            }

            if ($rollbackError !== null) {
                throw new ImportException($exception->getMessage() . ' ' . $rollbackError, 0, $exception);
            }
            if ($exception instanceof ImportException) {
                throw $exception;
            }
            throw new ImportException('Ошибка публикации прайса.', 0, $exception);
        } finally {
            $this->cleanupTemporaryFile($stagedJson);
        }
    }

    private function ensureDirectories(): void
    {
        // Storage must be writable by PHP and should reside on one local filesystem.
        foreach ([$this->uploadsDirectory, $this->archiveDirectory, $this->dataDirectory] as $directory) {
            if (!is_dir($directory) || !is_writable($directory)) {
                throw new ImportException(sprintf('Рабочая директория недоступна для записи: %s', $directory));
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function validateData(array $data): void
    {
        if (($data['schema_version'] ?? null) !== 1
            || !is_string($data['imported_at'] ?? null)
            || trim($data['imported_at']) === ''
            || ($data['currency'] ?? null) !== 'RUB'
            || !isset($data['source'], $data['sections'], $data['stats'], $data['warnings'])
            || !is_array($data['source'])
            || !is_array($data['sections'])
            || $data['sections'] === []
            || !is_array($data['stats'])
            || !is_array($data['warnings'])
            || !is_string($data['source']['stored_filename'] ?? null)
            || trim($data['source']['stored_filename']) === ''
            || !is_string($data['source']['title'] ?? null)
            || trim($data['source']['title']) === ''
            || !array_key_exists('price_date', $data['source'])
            || ($data['source']['price_date'] !== null && !is_string($data['source']['price_date']))
        ) {
            throw new ImportException('Сформированные данные не соответствуют схеме прайса.');
        }

        if (DateTimeImmutable::createFromFormat(DATE_ATOM, $data['imported_at']) === false
            || ($data['source']['price_date'] !== null && !$this->isValidDate($data['source']['price_date']))
        ) {
            throw new ImportException('Дата импорта в сформированных данных имеет неверный формат.');
        }

        $items = 0;
        foreach ($data['sections'] as $section) {
            if (!is_array($section)
                || !is_string($section['name'] ?? null)
                || trim($section['name']) === ''
                || !is_array($section['items'] ?? null)
                || $section['items'] === []
            ) {
                throw new ImportException('Раздел в сформированных данных имеет неверную структуру.');
            }
            foreach ($section['items'] as $item) {
                if (!is_array($item)
                    || !is_int($item['number'] ?? null)
                    || $item['number'] < 1
                    || !is_string($item['code'] ?? null)
                    || trim($item['code']) === ''
                    || !is_string($item['name'] ?? null)
                    || trim($item['name']) === ''
                    || !is_int($item['price_minor'] ?? null)
                    || $item['price_minor'] <= 0
                ) {
                    throw new ImportException('Услуга в сформированных данных имеет неверную структуру.');
                }
            }
            $items += count($section['items']);
        }

        foreach ($data['warnings'] as $warning) {
            if (!is_array($warning)
                || !is_int($warning['row'] ?? null)
                || $warning['row'] < 1
                || !is_string($warning['code'] ?? null)
                || trim($warning['code']) === ''
                || !is_string($warning['message'] ?? null)
                || trim($warning['message']) === ''
            ) {
                throw new ImportException('Предупреждение в сформированных данных имеет неверную структуру.');
            }
        }

        if (($data['stats']['sections'] ?? null) !== count($data['sections'])
            || ($data['stats']['items'] ?? null) !== $items
        ) {
            throw new ImportException('Статистика сформированных данных не совпадает с содержимым прайса.');
        }
    }

    private function isValidDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        return $parsed !== false
            && (!is_array($errors) || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $parsed->format('Y-m-d') === $date;
    }

    private function createTemporaryFile(string $directory, string $extension): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $path = $directory . DIRECTORY_SEPARATOR . '.tmp_' . bin2hex(random_bytes(12)) . $extension;
            $handle = @fopen($path, 'x+b');
            if ($handle !== false) {
                fclose($handle);
                return $path;
            }
        }
        throw new ImportException('Не удалось создать уникальное имя временного файла.');
    }

    private function copySourceToManagedFile(string $source, string $destination): void
    {
        $sourceHandle = fopen($source, 'rb');
        if ($sourceHandle === false) {
            throw new ImportException('Не удалось открыть исходный XLSX для копирования.');
        }

        try {
            $destinationHandle = fopen($destination, 'wb');
            if ($destinationHandle === false) {
                throw new ImportException('Не удалось открыть управляемый временный XLSX.');
            }
            try {
                if (stream_copy_to_stream($sourceHandle, $destinationHandle) === false) {
                    throw new ImportException('Не удалось скопировать XLSX во временное хранилище.');
                }
                if (!fflush($destinationHandle)) {
                    throw new ImportException('Не удалось записать управляемый временный XLSX.');
                }
            } finally {
                fclose($destinationHandle);
            }
        } finally {
            fclose($sourceHandle);
        }
    }

    private function cleanupTemporaryFile(string $path): void
    {
        if (is_file($path) && !unlink($path)) {
            error_log(sprintf('Не удалось удалить временный файл: %s', $path));
        }
    }

    private function nextArchivePath(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $now = DateTimeImmutable::createFromFormat(
                'U.u',
                sprintf('%.6F', microtime(true)),
                new DateTimeZone('UTC')
            );
            if ($now === false) {
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            }
            $filename = sprintf('price_%s_%s.xlsx', $now->format('Y-m-d_His_u'), bin2hex(random_bytes(4)));
            $path = $this->archiveDirectory . DIRECTORY_SEPARATOR . $filename;
            if (!file_exists($path)) {
                return $path;
            }
        }
        throw new ImportException('Не удалось создать уникальное имя архивного XLSX.');
    }
}

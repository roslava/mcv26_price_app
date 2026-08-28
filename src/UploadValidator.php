<?php

declare(strict_types=1);

namespace Mcv26\Price;

use Mcv26\Price\Exception\ImportException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;
use ZipArchive;

final class UploadValidator
{
    public const DEFAULT_MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly int $maxBytes = self::DEFAULT_MAX_BYTES)
    {
        if ($this->maxBytes < 1) {
            throw new ImportException('Максимальный размер XLSX должен быть положительным.');
        }
    }

    public function validateSource(string $path, ?string $sourceFilename = null): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ImportException('XLSX-файл не найден или недоступен для чтения.');
        }

        $filenameForExtension = $sourceFilename ?? $path;
        if (strtolower((string) pathinfo($filenameForExtension, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new ImportException('Допускаются только файлы с расширением .xlsx.');
        }

        $this->validateSize($path);
    }

    public function validateWorkbook(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ImportException('Управляемый XLSX-файл не найден или недоступен для чтения.');
        }
        if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new ImportException('Управляемый файл должен иметь расширение .xlsx.');
        }

        $this->validateSize($path);

        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            /** @var string|false $detectedMime */
            $detectedMime = @$finfo->file($path);
            $allowedMimes = [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip',
                'application/x-zip',
                'application/x-zip-compressed',
                'application/x-compressed',
                'application/octet-stream',
                'application/vnd.ms-excel',
                'application/vnd.ms-office',
            ];
            if (is_string($detectedMime) && !in_array(strtolower($detectedMime), $allowedMimes, true)) {
                throw new ImportException(sprintf('Недопустимый MIME-тип XLSX-файла: %s.', $detectedMime));
            }
        }

        $this->validateZipContainer($path);

        try {
            if (IOFactory::identify($path) !== 'Xlsx') {
                throw new ImportException('Файл не является допустимой книгой XLSX.');
            }
        } catch (ImportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ImportException('PhpSpreadsheet не смог распознать XLSX-файл.', 0, $exception);
        }
    }

    private function validateZipContainer(string $path): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new ImportException('Не удалось прочитать сигнатуру XLSX-файла.');
        }
        try {
            $signature = fread($handle, 4);
        } finally {
            fclose($handle);
        }
        if ($signature !== "PK\x03\x04") {
            throw new ImportException('XLSX-файл не является ZIP-контейнером.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ImportException('ZIP-контейнер XLSX повреждён.');
        }
        try {
            foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml'] as $requiredEntry) {
                if ($zip->locateName($requiredEntry, ZipArchive::FL_NOCASE) === false) {
                    throw new ImportException(sprintf(
                        'ZIP-контейнер не содержит обязательный компонент XLSX: %s.',
                        $requiredEntry
                    ));
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function validateSize(string $path): void
    {
        $size = filesize($path);
        if ($size === false || $size < 1) {
            throw new ImportException('XLSX-файл пуст или его размер невозможно определить.');
        }
        if ($size > $this->maxBytes) {
            throw new ImportException(sprintf('Размер XLSX превышает допустимые %d байт.', $this->maxBytes));
        }
    }
}

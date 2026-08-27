<?php

declare(strict_types=1);

namespace Mcv26\Price;

use Mcv26\Price\Exception\ImportException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

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

        // MIME varies between shared hosts and is advisory; PhpSpreadsheet is decisive.
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            /** @var string|false $detectedMime */
            $detectedMime = @$finfo->file($path);
        }

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

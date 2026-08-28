<?php

declare(strict_types=1);

namespace Mcv26\Price;

use DateTimeImmutable;
use DateTimeZone;
use Mcv26\Price\Exception\ImportException;
use Mcv26\Price\Exception\StructureException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

final class PriceImporter
{
    public function __construct(private readonly UploadValidator $validator)
    {
    }

    /** @return array<string, mixed> */
    public function import(string $path, string $storedFilename = 'current.xlsx'): array
    {
        $this->validator->validateWorkbook($path);

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(false);
            $spreadsheet = $reader->load($path);
        } catch (Throwable $exception) {
            throw new ImportException('PhpSpreadsheet не смог открыть XLSX-файл.', 0, $exception);
        }

        try {
            $nonEmptySheets = [];
            foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
                if ($this->sheetHasData($sheet)) {
                    $nonEmptySheets[] = $sheet;
                }
            }

            if (count($nonEmptySheets) !== 1) {
                throw new ImportException(sprintf(
                    'Ожидался один непустой лист, найдено: %d.',
                    count($nonEmptySheets)
                ));
            }

            return $this->parseSheet($nonEmptySheets[0], $storedFilename);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function sheetHasData(Worksheet $sheet): bool
    {
        foreach ($sheet->getCoordinates(false) as $coordinate) {
            $value = $sheet->getCell($coordinate)->getValue();
            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function parseSheet(Worksheet $sheet, string $storedFilename): array
    {
        $expectedHeaders = ['№ услуги', 'Код услуги', 'Наименование услуги', '₽'];
        foreach (['A', 'B', 'C', 'D'] as $index => $column) {
            $actual = $this->normalizedText($sheet->getCell($column . '2')->getValue());
            if ($actual !== $expectedHeaders[$index]) {
                throw StructureException::atRow(2, sprintf(
                    'ожидался заголовок «%s» в колонке %s, получено «%s».',
                    $expectedHeaders[$index],
                    $column,
                    $actual
                ));
            }
        }

        $title = trim((string) $sheet->getCell('A1')->getValue());
        if ($title === '') {
            throw StructureException::atRow(1, 'отсутствует заголовок прайса.');
        }

        $sections = [];
        $warnings = [];
        $seenNumbers = [];
        $itemCount = 0;
        $currentSection = null;
        $highestRow = $sheet->getHighestDataRow();
        $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($row = 3; $row <= $highestRow; $row++) {
            $values = [];
            foreach (['A', 'B', 'C', 'D'] as $column) {
                $cell = $sheet->getCell($column . $row);
                $raw = $cell->getValue();
                if ($cell->isFormula() || (is_string($raw) && str_starts_with($raw, '='))) {
                    throw StructureException::atRow($row, 'формулы в данных прайса не допускаются.');
                }
                $values[$column] = $raw;
            }

            for ($columnIndex = 5; $columnIndex <= $highestColumnIndex; $columnIndex++) {
                $cell = $sheet->getCell([$columnIndex, $row]);
                $raw = $cell->getValue();
                if ($raw !== null && $raw !== '') {
                    throw StructureException::atRow($row, sprintf(
                        'обнаружены неожиданные данные в колонке %s.',
                        Coordinate::stringFromColumnIndex($columnIndex)
                    ));
                }
            }

            if ($this->allEmpty($values)) {
                continue;
            }

            if ($this->isSection($values)) {
                $sections[] = ['name' => $values['A'], 'items' => []];
                $currentSection = array_key_last($sections);
                continue;
            }

            if (!$this->allFilled($values)) {
                throw StructureException::atRow($row, 'частично заполненная или неоднозначная строка.');
            }
            if ($currentSection === null) {
                throw StructureException::atRow($row, 'услуга находится до первого раздела.');
            }
            if (!is_numeric($values['A']) || (float) $values['A'] <= 0 || floor((float) $values['A']) !== (float) $values['A']) {
                throw StructureException::atRow($row, 'номер услуги должен быть положительным целым числом.');
            }
            if (!is_scalar($values['B']) || trim((string) $values['B']) === '') {
                throw StructureException::atRow($row, 'код услуги должен быть непустым текстом.');
            }
            if (!is_scalar($values['C']) || trim((string) $values['C']) === '') {
                throw StructureException::atRow($row, 'название услуги должно быть непустым текстом.');
            }
            $priceMinor = $this->priceToMinorUnits($values['D'], $row);

            $number = (int) $values['A'];
            if (isset($seenNumbers[$number])) {
                $warnings[] = [
                    'row' => $row,
                    'code' => 'duplicate_service_number',
                    'message' => sprintf('Номер услуги %d встречается повторно', $number),
                ];
            } else {
                $seenNumbers[$number] = $row;
            }

            $sections[$currentSection]['items'][] = [
                'number' => $number,
                'code' => (string) $values['B'],
                'name' => (string) $values['C'],
                'price_minor' => $priceMinor,
            ];
            $itemCount++;
        }

        if ($sections === [] || $itemCount === 0) {
            throw new ImportException('Прайс не содержит разделов или услуг.');
        }

        return [
            'schema_version' => 1,
            'imported_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
            'source' => [
                'stored_filename' => basename($storedFilename),
                'title' => $title,
                'price_date' => $this->extractPriceDate($title),
            ],
            'currency' => 'RUB',
            'sections' => $sections,
            'stats' => [
                'sections' => count($sections),
                'items' => $itemCount,
            ],
            'warnings' => $warnings,
        ];
    }

    private function priceToMinorUnits(mixed $value, int $row): int
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw StructureException::atRow($row, 'цена должна быть положительным числом с точностью до копеек.');
        }

        $normalized = trim((string) $value);
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/D', $normalized, $matches)) {
            throw StructureException::atRow($row, 'цена должна быть положительным числом с точностью до копеек.');
        }

        $whole = ltrim($matches[1], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($matches[2] ?? '', 2, '0');
        $minorText = $whole . $fraction;
        if (strlen($minorText) > strlen((string) PHP_INT_MAX)
            || (strlen($minorText) === strlen((string) PHP_INT_MAX) && strcmp($minorText, (string) PHP_INT_MAX) > 0)
        ) {
            throw StructureException::atRow($row, 'цена слишком велика.');
        }

        $minor = (int) $minorText;
        if ($minor <= 0) {
            throw StructureException::atRow($row, 'цена должна быть положительным числом с точностью до копеек.');
        }
        return $minor;
    }

    /** @param array<string, mixed> $values */
    private function allEmpty(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $values */
    private function allFilled(array $values): bool
    {
        foreach ($values as $value) {
            if ($value === null || $value === '') {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $values */
    private function isSection(array $values): bool
    {
        return is_string($values['A'])
            && trim($values['A']) !== ''
            && $values['B'] === null
            && $values['C'] === null
            && $values['D'] === null;
    }

    private function normalizedText(mixed $value): string
    {
        $text = trim((string) $value);
        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    private function extractPriceDate(string $title): ?string
    {
        if (!preg_match('/\b(\d{1,2})\s*\.\s*(\d{1,2})\s*\.\s*(\d{4})\b/u', $title, $matches)) {
            return null;
        }

        $candidate = sprintf('%02d.%02d.%04d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
        $date = DateTimeImmutable::createFromFormat('!d.m.Y', $candidate, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }
        return $date->format('Y-m-d');
    }
}

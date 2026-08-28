<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class XlsxFixture
{
    /** @param list<array{0: mixed, 1: mixed, 2: mixed, 3: mixed}> $rows */
    public static function create(
        array $rows = [[1, 'CODE-1', 'Service', 12.34]],
        int $sheetCount = 1,
        ?callable $customize = null
    ): string
    {
        $spreadsheet = new Spreadsheet();
        while ($spreadsheet->getSheetCount() < $sheetCount) {
            $spreadsheet->createSheet();
        }
        $sheet = $spreadsheet->getSheet(0);
        $sheet->setCellValue('A1', 'Прайс от 01.04.2025');
        $sheet->fromArray(['№ услуги', 'Код услуги', 'Наименование услуги', '₽'], null, 'A2');
        $sheet->setCellValue('A3', 'Section');
        foreach ($rows as $offset => $row) {
            $sheet->fromArray($row, null, 'A' . ($offset + 4));
        }
        if ($customize !== null) {
            $customize($spreadsheet);
        }

        $path = tempnam(sys_get_temp_dir(), 'mcv26_xlsx_');
        if ($path === false) {
            throw new \RuntimeException('Could not create XLSX fixture.');
        }
        $xlsxPath = $path . '.xlsx';
        unlink($path);
        (new Xlsx($spreadsheet))->save($xlsxPath);
        $spreadsheet->disconnectWorksheets();
        return $xlsxPath;
    }
}

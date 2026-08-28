<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests;

use Mcv26\Price\PriceImporter;
use Mcv26\Price\UploadValidator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class RealXlsxContractTest extends TestCase
{
    public function testRealWorkbookContract(): void
    {
        $path = dirname(__DIR__) . '/storage/uploads/current.xlsx';
        self::assertFileExists($path);

        $spreadsheet = IOFactory::load($path);
        self::assertSame(3, $spreadsheet->getSheetCount());
        $nonEmpty = 0;
        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            if ($sheet->getCoordinates(false) !== []) {
                $nonEmpty++;
            }
        }
        self::assertSame(1, $nonEmpty);
        self::assertSame('A1:D296', $spreadsheet->getSheet(0)->calculateWorksheetDataDimension());
        self::assertSame(
            'Прайс ООО «Медицинский Центр Власова» от 01. 04. 2025 г.',
            trim((string) $spreadsheet->getSheet(0)->getCell('A1')->getValue())
        );
        self::assertSame(['№ услуги', 'Код услуги', 'Наименование услуги', '₽'], $spreadsheet->getSheet(0)->rangeToArray('A2:D2')[0]);
        $spreadsheet->disconnectWorksheets();

        $data = (new PriceImporter(new UploadValidator()))->import($path);
        self::assertSame(['sections' => 22, 'items' => 271], $data['stats']);
        self::assertSame('2025-04-01', $data['source']['price_date']);
        self::assertSame([[
            'row' => 295,
            'code' => 'duplicate_service_number',
            'message' => 'Номер услуги 269 встречается повторно',
        ]], $data['warnings']);
        self::assertSame(37000, $data['sections'][0]['items'][0]['price_minor']);
    }
}

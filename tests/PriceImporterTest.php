<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests;

use Mcv26\Price\Exception\StructureException;
use Mcv26\Price\Exception\ImportException;
use Mcv26\Price\PriceImporter;
use Mcv26\Price\Tests\Support\XlsxFixture;
use Mcv26\Price\UploadValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PriceImporterTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testConvertsPricesToIntegerMinorUnits(): void
    {
        $path = $this->fixture([[1, 'A', 'Whole', 370], [2, 'B', 'Fraction', 12.34], [3, 'C', 'Tenth', 1.2]]);
        $data = (new PriceImporter(new UploadValidator()))->import($path);

        self::assertSame([37000, 1234, 120], array_column($data['sections'][0]['items'], 'price_minor'));
        self::assertArrayNotHasKey('price', $data['sections'][0]['items'][0]);
    }

    #[DataProvider('invalidPrices')]
    public function testRejectsInvalidPrices(mixed $price): void
    {
        $path = $this->fixture([[1, 'A', 'Invalid', $price]]);
        $this->expectException(StructureException::class);
        (new PriceImporter(new UploadValidator()))->import($path);
    }

    public static function invalidPrices(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'more than two decimals' => [1.234];
        yield 'text' => ['free'];
    }

    public function testRejectsUnexpectedHeader(): void
    {
        $path = $this->files[] = XlsxFixture::create(customize: static function ($spreadsheet): void {
            $spreadsheet->getSheet(0)->setCellValue('D2', 'Price');
        });
        $this->expectException(StructureException::class);
        (new PriceImporter(new UploadValidator()))->import($path);
    }

    public function testRejectsFormulaInData(): void
    {
        $path = $this->files[] = XlsxFixture::create(customize: static function ($spreadsheet): void {
            $spreadsheet->getSheet(0)->setCellValue('D4', '=10+20');
        });
        $this->expectException(StructureException::class);
        (new PriceImporter(new UploadValidator()))->import($path);
    }

    public function testRejectsUnexpectedDataOutsideContractColumns(): void
    {
        $path = $this->files[] = XlsxFixture::create(customize: static function ($spreadsheet): void {
            $spreadsheet->getSheet(0)->setCellValue('E4', 'unexpected');
        });
        $this->expectException(StructureException::class);
        (new PriceImporter(new UploadValidator()))->import($path);
    }

    public function testRejectsMoreThanOneNonEmptySheet(): void
    {
        $path = $this->files[] = XlsxFixture::create(sheetCount: 2, customize: static function ($spreadsheet): void {
            $spreadsheet->getSheet(1)->setCellValue('A1', 'unexpected');
        });
        $this->expectException(ImportException::class);
        (new PriceImporter(new UploadValidator()))->import($path);
    }

    private function fixture(array $rows): string
    {
        return $this->files[] = XlsxFixture::create($rows);
    }
}

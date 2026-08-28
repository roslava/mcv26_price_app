<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests;

use Mcv26\Price\Exception\ImportException;
use Mcv26\Price\Tests\Support\XlsxFixture;
use Mcv26\Price\UploadValidator;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class UploadValidatorTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testAcceptsMinimalXlsx(): void
    {
        (new UploadValidator())->validateWorkbook($this->files[] = XlsxFixture::create());
        $this->addToAssertionCount(1);
    }

    public function testRejectsPlainTextWithXlsxExtension(): void
    {
        $path = $this->temporary('.xlsx', 'not a workbook');
        $this->expectException(ImportException::class);
        (new UploadValidator())->validateWorkbook($path);
    }

    public function testRejectsZipWithoutRequiredXlsxParts(): void
    {
        $path = $this->temporary('.xlsx', '');
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('unrelated.txt', 'data');
        $zip->close();

        $this->expectException(ImportException::class);
        (new UploadValidator())->validateWorkbook($path);
    }

    public function testRejectsWrongSourceExtension(): void
    {
        $path = $this->files[] = XlsxFixture::create();
        $this->expectException(ImportException::class);
        (new UploadValidator())->validateSource($path, 'price.xls');
    }

    public function testRejectsOversizedWorkbook(): void
    {
        $path = $this->files[] = XlsxFixture::create();
        $this->expectException(ImportException::class);
        (new UploadValidator(10))->validateWorkbook($path);
    }

    private function temporary(string $suffix, string $contents): string
    {
        $base = tempnam(sys_get_temp_dir(), 'mcv26_invalid_');
        if ($base === false) {
            self::fail('Could not create fixture.');
        }
        $path = $base . $suffix;
        rename($base, $path);
        file_put_contents($path, $contents);
        return $this->files[] = $path;
    }
}

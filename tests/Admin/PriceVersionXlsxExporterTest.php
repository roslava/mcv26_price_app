<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests\Admin;

use Mcv26\Price\Admin\PriceVersionXlsxExporter;
use Mcv26\Price\Exception\PriceVersionExportException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PriceVersionXlsxExporterTest extends TestCase
{
    #[DataProvider('moneyValues')]
    public function testMinorUnitsUseCanonicalDecimalWithoutFloatingPoint(int $minor, string $decimal): void
    {
        self::assertSame($decimal, PriceVersionXlsxExporter::minorToDecimal($minor));
    }

    public static function moneyValues(): array
    {
        return [
            [37000, '370.00'],
            [37050, '370.50'],
            [1, '0.01'],
            [99999999, '999999.99'],
        ];
    }

    public function testRejectsNonPositiveMoney(): void
    {
        $this->expectException(PriceVersionExportException::class);
        PriceVersionXlsxExporter::minorToDecimal(0);
    }
}

<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests;

use Mcv26\Price\PublicPriceReader;
use PHPUnit\Framework\TestCase;

final class PublicPriceReaderTest extends TestCase
{
    private ?string $path = null;

    protected function tearDown(): void
    {
        if ($this->path !== null) {
            @unlink($this->path);
        }
    }

    public function testProvidesExistingViewPriceWithoutPersistedFloats(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'mcv26_json_');
        self::assertNotFalse($this->path);
        file_put_contents($this->path, json_encode([
            'schema_version' => 1,
            'source' => ['title' => 'Price', 'price_date' => '2025-04-01'],
            'sections' => [[
                'name' => 'Section',
                'items' => [
                    ['code' => 'A', 'name' => 'Whole', 'price_minor' => 37000],
                    ['code' => 'B', 'name' => 'Fraction', 'price_minor' => 1234],
                ],
            ]],
        ], JSON_THROW_ON_ERROR));

        $data = (new PublicPriceReader($this->path))->read();
        self::assertSame('370', $data['sections'][0]['items'][0]['price']);
        self::assertSame('12.34', $data['sections'][0]['items'][1]['price']);
    }

    public function testReadsCurrentlyPublishedLegacyPriceJson(): void
    {
        $data = (new PublicPriceReader(dirname(__DIR__) . '/storage/data/price.json'))->read();

        self::assertSame(22, count($data['sections']));
        self::assertSame(37000, $data['sections'][0]['items'][0]['price_minor']);
        self::assertSame('370', $data['sections'][0]['items'][0]['price']);
    }

    public function testNewMinorUnitsAreAuthoritativeWhenBothFieldsExist(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'mcv26_json_');
        self::assertNotFalse($this->path);
        file_put_contents($this->path, json_encode([
            'schema_version' => 1,
            'source' => ['title' => 'Price', 'price_date' => null],
            'sections' => [[
                'name' => 'Section',
                'items' => [[
                    'code' => 'A',
                    'name' => 'Service',
                    'price_minor' => 1234,
                    'price' => 999,
                ]],
            ]],
        ], JSON_THROW_ON_ERROR));

        $data = (new PublicPriceReader($this->path))->read();
        self::assertSame(1234, $data['sections'][0]['items'][0]['price_minor']);
        self::assertSame('12.34', $data['sections'][0]['items'][0]['price']);
    }
}

<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests;

use PHPUnit\Framework\TestCase;

final class PublicIndexPresentationTest extends TestCase
{
    public function testPublicHeaderUsesLocalClientLogo(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');

        self::assertStringContainsString('src="/assets/mcv26_logo_h.png"', $source);
        self::assertStringContainsString('alt="Медицинский Центр Власова"', $source);
        self::assertStringNotContainsString('class="brand-mark"', $source);
        self::assertStringNotContainsString('storage.yandexcloud.net', $source);
    }

    public function testLocalLogoHasExpectedDimensions(): void
    {
        $path = dirname(__DIR__) . '/public/assets/mcv26_logo_h.png';
        self::assertFileExists($path);
        $size = getimagesize($path);
        self::assertIsArray($size);
        self::assertSame(748, $size[0]);
        self::assertSame(186, $size[1]);
        self::assertSame('image/png', $size['mime']);
    }

    public function testFreshInstallHasDedicatedEmptyPriceStateInsideNormalLayout(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');

        self::assertStringContainsString('if ($priceData === null)', $source);
        self::assertStringContainsString('Прайс-лист пока не опубликован.', $source);
        self::assertStringContainsString('<header class="public-header">', $source);
        self::assertStringContainsString('<footer class="public-footer">', $source);
    }
}

<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests;

use PHPUnit\Framework\TestCase;

final class PublicIndexPresentationTest extends TestCase
{
    public function testPublicHeaderUsesLocalClientLogo(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');

        self::assertStringContainsString('href="https://mcv26.ru/"', $source);
        self::assertStringContainsString("AppUrl::assetPath('mcv26_logo_h.png')", $source);
        self::assertStringContainsString('alt="Медицинский Центр Власова"', $source);
        self::assertStringNotContainsString('class="brand-mark"', $source);
        self::assertStringNotContainsString('src="https://storage.yandexcloud.net', $source);
    }

    public function testPublicHeaderAndHeroShareOneBackgroundWrapper(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
        $styles = (string) file_get_contents(dirname(__DIR__) . '/public/assets/price.css');

        self::assertStringContainsString('<div class="public-hero-area">', $source);
        self::assertStringContainsString('background-image: url("https://storage.yandexcloud.net/mcv26/price_bg.webp");', $styles);
        self::assertStringContainsString('background-color: #004E89;', $styles);
        self::assertStringContainsString('background-position: right center;', $styles);
        self::assertStringContainsString('background-size: auto 100%;', $styles);
        self::assertStringContainsString('.public-hero-area::before', $styles);
        self::assertStringContainsString('background: transparent;', $styles);
        self::assertStringContainsString('img-src \'self\' data: https://storage.yandexcloud.net;', $source);
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

    public function testServiceRowsHaveSubtlePointerHoverAndReducedMotionFallback(): void
    {
        $styles = (string) file_get_contents(dirname(__DIR__) . '/public/assets/price.css');

        self::assertMatchesRegularExpression('/\.service-row:hover\s*\{[^}]*background:/s', $styles);
        self::assertStringContainsString('transition: background-color 140ms ease;', $styles);
        self::assertStringContainsString('.service-row { transition: none; }', $styles);
    }

    public function testFooterHasDiscreetAccessibleAdminLink(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');

        self::assertStringContainsString("class=\"admin-service-link\" href=\"<?= public_e(AppUrl::adminPath('/')) ?>\"", $source);
        self::assertStringContainsString('title="Администрирование прайса"', $source);
        self::assertStringContainsString('aria-label="Администрирование прайса"', $source);
        self::assertStringContainsString('<svg viewBox="0 0 24 24"', $source);
    }

    public function testBackToTopLinkLivesInsideSearchPanel(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');

        self::assertStringContainsString('<section class="search-panel"', $source);
        self::assertStringContainsString('<a class="back-to-tools" href="#" aria-label="В начало страницы">В начало</a>', $source);
        self::assertSame(1, substr_count($source, 'class="back-to-tools"'));
        self::assertStringNotContainsString('↑ К поиску', $source);
    }

    public function testBackToTopButtonIsAlignedInSearchPanel(): void
    {
        $styles = (string) file_get_contents(dirname(__DIR__) . '/public/assets/price.css');

        self::assertStringContainsString('"label input status back"', $styles);
        self::assertStringContainsString('.back-to-tools {', $styles);
        self::assertStringContainsString('grid-area: back;', $styles);
    }

    public function testBackToTopButtonAppearsOnlyAfterScrollingPastSearchPanel(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__) . '/public/assets/price.js');

        self::assertStringContainsString('const updateBackToToolsVisibility = () => {', $script);
        self::assertStringContainsString('searchPanel.getBoundingClientRect().bottom + window.scrollY + 80', $script);
        self::assertStringContainsString('backToTools.hidden = window.scrollY <= revealAt;', $script);
        self::assertStringContainsString('const recalculateRevealAt = () => {', $script);
        self::assertStringContainsString('updateBackToToolsVisibility();', $script);
        self::assertStringContainsString("window.addEventListener('pageshow', () => {", $script);
    }

    public function testPublicPriceSectionHeadingsUseFullWidthNavyUppercaseStyle(): void
    {
        $styles = (string) file_get_contents(dirname(__DIR__) . '/public/assets/price.css');

        self::assertStringContainsString('.price-section > h2 {', $styles);
        self::assertStringContainsString('background: #004E89;', $styles);
        self::assertStringContainsString('color: #FFFFFF;', $styles);
        self::assertStringContainsString('text-transform: uppercase;', $styles);
        self::assertStringContainsString('overflow-wrap: anywhere;', $styles);
    }

    public function testPublicHeroAndFooterCopyAndHomeLinks(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
        $styles = (string) file_get_contents(dirname(__DIR__) . '/public/assets/price.css');

        self::assertStringContainsString('<h1>Услуги и цены</h1>', $source);
        self::assertStringNotContainsString('<p class="intro">Цены носят информационный характер и могут обновляться.</p>', $source);
        self::assertStringNotContainsString('class="source-title"', $source);
        self::assertStringContainsString('class="footer-price-source"', $source);
        self::assertStringContainsString('от <time datetime="<?= public_e($priceData[\'source\'][\'price_date\']) ?>">', $source);
        self::assertSame(2, substr_count($source, '← вернуться на главную страницу'));
        self::assertStringContainsString('<a class="price-home-link" href="https://mcv26.ru/">← вернуться на главную страницу</a>', $source);
        self::assertStringNotContainsString('<a class="footer-home-link"', $source);
        self::assertStringContainsString('.price-home-link {', $styles);
        self::assertStringContainsString('text-align: right;', $styles);
        self::assertStringContainsString('background: #004e87;', $styles);
        self::assertStringContainsString('padding: 30px 0;', $styles);
        self::assertStringContainsString('font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;', $styles);
    }

    public function testSectionNavigationIsSidebarAroundMainPriceContent(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
        $styles = (string) file_get_contents(dirname(__DIR__) . '/public/assets/price.css');

        self::assertStringContainsString('<div class="price-layout">', $source);
        self::assertStringContainsString('<nav class="section-nav" aria-label="Разделы прайс-листа">', $source);
        self::assertStringContainsString('<div class="price-main">', $source);
        self::assertStringContainsString('grid-template-columns: minmax(0, 1fr) minmax(180px, 220px);', $styles);
        self::assertStringContainsString('position: sticky;', $styles);
        self::assertStringContainsString('max-height: calc(100vh - 24px);', $styles);
        self::assertStringContainsString('flex-wrap: wrap;', $styles);
        self::assertStringContainsString('font-size: 11px;', $styles);
        self::assertStringContainsString('background: #f9f8e1;', $styles);
        self::assertStringContainsString('text-transform: uppercase;', $styles);
        self::assertStringContainsString('overflow-wrap: anywhere;', $styles);
        self::assertStringContainsString('.section-nav a.is-active', $styles);
        self::assertStringContainsString('background: #f9f8e1;', $styles);
        self::assertStringContainsString('background: #ffd3b2;', $styles);
        self::assertStringContainsString('IntersectionObserver', $script = (string) file_get_contents(dirname(__DIR__) . '/public/assets/price.js'));
        self::assertStringContainsString("aria-current', 'location'", $script);
        self::assertStringContainsString('setActiveSectionFromPosition();', $script);
        self::assertStringContainsString('@media (max-width: 900px)', $styles);
    }
}

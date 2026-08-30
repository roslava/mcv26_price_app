<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests;

use Mcv26\Price\AppUrl;
use PHPUnit\Framework\TestCase;

final class AppUrlTest extends TestCase
{
    private array $previous = [];

    protected function tearDown(): void
    {
        foreach (['MCV26_BASE_PATH', 'MCV26_PUBLIC_BASE_PATH', 'MCV26_ADMIN_BASE_PATH'] as $name) {
            $value = $this->previous[$name] ?? null;
            putenv($value === null ? $name : $name . '=' . $value);
        }
    }

    public function testRootBasePathRemainsBackwardCompatible(): void
    {
        $this->setPaths('/', '/admin/');

        self::assertSame('/', AppUrl::publicBasePath());
        self::assertSame('/admin/', AppUrl::adminBasePath());
        self::assertSame('/', AppUrl::publicPath('/'));
        self::assertSame('/admin/', AppUrl::adminPath('/'));
        self::assertSame('/assets/admin.css', AppUrl::assetPath('admin.css'));
    }

    public function testConfiguredSubdirectoryPrefixesApplicationUrls(): void
    {
        $this->setPaths('/new-price/', '/price-admin/');

        self::assertSame('/new-price/', AppUrl::publicBasePath());
        self::assertSame('/price-admin/', AppUrl::adminBasePath());
        self::assertSame('/new-price/', AppUrl::publicPath('/'));
        self::assertSame('/price-admin/publish-version.php', AppUrl::adminPath('/publish-version.php'));
        self::assertSame('/new-price/assets/price.css', AppUrl::assetPath('price.css'));
        self::assertStringNotContainsString('/new-price/admin/', AppUrl::adminPath('/'));
        self::assertSame('/price-admin/draft.php?id=22', AppUrl::adminPath('draft.php?id=22'));
        self::assertStringNotContainsString('/price-admin/admin/', AppUrl::adminPath('draft.php?id=22'));
    }

    public function testRoutingHelpersUseSeparatePathsForPublicAndAdmin(): void
    {
        $bootstrap = (string) file_get_contents(dirname(__DIR__) . '/src/admin_bootstrap.php');
        $public = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
        $draftScript = (string) file_get_contents(dirname(__DIR__) . '/public/assets/admin-draft.js');

        self::assertStringContainsString('AppUrl::adminPath($path)', $bootstrap);
        self::assertStringContainsString("AppUrl::adminPath('logout.php')", $bootstrap);
        self::assertStringContainsString("AppUrl::adminPath('/')", $public);
        self::assertStringContainsString('href="https://mcv26.ru/"', $public);
        self::assertStringContainsString("appUrl('export-version.php')", $draftScript);
        self::assertStringNotContainsString("admin_url('/admin/", $bootstrap . $public);
        self::assertStringNotContainsString("appUrl('admin/", $draftScript);
    }

    private function setPaths(string $public, string $admin): void
    {
        foreach (['MCV26_BASE_PATH', 'MCV26_PUBLIC_BASE_PATH', 'MCV26_ADMIN_BASE_PATH'] as $name) {
            $value = getenv($name);
            $this->previous[$name] = $value === false ? null : $value;
        }
        putenv('MCV26_PUBLIC_BASE_PATH=' . $public);
        putenv('MCV26_ADMIN_BASE_PATH=' . $admin);
    }
}

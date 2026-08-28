<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests\Storage;

use Mcv26\Price\Storage\OriginalXlsxStorage;
use Mcv26\Price\Tests\Support\XlsxFixture;
use Mcv26\Price\UploadValidator;
use PHPUnit\Framework\TestCase;

final class OriginalXlsxStorageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'mcv26_storage_');
        self::assertNotFalse($base);
        unlink($base);
        mkdir($base);
        mkdir($base . '/public');
        mkdir($base . '/originals');
        $this->root = $base;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/originals/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root . '/originals');
        rmdir($this->root . '/public');
        rmdir($this->root);
    }

    public function testStoresValidatedWorkbookUnderServerGeneratedNameOutsidePublic(): void
    {
        $source = XlsxFixture::create();
        try {
            $storage = new OriginalXlsxStorage(
                $this->root . '/originals',
                $this->root . '/public',
                new UploadValidator()
            );
            $name = $storage->store($source, 'client-name.xlsx');
            self::assertMatchesRegularExpression('/^price_\d{8}_\d{6}_[a-f0-9]{32}\.xlsx$/', $name);
            self::assertFileExists($this->root . '/originals/' . $name);
            self::assertStringNotContainsString('client-name', $name);
        } finally {
            unlink($source);
        }
    }
}

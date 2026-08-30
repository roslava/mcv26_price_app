<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests;

use Mcv26\Price\Exception\StructureException;
use Mcv26\Price\PriceImporter;
use Mcv26\Price\PriceRepository;
use Mcv26\Price\Tests\Support\XlsxFixture;
use Mcv26\Price\UploadValidator;
use PHPUnit\Framework\TestCase;

final class PriceRepositoryFreshInstallTest extends TestCase
{
    private string $storageDirectory;
    /** @var list<string> */
    private array $fixtures = [];

    protected function setUp(): void
    {
        $this->storageDirectory = sys_get_temp_dir() . '/mcv26_storage_' . bin2hex(random_bytes(8));
        foreach (['uploads', 'archive', 'data'] as $directory) {
            self::assertTrue(mkdir($this->storageDirectory . '/' . $directory, 0700, true));
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            @unlink($fixture);
        }
        foreach (['uploads', 'archive', 'data'] as $directory) {
            foreach (glob($this->storageDirectory . '/' . $directory . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->storageDirectory . '/' . $directory);
        }
        @unlink($this->storageDirectory . '/import.lock');
        @rmdir($this->storageDirectory);
    }

    public function testFirstValidUploadPublishesWithoutCreatingAnArchive(): void
    {
        $xlsx = $this->fixtures[] = XlsxFixture::create();

        $data = $this->repository()->importAndPublish(
            $xlsx,
            $this->validator(),
            new PriceImporter($this->validator()),
            'первый-прайс.xlsx'
        );

        self::assertSame('RUB', $data['currency']);
        self::assertFileExists($this->storageDirectory . '/uploads/current.xlsx');
        self::assertFileExists($this->storageDirectory . '/data/price.json');
        self::assertSame([], glob($this->storageDirectory . '/archive/*.xlsx') ?: []);
    }

    public function testFailedFirstUploadLeavesNoPublishedArtifacts(): void
    {
        $xlsx = $this->fixtures[] = XlsxFixture::create(customize: static function ($spreadsheet): void {
            $spreadsheet->getSheet(0)->setCellValue('D2', 'неверный заголовок');
        });

        try {
            $this->repository()->importAndPublish(
                $xlsx,
                $this->validator(),
                new PriceImporter($this->validator()),
                'невалидный.xlsx'
            );
            self::fail('Ожидалась ошибка структуры XLSX.');
        } catch (StructureException) {
            self::assertFileDoesNotExist($this->storageDirectory . '/uploads/current.xlsx');
            self::assertFileDoesNotExist($this->storageDirectory . '/data/price.json');
            self::assertSame([], glob($this->storageDirectory . '/archive/*.xlsx') ?: []);
        }
    }

    private function repository(): PriceRepository
    {
        return new PriceRepository($this->storageDirectory);
    }

    private function validator(): UploadValidator
    {
        return new UploadValidator();
    }
}

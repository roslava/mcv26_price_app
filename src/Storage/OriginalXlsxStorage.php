<?php

declare(strict_types=1);

namespace Mcv26\Price\Storage;

use Mcv26\Price\UploadValidator;
use RuntimeException;

final class OriginalXlsxStorage
{
    public function __construct(
        private readonly string $directory,
        string $publicDirectory,
        private readonly UploadValidator $validator
    ) {
        $storage = realpath($this->directory);
        $public = realpath($publicDirectory);
        if ($storage === false || $public === false) {
            throw new RuntimeException('Storage and public directories must exist.');
        }
        $storage = rtrim($storage, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $public = rtrim($public, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (str_starts_with($storage, $public)) {
            throw new RuntimeException('Original XLSX storage must be outside the public directory.');
        }
        if (!is_dir($this->directory) || !is_writable($this->directory)) {
            throw new RuntimeException('Original XLSX storage directory is not writable.');
        }
    }

    public function store(string $source, ?string $originalFilename = null): string
    {
        $this->validator->validateSource($source, $originalFilename);
        $name = sprintf('price_%s_%s.xlsx', gmdate('Ymd_His'), bin2hex(random_bytes(16)));
        $temporary = $this->directory . DIRECTORY_SEPARATOR . '.' . $name . '.tmp';
        $destination = $this->directory . DIRECTORY_SEPARATOR . $name;
        if (!copy($source, $temporary)) {
            throw new RuntimeException('Could not stage original XLSX.');
        }
        if (!rename($temporary, $destination)) {
            @unlink($temporary);
            throw new RuntimeException('Could not store original XLSX.');
        }
        return $name;
    }

    public function path(string $storedFilename): string
    {
        $this->assertGeneratedFilename($storedFilename);
        return $this->directory . DIRECTORY_SEPARATOR . $storedFilename;
    }

    public function matches(string $storedFilename, string $sha256): bool
    {
        $path = $this->path($storedFilename);
        $actual = is_file($path) && is_readable($path) ? hash_file('sha256', $path) : false;
        return is_string($actual) && hash_equals($sha256, $actual);
    }

    public function remove(string $storedFilename): void
    {
        $path = $this->path($storedFilename);
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Could not remove stored original XLSX.');
        }
    }

    private function assertGeneratedFilename(string $storedFilename): void
    {
        if (!preg_match('/^price_\d{8}_\d{6}_[a-f0-9]{32}\.xlsx$/D', $storedFilename)) {
            throw new RuntimeException('Stored XLSX filename is invalid.');
        }
    }
}

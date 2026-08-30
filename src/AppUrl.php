<?php

declare(strict_types=1);

namespace Mcv26\Price;

final class AppUrl
{
    public static function publicBasePath(): string
    {
        $configured = getenv('MCV26_PUBLIC_BASE_PATH');
        if (!is_string($configured) || trim($configured) === '') {
            $configured = getenv('MCV26_BASE_PATH');
        }
        return self::normalizeBasePath($configured, '/');
    }

    public static function adminBasePath(): string
    {
        $configured = getenv('MCV26_ADMIN_BASE_PATH');
        if (!is_string($configured) || trim($configured) === '') {
            $configured = getenv('MCV26_BASE_PATH');
        }
        return self::normalizeBasePath($configured, '/admin/');
    }

    public static function assetPath(string $path): string
    {
        return self::publicPath('assets/' . ltrim($path, '/'));
    }

    public static function publicPath(string $path = '/'): string
    {
        return self::join(self::publicBasePath(), $path);
    }

    public static function adminPath(string $path = '/'): string
    {
        return self::join(self::adminBasePath(), $path);
    }

    public static function basePath(): string
    {
        return self::publicBasePath();
    }

    public static function path(string $path = '/'): string
    {
        return self::publicPath($path);
    }

    private static function normalizeBasePath(mixed $configured, string $default): string
    {
        if (!is_string($configured) || trim($configured) === '' || trim($configured) === '/') {
            return $default;
        }

        return '/' . trim(trim($configured), '/') . '/';
    }

    private static function join(string $base, string $path): string
    {
        $normalizedPath = '/' . ltrim($path, '/');
        $base = rtrim($base, '/');

        return ($base === '' ? '' : $base) . ($normalizedPath === '/' ? '/' : $normalizedPath);
    }
}

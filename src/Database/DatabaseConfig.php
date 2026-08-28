<?php

declare(strict_types=1);

namespace Mcv26\Price\Database;

use RuntimeException;

final readonly class DatabaseConfig
{
    public function __construct(
        public string $dsn,
        public string $username,
        public string $password
    ) {
        if ($this->dsn === '') {
            throw new RuntimeException('Database DSN must not be empty.');
        }
    }

    public static function fromEnvironment(): self
    {
        $dsn = self::environment('MCV26_DB_DSN');
        if ($dsn === null) {
            $host = self::environment('MCV26_DB_HOST') ?? '127.0.0.1';
            $port = self::environment('MCV26_DB_PORT') ?? '3306';
            $database = self::environment('MCV26_DB_NAME');
            if ($database === null) {
                throw new RuntimeException('Set MCV26_DB_DSN or MCV26_DB_NAME.');
            }
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);
        }

        return new self(
            $dsn,
            self::environment('MCV26_DB_USER') ?? '',
            self::environment('MCV26_DB_PASSWORD') ?? ''
        );
    }

    private static function environment(string $name): ?string
    {
        $value = getenv($name);
        return is_string($value) && $value !== '' ? $value : null;
    }
}

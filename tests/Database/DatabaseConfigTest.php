<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests\Database;

use Mcv26\Price\Database\DatabaseConfig;
use PHPUnit\Framework\TestCase;

final class DatabaseConfigTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $original = [];

    protected function setUp(): void
    {
        foreach (['MCV26_DB_DSN', 'MCV26_DB_HOST', 'MCV26_DB_PORT', 'MCV26_DB_NAME', 'MCV26_DB_USER', 'MCV26_DB_PASSWORD'] as $name) {
            $this->original[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }
    }

    public function testBuildsMysqlDsnFromEnvironment(): void
    {
        putenv('MCV26_DB_HOST=db');
        putenv('MCV26_DB_PORT=3307');
        putenv('MCV26_DB_NAME=prices');
        putenv('MCV26_DB_USER=user');
        putenv('MCV26_DB_PASSWORD=secret');

        $config = DatabaseConfig::fromEnvironment();
        self::assertSame('mysql:host=db;port=3307;dbname=prices;charset=utf8mb4', $config->dsn);
        self::assertSame('user', $config->username);
        self::assertSame('secret', $config->password);
    }

    public function testExplicitDsnTakesPrecedence(): void
    {
        putenv('MCV26_DB_DSN=mysql:unix_socket=/tmp/mysql.sock;dbname=prices;charset=utf8mb4');
        $config = DatabaseConfig::fromEnvironment();
        self::assertSame('mysql:unix_socket=/tmp/mysql.sock;dbname=prices;charset=utf8mb4', $config->dsn);
    }
}

<?php

declare(strict_types=1);

namespace Mcv26\Price\Database;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    public function __construct(private readonly PDO $pdo, private readonly string $directory)
    {
    }

    /** @return list<string> */
    public function migrate(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . 'version VARCHAR(191) NOT NULL PRIMARY KEY, applied_at DATETIME(6) NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $files = glob(rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.sql');
        if ($files === false) {
            throw new RuntimeException('Could not enumerate database migrations.');
        }
        sort($files, SORT_STRING);
        $applied = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            $check = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = ?');
            $check->execute([$version]);
            if ($check->fetchColumn() !== false) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false || trim($sql) === '') {
                throw new RuntimeException(sprintf('Migration is empty or unreadable: %s', $file));
            }
            // MySQL and MariaDB implicitly commit DDL. Migrations must therefore be
            // idempotent; the version is recorded only after the complete file succeeds.
            $this->pdo->exec($sql);
            $record = $this->pdo->prepare(
                'INSERT INTO schema_migrations (version, applied_at) VALUES (?, UTC_TIMESTAMP(6))'
            );
            $record->execute([$version]);
            $applied[] = $version;
        }
        return $applied;
    }
}

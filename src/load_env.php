<?php

declare(strict_types=1);

$envFile = dirname(__DIR__) . '/.env';

if (!is_file($envFile) || !is_readable($envFile)) {
    throw new RuntimeException('Production environment file is missing or unreadable.');
}

$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

if ($lines === false) {
    throw new RuntimeException('Unable to read production environment file.');
}

foreach ($lines as $line) {
    $line = trim($line);

    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }

    $position = strpos($line, '=');

    if ($position === false) {
        continue;
    }

    $name = trim(substr($line, 0, $position));
    $value = trim(substr($line, $position + 1));

    if ($name === '') {
        continue;
    }

    if (
        strlen($value) >= 2
        && (
            ($value[0] === '"' && $value[-1] === '"')
            || ($value[0] === "'" && $value[-1] === "'")
        )
    ) {
        $value = substr($value, 1, -1);
    }

    putenv($name . '=' . $value);
    $_ENV[$name] = $value;
}

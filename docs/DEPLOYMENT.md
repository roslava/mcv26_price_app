# Production deployment checklist for mcv26.ru

This standalone PHP/MySQL application is intended for PHP 8.3 shared hosting. It requires no Node.js, Docker, Redis, daemon, or WordPress integration. It is mounted into the existing HTTPS virtual host at `/new-price/` (public) and `/price-admin/` (admin); `/wp-admin/` remains WordPress-owned.

## Layout

Keep the repository outside the WordPress document root when possible, and expose only its `public/` directory through the two scoped nginx locations in `nginx-mcv26-price.conf.example`. Keep `src/`, `vendor/`, `migrations/`, `bin/`, and `storage/` outside the web-served directory.

## Install

Use PHP 8.3 with `pdo_mysql`, `mbstring`, `xml`, `zip`, `fileinfo`, and `gd` enabled. From the application root:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
```

If Composer CLI is unavailable, run it locally and upload the resulting `vendor/` directory. No `.env` file is loaded by the application; configure environment variables through a Timeweb-supported mechanism. Verify in the current Timeweb control panel during deployment how those variables are exposed to the PHP runtime.

## Writable directories

The PHP account must be able to write `storage/`, `storage/data/`, `storage/uploads/`, `storage/archive/`, and `storage/originals/`. Use the hosting account/PHP process owner with mode `750`/`770` as required by the account group; never use `777`. Keep these directories outside `public/`; uploaded files are validated and stored with generated non-executable names.

## Environment

Set placeholders below as server environment variables; never commit real values:

```text
MCV26_DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=REPLACE_DB;charset=utf8mb4
MCV26_DB_USER=REPLACE_DEDICATED_DB_USER
MCV26_DB_PASSWORD=REPLACE_DB_PASSWORD
MCV26_ADMIN_LOGIN=REPLACE_ADMIN_LOGIN
MCV26_ADMIN_PASSWORD_HASH=REPLACE_PASSWORD_HASH
MCV26_PUBLIC_BASE_PATH=/new-price/
MCV26_ADMIN_BASE_PATH=/price-admin/
```

Before migration, verify from the hosting environment that PHP can see all seven required variables without printing their secret values. If the exact hosting mechanism has not yet been verified, **verify it in the current control panel during deployment.** Do not put database or administrator secrets in `.user.ini`.

Generate a password hash with PHP (do not store the plaintext password or resulting hash in Git). Preferred interactive method (the password is read from standard input, not command-line arguments or a file):

```sh
IFS= read -r -s MCV26_PASSWORD
printf '\n'
printf '%s' "$MCV26_PASSWORD" | php -r '$p = trim(stream_get_contents(STDIN)); echo password_hash($p, PASSWORD_DEFAULT), PHP_EOL;'
unset MCV26_PASSWORD
```

The simple one-line alternative below is convenient but exposes the password to shell history/process arguments:

```sh
php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT), PHP_EOL;' 'REPLACE_WITH_PASSWORD'
```

Use a dedicated database/user. Runtime needs normal CRUD privileges; migrations additionally need the DDL privileges used by the migration files.

## Database setup and migration

Create the database/user in Timeweb and back up the database before migration. If SSH or the Timeweb terminal is available, from the application root run:

```sh
php bin/migrate.php
php bin/migrate-current-price.php
```

The second command verifies the retained `storage/uploads/current.xlsx` against `storage/data/price.json` and creates the initial published MySQL version. Migrations are restart-safe. If CLI access is unavailable, use Timeweb's supported terminal or a controlled maintenance procedure; do not expose a permanent web migration endpoint.

## Smoke test

1. Open `https://mcv26.ru/new-price/` and confirm the published date and prices.
2. Open `/price-admin/login.php` and confirm configured credentials work.
3. Upload an XLSX; confirm it creates a draft.
4. Edit/save the draft; confirm public output remains on the previous publication.
5. Export the draft XLSX.
6. Publish it; confirm public output switches and the previous version is archived.
7. Restore an archived version; confirm it creates a private draft only.
8. Confirm `src/`, `vendor/`, `migrations/`, `bin/`, `storage/`, and credentials are not web-accessible.
9. Request `https://mcv26.ru/new-price/.env.production.example`; it must return 403 or 404 and never expose file contents. If it is downloadable, deployment is **not complete** until example/env files are kept outside the served directory or blocked by nginx.
10. Confirm effective PHP settings have `display_errors=Off` and `log_errors=On`.
11. Perform a controlled non-sensitive error/log smoke test, verify the corresponding PHP error appears in the Timeweb hosting log interface or configured PHP error log, and verify no technical error is shown to the browser. Verify the exact log location in the current Timeweb hosting panel during deployment.

If an import rolls back after storing an original and filesystem cleanup itself fails, an orphan generated file may remain in `storage/originals/`. It is not referenced by a committed database version and cannot affect public publication; no automatic cleanup job is required for Stage 11.

## Rollback

Retain the Stage 9 checkpoint `5bea2955666a910143ca410b6d4ecb84efcbc544`, Stage 10 checkpoint `71c7183b8d44d86296e423d4ad066a5f3fda578c`, `storage/uploads/current.xlsx`, `storage/data/price.json`, database backups, and all version history. Before changes, take a DB backup. A code rollback is a deliberate deployment of the selected Git checkpoint; do not automatically switch runtime back to JSON, delete versions, or delete retained originals.

## HTTPS, sessions, and logging

Serve both paths from the existing `https://mcv26.ru` virtual host. Admin cookies are HttpOnly and SameSite=Lax, strict session mode is enabled, and the Secure flag is derived from the actual HTTPS request. Do not blindly trust arbitrary `X-Forwarded-Proto`. Keep server-side logging enabled and browser error display disabled. HSTS should be enabled only after HTTPS for the whole domain is confirmed.

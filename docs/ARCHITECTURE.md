# Архитектура mcv26_price_app

## Границы приложения

Приложение — server-rendered PHP без framework и frontend build step. Каждый файл в `public/` или `public/admin/` является entry point. Общая бизнес-логика находится в `src/`, SQL — в `migrations/`, непубличные оригиналы — в `storage/originals/`.

Основной runtime использует MariaDB/MySQL. Файловая модель `storage/uploads/current.xlsx` + `storage/data/price.json` является legacy-моделью и нужна только для первоначальной миграции и совместимости отдельных тестируемых классов.

## Потоки запросов

### Публичный прайс

```text
GET public/index.php
  → src/load_env.php
  → DatabaseConfig::fromEnvironment()
  → PdoConnectionFactory
  → DatabasePublicPriceReader::read()
  → price_versions(status=published)
  → categories → services.current_price_minor
  → escaped HTML + price.css + price.js
```

Если БД недоступна или published-граф некорректен, endpoint возвращает HTTP 503. Если published-версии нет, показывается штатное пустое состояние.

### Административные страницы

Все admin entry points подключают `src/admin_bootstrap.php`. Bootstrap:

1. запрещает вывод PHP errors посетителю;
2. ставит no-cache и security headers (CSP, `nosniff`, `DENY`, referrer policy);
3. загружает `.env` и Composer autoload;
4. запускает `AdminSession`;
5. предоставляет escaping, URL и redirect helpers.

Основные endpoints:

| Endpoint | Метод | Назначение |
|---|---:|---|
| `public/admin/login.php` | GET/POST | вход |
| `public/admin/logout.php` | POST | выход |
| `public/admin/index.php` | GET/POST | dashboard и upload XLSX |
| `public/admin/draft.php` | GET | редактор draft |
| `public/admin/save-draft.php` | POST JSON | сохранение всех текущих цен |
| `public/admin/publish-version.php` | POST JSON | публикация draft |
| `public/admin/edit-current.php` | POST | создать draft из published |
| `public/admin/restore-version.php` | POST JSON | создать draft из archived |
| `public/admin/export-version.php` | GET | скачать выбранную версию как XLSX |

JSON endpoints используют `X-CSRF-Token`; HTML forms передают `csrf_token`. Save и publish используют optimistic revision/expected state поверх row locks в БД.

## Жизненный цикл версии

```text
                         ┌─────────────┐
XLSX upload ───────────► │    draft    │
                         └──────┬──────┘
                                │ save: current prices + revision + audit
                                │ publish transaction
                                ▼
                         ┌─────────────┐
                         │  published  │ ◄── ровно одна или ни одной
                         └──────┬──────┘
                                │ публикация другого draft
                                ▼
                         ┌─────────────┐
                         │  archived   │
                         └──────┬──────┘
                                │ restore (копирование)
                                └──────────────► новый draft
```

`CurrentPublishedVersionEditorStarter` также создаёт новый draft-клон published. Если draft уже существует, endpoint возвращает последний draft вместо создания ещё одного. Для клона текущая цена источника становится и импортной, и текущей ценой нового baseline.

`ArchivedVersionRestorer` не переводит archived обратно в published и не изменяет источник: он копирует граф в новый draft с `restored_from_version_id`.

## Модель данных

### `schema_migrations`

- `version` — basename SQL-файла, primary key;
- `applied_at` — UTC-время успешного применения.

### `price_versions`

Ключевые поля:

- `status`: код использует `draft`, `published`, `archived`;
- `revision`: optimistic revision draft, начинается с 0;
- `restored_from_version_id`: self-reference на источник clone/restore;
- `title`, `price_date`, `original_filename`, `stored_xlsx_name`;
- `source_xlsx_sha256`: полный lowercase SHA-256 исходника;
- `source_json_sha256`: только для legacy initial migration;
- `source_identity`: nullable unique identity происхождения;
- `imported_at`, `published_at`, `created_at`.

Индекс статуса не является unique; инвариант одной published-версии обеспечивается транзакционными блокировками и проверками приложения. Readers также fail closed, если published больше одной.

### `categories`

Принадлежит версии по `price_version_id`, удаляется каскадно. `position` уникальна внутри версии. `name` хранится как импортированная строка.

### `services`

Принадлежит категории по `category_id`, удаляется каскадно. `position` уникальна внутри категории. Хранит номер, код, название, импортную и текущую цену. Цены — положительные целые копейки в unsigned bigint.

Штатный editor меняет только `current_price_minor`; импортная цена и metadata услуги после создания не меняются.

### `price_changes`

Audit trail draft-сохранений:

- `version_id` и `service_id`;
- `old_price_minor`, `new_price_minor`;
- `changed_at` в UTC;
- `changed_by` — login из authenticated session.

Запись создаётся только если текущая цена действительно изменилась. Сохранение полного, но неизменённого набора всё равно увеличивает `revision`, хотя UI обычно не отправляет save без изменений.

## Транзакции и конкурентность

### Импорт

`DraftVersionImporter` начинает repository transaction и делает locking read всех `price_versions` с `ORDER BY id FOR UPDATE`. Это сериализует одновременные проверки дубликатов. Новый оригинал записывается внутри операции; если БД-транзакция падает, файл удаляется в catch.

### Сохранение draft

`DraftPriceSaver` блокирует строку версии и все её услуги. Клиент обязан передать цену каждой услуги ровно один раз. Сервер отклоняет неизвестные, повторные, отсутствующие ID и цены вне диапазона 1..9 000 000 000 000 000 копеек. После updates и audit revision меняется условным `UPDATE ... WHERE revision = expected`.

### Публикация

`VersionPublisher` блокирует все версии, сверяет target revision и expected published ID, архивирует прежнюю и публикует новую внутри одной транзакции. Если вкладка устарела или состояние изменил другой запрос, возвращается conflict вместо silent overwrite.

## Импорт и хранение оригинала

`UploadValidator` сначала проверяет путь, расширение и размер. Для managed temporary XLSX дополнительно проверяет MIME (если доступен `finfo`), ZIP signature/components и PhpSpreadsheet type.

`OriginalXlsxStorage` требует, чтобы `storage/originals` существовал, был writable и находился вне `public/`. Имя генерируется как:

```text
price_YYYYMMDD_HHMMSS_<32 lowercase hex>.xlsx
```

Запись идёт через скрытый temporary file и atomic rename. Путь к оригиналу не строится из пользовательского имени.

## Редактор

`DraftEditorPage` формирует строки с immutable `data-imported-minor`, `data-loaded-minor` и service ID. `admin-draft.js`:

- парсит денежный ввод в `BigInt` копейки;
- пересчитывает процент и цену в обе стороны;
- подсчитывает изменения и totals;
- блокирует save/publish при invalid input;
- отправляет полный список цен и expected revision;
- обновляет локальный loaded baseline после успешного save;
- предупреждает через `beforeunload` о валидных несохранённых изменениях.

Процент — presentation state и в payload/БД не входит.

## Публикация и чтение

Публикация не формирует отдельный JSON. `DatabasePublicPriceReader` на каждый публичный запрос читает версию, категории и услуги в position/id order и формирует view model с `currency=RUB`.

`PriceVersionXlsxExporter` строит XLSX из любой версии, используя `current_price_minor`, поэтому export draft отражает сохранённое состояние БД, а не несохранённый browser state. UI предлагает сначала сохранить или скачать последнюю сохранённую версию.

## Legacy migration

`bin/migrate-current-price.php` предназначен для одноразового переноса согласованной пары:

- `storage/uploads/current.xlsx`;
- `storage/data/price.json`.

`CurrentPublicationMigrator` сравнивает ordered content XLSX и JSON, вычисляет оба hash, создаёт identity `initial:<xlsx sha256>`, сохраняет original и публикует версию. Повторный запуск идемпотентен по `source_identity` и дополнительно проверяет весь сохранённый граф.

`bin/import-price.php` относится к прежней файловой схеме и сразу публикует `storage/data/price.json`/`storage/uploads/current.xlsx`; не используйте его для обычного DB workflow админки.

## Security boundaries

- `.env` обязателен и не находится в document root.
- Originals хранятся вне `public/`.
- PDO emulated prepares отключены, errors — exceptions.
- HTML values экранируются через `htmlspecialchars`.
- Admin responses используют restrictive CSP и anti-frame headers.
- Session cookie защищён `HttpOnly`, `SameSite=Lax`, `Secure` на HTTPS.
- Password хранится только как hash; проверяется `password_verify`.
- Изменяющие endpoints требуют authentication + CSRF.
- Upload проверяется по extension, size, container structure и workbook type.

Приложение не реализует rate limiting входа, управление несколькими admin accounts, автоматический backup или удаление версий. Эти возможности нельзя считать существующими без отдельной инфраструктуры.

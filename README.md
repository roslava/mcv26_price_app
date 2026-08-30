# mcv26_price_app

Веб-приложение для импорта, проверки, редактирования, версионирования и публикации прайс-листа медицинского центра MCV26. Исходный прайс поступает в формате XLSX, сохраняется как черновик в MariaDB/MySQL и становится видимым посетителям только после явной публикации администратором.

Подробные материалы:

- [архитектура и модель данных](docs/ARCHITECTURE.md);
- [контракт XLSX и защита от повторной загрузки](docs/PRICE_IMPORT.md);
- [фактический процесс production-деплоя на Timeweb](docs/mcv26_price_deploy.md).

## Назначение и пользовательские сценарии

Публичная часть показывает единственный опубликованный прайс: разделы, услуги, коды, цены, поиск и навигацию по разделам. Она доступна локально по `/`, а в production — по `https://mcv26.ru/new-price/`.

Административная часть позволяет:

- войти по логину и паролю;
- загрузить и проверить XLSX размером до 10 МиБ;
- продолжить работу с существующим черновиком;
- изменить текущую цену напрямую или через процент изменения;
- сохранить черновик, скачать любую версию в XLSX и опубликовать черновик;
- начать новый черновик из текущей опубликованной версии;
- восстановить архивную версию как новый черновик;
- просмотреть историю версий.

Админка доступна локально по `/admin/`, в production — по `https://mcv26.ru/price-admin/`. Загрузка и сохранение черновика не меняют публичный прайс.

## Технологический стек

- PHP: зависимости требуют PHP `^8.2`; локально проект проверен на PHP 8.3.33, production-памятка указывает PHP 8.3 (`/opt/php83/bin/php`).
- Composer 2; PSR-4 autoload для `Mcv26\Price\` и тестов.
- PhpSpreadsheet 5.9.0 для чтения и формирования XLSX.
- MariaDB/MySQL через PDO (`pdo_mysql`); в текущем локальном окружении используется MariaDB client 10.6.23, а hosting-документ фиксирует production Percona Server 5.7.35-38.
- Vanilla JavaScript без сборщика и frontend-фреймворка.
- Обычный CSS без препроцессора.
- PHPUnit 11.5.x; lock-файл сейчас фиксирует 11.5.56.
- Production: nginx + PHP-FPM согласно `nginx-mcv26-price.conf.example`; локально достаточно встроенного PHP-сервера.

## Требования к окружению

Минимально нужны:

- PHP 8.2 или новее;
- Composer;
- MariaDB или MySQL с InnoDB и поддержкой `DATETIME(6)`;
- PHP extensions `ctype`, `dom`, `fileinfo`, `filter`, `gd`, `iconv`, `libxml`, `mbstring`, `pdo`, `pdo_mysql`, `simplexml`, `xml`, `xmlreader`, `xmlwriter`, `zip`, `zlib`;
- для полного теста конкурентных транзакций — также `pcntl` и sockets.

Перечень обязательных расширений следует из `composer.lock` и кода подключения к БД. Веб-сервер должен направлять document root в `public/`, а `storage/` и `.env` не должны быть доступны по HTTP.

## Локальная установка

### 1. Код и зависимости

```bash
git clone https://github.com/roslava/mcv26_price_app.git
cd mcv26_price_app
composer install
```

### 2. MariaDB/MySQL

Создайте отдельную БД и пользователя. Имена ниже — безопасный пример, пароль замените:

```sql
CREATE DATABASE mcv26_price CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mcv26_price'@'127.0.0.1' IDENTIFIED BY 'change-me';
GRANT ALL PRIVILEGES ON mcv26_price.* TO 'mcv26_price'@'127.0.0.1';
FLUSH PRIVILEGES;
```

В Debian/Ubuntu/WSL SQL можно открыть командой `sudo mariadb`. Проект не создаёт саму БД или пользователя, но создаёт таблицы миграциями.

### 3. `.env`

В репозитории есть только production-шаблон `.env.production.example`. Скопируйте его и замените значения:

```bash
cp .env.production.example .env
```

Минимальный локальный вариант:

```env
MCV26_PUBLIC_BASE_PATH=/
MCV26_ADMIN_BASE_PATH=/admin/
MCV26_DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=mcv26_price;charset=utf8mb4
MCV26_DB_USER=mcv26_price
MCV26_DB_PASSWORD=change-me
MCV26_ADMIN_LOGIN=admin
MCV26_ADMIN_PASSWORD_HASH=...
```

Вместо DSN класс `DatabaseConfig` также понимает `MCV26_DB_HOST`, `MCV26_DB_PORT`, `MCV26_DB_NAME`, `MCV26_DB_USER`, `MCV26_DB_PASSWORD`. Если задан `MCV26_DB_DSN`, отдельные host/port/name не используются.

### 4. Пароль администратора

Получите hash безопасного тестового пароля и вставьте результат в `MCV26_ADMIN_PASSWORD_HASH`:

```bash
php -r "echo password_hash('change-me', PASSWORD_DEFAULT), PHP_EOL;"
```

`change-me` — только пример. Не используйте его в production. Приложение не имеет таблицы администраторов и команды создания пользователя: логин и hash читаются из `.env`.

### 5. Storage и миграции

```bash
mkdir -p storage/originals
chmod u+rwx storage/originals
php -d auto_prepend_file=src/load_env.php bin/migrate.php
```

`storage/originals/` уже присутствует в Git через `.gitkeep`, но должен быть writable для пользователя PHP. CLI-скрипты сами не подключают `src/load_env.php`, поэтому выше он указан через `auto_prepend_file`; web entry points загружают `.env` сами. Миграции можно запускать повторно: применённые имена фиксируются в `schema_migrations`.

### 6. Запуск

```bash
php -S 127.0.0.1:8080 -t public
```

Откройте:

- публичный прайс: `http://127.0.0.1:8080/`;
- админку: `http://127.0.0.1:8080/admin/`.

Остановка встроенного сервера — `Ctrl+C` в его терминале.

## Администратор и авторизация

`AdminSession` сравнивает логин из `MCV26_ADMIN_LOGIN` через `hash_equals()` и пароль с `MCV26_ADMIN_PASSWORD_HASH` через `password_verify()`. После успешного входа идентификатор PHP-сессии регенерируется. Cookie:

- называется `mcv26_admin`;
- действует до закрытия браузера;
- имеет `HttpOnly` и `SameSite=Lax`;
- получает `Secure` при HTTPS;
- ограничен административным base path.

Изменение пароля означает генерацию нового `password_hash()` и замену `MCV26_ADMIN_PASSWORD_HASH` в `.env`. Изменение логина — замена `MCV26_ADMIN_LOGIN`. После изменения production `.env` следует войти заново; отдельного интерфейса смены пароля нет.

Все изменяющие admin-запросы защищены CSRF-токеном. JSON endpoints дополнительно проверяют метод, `Content-Type`, авторизацию и ожидаемую ревизию черновика.

## Структура проекта

```text
bin/                 CLI-команды миграции и импорта
docs/                эксплуатационная и архитектурная документация
migrations/          последовательные SQL-миграции MariaDB/MySQL
public/              единственный web document root
public/admin/        административные entry points
public/assets/       CSS, JavaScript и логотип
src/                 PHP-код домена, импорта, БД и админки
storage/originals/   непубличные оригиналы загруженных XLSX
tests/               unit, presentation и DB integration tests
vendor/              Composer dependencies; не хранится в Git
```

Ключевые модули:

- `PriceImporter`, `UploadValidator` — проверка контейнера XLSX и разбор листа;
- `DraftVersionImporter` — импорт в БД и duplicate-upload;
- `DatabasePriceRepository` — версии, разделы, услуги и транзакции;
- `DraftPriceSaver` — полное сохранение цен черновика и аудит;
- `VersionPublisher` — атомарная архивация текущей и публикация новой версии;
- `CurrentPublishedVersionEditorStarter`, `ArchivedVersionRestorer` — создание редактируемых копий;
- `DatabasePublicPriceReader` — строгая сборка публичного прайса из БД;
- `PriceVersionXlsxExporter` — экспорт выбранной версии;
- `AdminSession` — сессия, вход, logout и CSRF;
- `AppUrl` — локальные и production base paths.

Подробности и связи: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Архитектура и жизненный цикл прайса

```text
XLSX
  → HTTP/CLI validation
  → PriceImporter
  → duplicate check под DB-lock
  → draft: price_versions + categories + services
  → редактирование current_price_minor
  → сохранение + price_changes + revision
  → публикация в транзакции
  → прежний published становится archived
  → DatabasePublicPriceReader
  → публичная HTML-страница
```

Импортная цена сохраняется в `services.imported_price_minor`, текущая — в `services.current_price_minor`; обе представлены целым количеством копеек. Новый импорт начинает с одинаковых значений. Публичная часть читает только `current_price_minor` единственной версии со статусом `published`.

Статусы версий: `draft`, `published`, `archived`. Код обеспечивает не более одной опубликованной версии и проверяет ожидаемую текущую версию при публикации. Предыдущая версия не удаляется, а архивируется.

Legacy-классы `PriceRepository`, `PublicPriceReader`, `PriceStatusReader` и команда `bin/migrate-current-price.php` нужны для миграции прежнего файлового хранения `storage/uploads/current.xlsx` + `storage/data/price.json`. Текущий web-поток работает через БД.

## Импорт Excel

Краткий контракт:

- только `.xlsx`, непустой файл не более 10 МиБ;
- ZIP-контейнер с обязательными XLSX-компонентами;
- ровно один непустой лист; дополнительные полностью пустые листы допустимы;
- A1 — непустой заголовок;
- A2:D2 — строго `№ услуги`, `Код услуги`, `Наименование услуги`, `₽` (лишние пробелы нормализуются);
- с третьей строки раздел — строка с непустой строкой только в A;
- услуга — все четыре значения A:D заполнены; A — положительное целое, B/C — непустые, D — положительная цена с точкой и максимум двумя знаками после неё;
- пустые строки игнорируются; данные в E и далее запрещены;
- формулы в A:D строк данных запрещены;
- повторный номер услуги не останавливает импорт, а создаёт warning;
- дата — первая корректная дата `дд.мм.гггг` в A1, иначе `price_date = null`;
- валюта результата — `RUB`.

Полный контракт, ошибки и duplicate-upload: [docs/PRICE_IMPORT.md](docs/PRICE_IMPORT.md).

## Защита от повторной загрузки

После успешного разбора `DraftVersionImporter` считает `hash_file('sha256', $sourcePath)`. SHA-256 сохраняется в `price_versions.source_xlsx_sha256`; сохранённый оригинал повторно сверяется через `hash_equals()`.

В транзакции выполняется `SELECT ... FROM price_versions ORDER BY id FOR UPDATE`: блокируются строки версий, затем версии с тем же hash проверяются от новых к старым.

- Если найден черновик, проверяется его граф и возвращается `existing_draft` без создания копии.
- Если опубликованная версия полностью совпадает с загруженным XLSX по текущим ценам, возвращается `unchanged_published`.
- Иначе создаётся новый черновик.

`source_identity` уникален, но сам SHA-256 больше не уникален. Для обычной загрузки identity имеет вид `upload:<40 символов hash>:<32 hex nonce>`. Случайный nonce позволяет создать новый осмысленный черновик из того же исходного XLSX, если опубликованная версия уже отличается ручными текущими ценами, и одновременно исключает коллизию уникального индекса. Legacy-миграция использует детерминированный `initial:<полный SHA-256>`.

## Редактирование цены

Пользователь не может менять импортную цену, номер, код, название или структуру услуг. Редактируются текущая цена и процент изменения:

```text
Текущая цена = Импортная цена × (1 + Процент / 100)
Процент = ((Текущая цена / Импортная цена) - 1) × 100
```

`public/assets/admin-draft.js` обновляет второе поле непосредственно, не генерируя новое DOM-событие `input`, поэтому цикл событий не возникает. Расчёты используют `BigInt`:

- цена округляется до ближайшей копейки, половина округляется вверх;
- вычисляемый процент показывается с точностью до 0,01 процентного пункта;
- ввод процента допускает знак и до шести дробных знаков; результат обязан оставаться положительной ценой;
- текущая цена допускает максимум две цифры после точки или запятой и должна быть положительной.

В БД процент не хранится. Сохраняется полный набор `current_price_minor`; изменённые услуги получают записи в `price_changes`. `revision` защищает от сохранения из устаревшей вкладки.

Таблица использует `width: 100%`, `table-layout: fixed` и процентную сетку `7 / 12 / 31 / 15 / 17,5 / 17,5`. Наименование переносится. Оба input занимают ширину ячейки. При viewport около 620 px и меньше таблица получает `min-width: 600px`, а горизонтальный скролл остаётся внутри `.draft-table-wrap`, не на странице.

## Публикация и история

При публикации `VersionPublisher` блокирует все версии `FOR UPDATE` и проверяет:

- цель существует и имеет статус `draft`;
- `revision` совпадает с ожидаемой;
- в БД не более одного `published`;
- идентификатор текущей опубликованной версии совпадает с присланным UI.

Текущая версия переводится в `archived`, затем черновик — в `published`, всё в одной транзакции. Публичная часть при каждом запросе читает единственную published-версию из БД; отдельного application cache нет.

Опубликованная версия напрямую не редактируется. Кнопка редактирования создаёт новый draft, где baseline `imported_price_minor` и `current_price_minor` равен текущей цене опубликованной версии. Восстановление архива работает так же: создаётся новая копия-draft с `restored_from_version_id`; архивный источник не изменяется.

## База данных

SQL находится в `migrations/`. `MigrationRunner` применяет файлы по имени и записывает их в `schema_migrations`. DDL в MySQL/MariaDB делает implicit commit, поэтому миграции написаны идемпотентно и считаются применёнными только после успешного выполнения всего файла.

Основные таблицы:

- `schema_migrations` — применённые версии миграций;
- `price_versions` — статус, revision, metadata, hash/identity, даты, ссылка на восстановленный источник;
- `categories` — упорядоченные разделы версии;
- `services` — упорядоченные услуги и две цены в копейках;
- `price_changes` — аудит изменения текущей цены, версия, услуга, старая/новая цена, время и admin login.

Важные ограничения: уникальны `(price_version_id, position)`, `(category_id, position)` и nullable `source_identity`; внешние ключи каскадно удаляют категории, услуги и аудит вместе с владельцем. `restored_from_version_id` при удалении источника становится `NULL`. В приложении нет web-операции удаления версии.

Импортные цены и структура уже созданной версии не изменяются штатными редакторами. Для draft меняются только `current_price_minor` и `revision`; при публикации меняются статус и `published_at`.

## Frontend

- `public/assets/admin-draft.js` — поиск в таблице, двусторонняя цена/процент, summary, save, publish, reset и export.
- `public/assets/admin-versions.js` — upload UX, review, publish/restore и accordion главной страницы админки.
- `public/assets/price.js` — публичный поиск, фильтрация, навигация по разделам и кнопка возврата.
- `public/assets/admin.css` — административный интерфейс и адаптивная таблица редактора.
- `public/assets/price.css` — публичный прайс.
- `public/assets/mcv26_logo_h.png` — логотип обеих частей.
- `src/Admin/DraftEditorPage.php` — HTML таблицы редактора; `public/admin/draft.php` — endpoint страницы.

CSS/JS подключаются через `AppUrl::assetPath()`. В production assets физически синхронизируются в `public_html/new-price/assets`, что важно учитывать при деплое.

## Тесты

Все тесты:

```bash
composer test
# эквивалентно
vendor/bin/phpunit
```

Только integration group:

```bash
composer test:integration
```

Отдельный файл или метод:

```bash
vendor/bin/phpunit tests/PriceImporterTest.php
vendor/bin/phpunit --filter testName tests/PriceImporterTest.php
```

DB integration tests требуют отдельную тестовую БД:

```bash
export MCV26_TEST_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=mcv26_price_test;charset=utf8mb4'
export MCV26_TEST_DB_USER='mcv26_price_test'
export MCV26_TEST_DB_PASSWORD='change-me'
vendor/bin/phpunit --group integration
```

Тесты покрывают XLSX-контракт, upload validation, legacy file repository, миграции, SQL constraints, duplicate/concurrency, draft save/revision/audit, публикацию/архив/restore, публичное чтение, export и HTML/CSS/JS presentation contracts. Без `MCV26_TEST_DB_DSN` DB-тесты корректно отмечаются skipped.

### Известные проблемы текущего набора тестов

На текущем состоянии репозитория полный `vendor/bin/phpunit` воспроизводимо заканчивается двумя несвязанными presentation failures в `PublicIndexPresentationTest` (остальные DB integration tests без test DSN пропускаются):

- тест ищет буквальный селектор `.price-home-link {`, тогда как актуальный CSS задаёт link/visited/hover selectors отдельно;
- тест ожидает `IntersectionObserver`, тогда как актуальный `price.js` обновляет активный раздел через scroll/resize + `requestAnimationFrame`.

Это расхождение тестовых строковых ожиданий и существующей публичной реализации; оно не связано с настройкой БД. Не следует считать полный suite зелёным, пока ожидания или реализация не будут согласованы отдельной задачей.

## Деплой

Production-схема репозитория:

```text
локальная main → GitHub → /home/m/mcv26/repos/mcv26_price_app
                         → /home/m/mcv26/public_html/_mcv26_app
                         → public assets в public_html/new-price/assets
```

Routine deploy выполняет серверный `~/deploy-price.sh`: `git pull --ff-only`, `rsync` приложения без `.env`, `.git`, `storage`, `vendor`, синхронизацию assets, PHP lint и HTTP-check. Скрипт намеренно не использует `rsync --delete`.

### Быстрый деплой

Локально после тестов:

```bash
git status
git push origin main
```

На сервере:

```bash
ssh mcv26@mcv26.ru
~/deploy-price.sh
```

Успех заканчивается строкой `DEPLOY OK`. Полные пути, содержимое скрипта и проверка checksum: [docs/mcv26_price_deploy.md](docs/mcv26_price_deploy.md).

Если добавлена SQL-миграция, routine-скрипт её не запускает. После доставки кода выполните на сервере с production `.env`:

```bash
/opt/php83/bin/php \
  -d auto_prepend_file=/home/m/mcv26/public_html/_mcv26_app/src/load_env.php \
  /home/m/mcv26/public_html/_mcv26_app/bin/migrate.php
```

Если изменился `composer.lock`, учтите, что routine-скрипт сохраняет существующий `vendor/` и сам `composer install` не вызывает. Hosting-документ фиксирует Composer 2 в `/home/m/mcv26/bin/composer2`; обновление выполняется отдельно:

```bash
cd /home/m/mcv26/public_html/_mcv26_app
/opt/php83/bin/php /home/m/mcv26/bin/composer2 \
  install --no-dev --prefer-dist --optimize-autoloader
```

Не публикуйте код, зависящий от новых пакетов, пока этот шаг не выполнен.

После deploy проверьте:

```bash
test -r /home/m/mcv26/public_html/_mcv26_app/.env
test -w /home/m/mcv26/public_html/_mcv26_app/storage/originals
curl -fsS https://mcv26.ru/new-price/ >/dev/null
```

На текущем shared hosting публичными document roots фактически служат wrappers в `public_html/new-price/` и `public_html/price-admin/`, которые подключают закрытую копию `_mcv26_app`; assets копируются физически. Для самостоятельного nginx-развёртывания document root/alias должен вести только в repository `public/`, как в `nginx-mcv26-price.conf.example`.

Для нового nginx-хоста используйте `nginx-mcv26-price.conf.example` как include в существующий HTTPS server block, заменив `APP_ROOT`/socket согласно комментариям. На текущем shared hosting действует отдельная схема wrappers/assets, описанная в deploy-документе.

## Backup и безопасность

Резервировать нужно:

- всю production-БД (версии, услуги, аудит и `schema_migrations`);
- `/home/m/mcv26/public_html/_mcv26_app/.env`;
- `/home/m/mcv26/public_html/_mcv26_app/storage/originals/`;
- серверный `~/deploy-price.sh` и hosting wrappers, потому что они не хранятся в этом репозитории.

Точный инструмент, расписание и место хранения production backup в репозитории не описаны. Backup следует проверить восстановлением в отдельную БД и непубличный каталог.

Нельзя коммитить `.env`, пароли БД, admin hash production, токены, приватные ключи и загруженные XLSX. `.gitignore` исключает `.env`, `vendor/`, runtime storage и `storage/originals/*.xlsx`. Writable должен быть как минимум `storage/originals/`; системный upload temp и temp-каталог нужны PHP для загрузки, проверки и экспорта.

## Типовые операции

### Запустить и остановить локально

```bash
php -d auto_prepend_file=src/load_env.php bin/migrate.php
php -S 127.0.0.1:8080 -t public
# остановка: Ctrl+C
```

### MariaDB после перезапуска WSL

```bash
sudo service mariadb start
sudo service mariadb status
```

Если установлен MySQL, имя сервиса обычно `mysql`; проверьте доступные сервисы своей WSL-системы. Репозиторий не содержит systemd/service-конфигурации БД.

### Новый пароль администратора

```bash
php -r "echo password_hash('change-me', PASSWORD_DEFAULT), PHP_EOL;"
```

Замените hash в `.env`; сам пример-пароль использовать нельзя.

### Загрузить и опубликовать прайс

1. Войти в `/admin/` или production `/price-admin/`.
2. Открыть «Загрузить новый прайс» и выбрать `.xlsx`.
3. После проверки при необходимости открыть черновик и изменить цены.
4. Сохранить изменения.
5. Нажать «Опубликовать» и подтвердить действие.
6. Проверить публичную страницу.

CLI может только создать/найти draft:

```bash
php -d auto_prepend_file=src/load_env.php bin/import-draft.php /path/to/price.xlsx
```

### Git

```bash
git status -sb                    # ветка и изменённые файлы
git log -1 --oneline              # текущий коммит
git diff                          # незакоммиченные изменения
git diff --cached                 # staged-изменения
```

Перед откатом сначала просмотрите `git diff`. Для одного файла с ненужными незакоммиченными изменениями:

```bash
git restore path/to/file
```

Команда необратимо удаляет незакоммиченные изменения этого файла; не применяйте `git reset --hard` или массовый restore без резервной копии.

## Troubleshooting

### БД не запущена / connection refused

Запустите MariaDB/MySQL, проверьте `mysqladmin ping` или вход клиентом, затем повторите `php -d auto_prepend_file=src/load_env.php bin/migrate.php`. В WSL после перезагрузки сервис часто нужно запустить вручную.

### Access denied или Unknown database

Сверьте DSN, пользователя и права в `.env`. Убедитесь, что БД создана, пользователь разрешён именно с того host (`localhost`/`127.0.0.1`), который указан в DSN.

### `.env` отсутствует

`src/load_env.php` завершает запрос с ошибкой `Production environment file is missing or unreadable`. Создайте `.env` из `.env.production.example`, заполните безопасные значения и не добавляйте файл в Git.

### Нет `vendor/autoload.php`

Выполните `composer install`. Production deploy сохраняет старый `vendor/`, поэтому при изменении lock-файла зависимости обновляются отдельным шагом.

### Storage не writable

Проверьте существование и владельца `storage/originals/`. Каталог должен быть вне `public/` и доступен на запись пользователю PHP-FPM. Не делайте весь проект world-writable.

### Браузер показывает старый CSS/JS

В production проверьте, что `deploy-price.sh` синхронизировал `_mcv26_app/public/assets/` в `public_html/new-price/assets/`. Сравните SHA-256 по инструкции deploy-документа, затем выполните hard reload браузера.

### Правая часть редактора не помещается

На обычных desktop/tablet ширинах таблица резиновая. Ниже примерно 620 px используйте горизонтальный скролл внутри рамки таблицы. Если скроллится вся страница или колонка обрезана, проверьте актуальность `public/assets/admin.css` в реально отдаваемом production assets-каталоге.

### XLSX отклонён

Проверьте расширение, размер до 10 МиБ, один непустой лист, A1, точные заголовки A2:D2, наличие раздела перед услугами, заполнение всех A:D, отсутствие формул и данных после D. Детали — в [docs/PRICE_IMPORT.md](docs/PRICE_IMPORT.md). Admin UI намеренно показывает безопасное общее сообщение; точное исключение записывается в server error log только для неожиданных ошибок.

## Ограничения документации

Репозиторий не содержит production `.env`, секретов, дампа БД, конфигурации backup, содержимого hosting wrappers и самого `~/deploy-price.sh` как исполняемого файла (его текущий текст сохранён только в deploy-документе). Старый `docs/mcv26_price_hosting_documentation.docx` описывает предшествующую схему обновления непосредственно в `_mcv26_app`; для текущего routine deploy приоритет имеет более новая Markdown-памятка `docs/mcv26_price_deploy.md`. Фактические server-side файлы и версии всё равно следует сверять на сервере. Реальные пароли, hashes, токены и ключи в эту документацию не включены.

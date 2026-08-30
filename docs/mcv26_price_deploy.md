# Деплой проекта MCV26 Price

Общее описание приложения, локальный запуск и архитектура находятся в
корневом [`README.md`](../README.md). Этот файл фиксирует фактическую
схему текущего production-деплоя на Timeweb и заменяет старую инструкцию
обновления рабочей копии непосредственно в `_mcv26_app`, сохранённую в
`mcv26_price_hosting_documentation.docx`.

## Общая схема

Рабочая схема деплоя:

**Локальный проект → GitHub (`main`) → Timeweb → production**

Проект локально находится:

``` text
/home/akdev/projects/mcv26_price_app
```

GitHub-репозиторий:

``` text
https://github.com/roslava/mcv26_price_app
```

На сервере репозиторий-копия находится:

``` text
/home/m/mcv26/repos/mcv26_price_app
```

Production-приложение:

``` text
/home/m/mcv26/public_html/_mcv26_app
```

Публичные assets, которые реально отдаёт сайт:

``` text
/home/m/mcv26/public_html/new-price/assets
```

Публичный прайс:

``` text
https://mcv26.ru/new-price/
```

Админка прайса:

``` text
https://mcv26.ru/price-admin/
```

------------------------------------------------------------------------

## Быстрый деплой

### 1. Локально проверить изменения

``` bash
cd ~/projects/mcv26_price_app
git status
```

### 2. Добавить изменения в Git

``` bash
git add .
```

### 3. Создать коммит

Например:

``` bash
git commit -m "Update price frontend"
```

### 4. Отправить изменения на GitHub

``` bash
git push
```

Если Git впервые сообщает:

``` text
fatal: The current branch main has no upstream branch.
```

выполнить один раз:

``` bash
git push --set-upstream origin main
```

После этого в дальнейшем достаточно обычного:

``` bash
git push
```

**Важно:** если `git push` не был успешно выполнен, изменений на сервере
не будет. Сервер забирает код из GitHub, а не непосредственно с
локального компьютера.

------------------------------------------------------------------------

## 5. Зайти на сервер

``` bash
ssh mcv26@mcv26.ru
```

## 6. Запустить деплой

``` bash
~/deploy-price.sh
```

В конце успешного деплоя должно быть:

``` text
DEPLOY OK
```

Готово.

------------------------------------------------------------------------

# Что делает deploy-price.sh

Скрипт расположен:

``` text
/home/m/mcv26/deploy-price.sh
```

Он выполняет четыре действия.

### 1. Получает свежий `main` из GitHub

``` bash
cd /home/m/mcv26/repos/mcv26_price_app
git pull --ff-only origin main
```

### 2. Копирует приложение в production

Из:

``` text
/home/m/mcv26/repos/mcv26_price_app
```

в:

``` text
/home/m/mcv26/public_html/_mcv26_app
```

При этом не перезаписываются:

``` text
.env
.git/
.github/
storage/
vendor/
```

Это важно: production-настройки, пользовательские данные и зависимости
остаются на сервере.

### 3. Синхронизирует публичные assets

Из:

``` text
/home/m/mcv26/public_html/_mcv26_app/public/assets/
```

в:

``` text
/home/m/mcv26/public_html/new-price/assets/
```

Это обязательный шаг.

Из-за структуры shared-хостинга PHP-код работает из `_mcv26_app`, но
браузер получает CSS, JavaScript и изображения из физической директории:

``` text
public_html/new-price/assets/
```

Поэтому одного обновления `_mcv26_app` недостаточно.

### 4. Проверяет production

Скрипт проверяет синтаксис:

``` bash
/opt/php83/bin/php -l /home/m/mcv26/public_html/_mcv26_app/public/index.php
```

и доступность:

``` text
https://mcv26.ru/new-price/
```

При успехе выводится:

``` text
DEPLOY OK
```

------------------------------------------------------------------------

## Миграции и Composer

Текущий `deploy-price.sh` не запускает SQL-миграции и не обновляет
Composer dependencies. Он сохраняет production `.env` и `vendor/`.

Если в релизе появился новый файл в `migrations/`, после доставки кода
нужно выполнить:

``` bash
/opt/php83/bin/php \
  -d auto_prepend_file=/home/m/mcv26/public_html/_mcv26_app/src/load_env.php \
  /home/m/mcv26/public_html/_mcv26_app/bin/migrate.php
```

Если изменился `composer.lock`, до включения зависящего от него кода
нужно отдельно обновить `vendor/` в production-приложении. Точный путь
к Composer 2 из hosting-документа — `/home/m/mcv26/bin/composer2`:

``` bash
cd /home/m/mcv26/public_html/_mcv26_app
/opt/php83/bin/php /home/m/mcv26/bin/composer2 \
  install --no-dev --prefer-dist --optimize-autoloader
```

Обычный deploy этого не делает; наличие пути следует проверить на
сервере перед обновлением зависимостей.

------------------------------------------------------------------------

# Полный deploy-price.sh

``` bash
#!/bin/bash
set -e

REPO="/home/m/mcv26/repos/mcv26_price_app"
APP="/home/m/mcv26/public_html/_mcv26_app"
PUBLIC_ASSETS="/home/m/mcv26/public_html/new-price/assets"

echo "== Pull latest from GitHub =="
cd "$REPO"
git pull --ff-only origin main

echo "== Deploy application =="
rsync -av \
  --exclude='.env' \
  --exclude='.git/' \
  --exclude='.github/' \
  --exclude='storage/' \
  --exclude='vendor/' \
  ./ \
  "$APP/"

echo "== Publish assets =="
rsync -av \
  "$APP/public/assets/" \
  "$PUBLIC_ASSETS/"

echo "== PHP check =="
/opt/php83/bin/php -l "$APP/public/index.php"

echo "== Production check =="
curl -fsS https://mcv26.ru/new-price/ > /dev/null

echo
echo "DEPLOY OK"
```

------------------------------------------------------------------------

# Как проверить, что сервер получил нужный коммит

Локально:

``` bash
cd ~/projects/mcv26_price_app
git log -1 --oneline
```

На сервере:

``` bash
cd ~/repos/mcv26_price_app
git log -1 --oneline
```

Хэши коммитов должны совпадать.

Например:

``` text
2d1c557 tags
```

Если локально новый коммит, а на сервере старый --- сначала проверить,
был ли выполнен успешный:

``` bash
git push
```

а затем снова:

``` bash
~/deploy-price.sh
```

------------------------------------------------------------------------

# Как проверить CSS и JS

Локально:

``` bash
sha256sum public/assets/price.css
sha256sum public/assets/price.js
```

На сервере:

``` bash
sha256sum ~/repos/mcv26_price_app/public/assets/price.css
sha256sum ~/repos/mcv26_price_app/public/assets/price.js

sha256sum ~/public_html/_mcv26_app/public/assets/price.css
sha256sum ~/public_html/_mcv26_app/public/assets/price.js

sha256sum ~/public_html/new-price/assets/price.css
sha256sum ~/public_html/new-price/assets/price.js
```

И то, что реально отдаёт сайт:

``` bash
curl -s https://mcv26.ru/new-price/assets/price.css | sha256sum
curl -s https://mcv26.ru/new-price/assets/price.js | sha256sum
```

Для каждого файла SHA256 должен совпадать на всех этапах.

------------------------------------------------------------------------

# Что нельзя перезаписывать при деплое

Не трогать production:

``` text
.env
storage/
vendor/
БД
WordPress
wp-config.php
public_html/.htaccess
production wrappers в public_html/new-price/ и public_html/price-admin/
```

Не использовать `rsync --delete` для production.

------------------------------------------------------------------------

# Важные особенности

## PHP

На сервере для приложения используется PHP 8.3:

``` text
/opt/php83/bin/php
```

Не ориентироваться на системный CLI PHP по умолчанию.

## Production base paths

Production:

``` text
MCV26_PUBLIC_BASE_PATH=/new-price/
MCV26_ADMIN_BASE_PATH=/price-admin/
```

Локально:

``` text
MCV26_PUBLIC_BASE_PATH=/
MCV26_ADMIN_BASE_PATH=/admin/
```

Production `.env` при деплое не копируется.

## GitHub Actions

Автоматический деплой через GitHub Actions по SSH не используется.

Причина: GitHub-hosted runner не может подключиться к SSH Timeweb на
порту 22 и получает:

``` text
ssh: connect to host mcv26.ru port 22: Connection timed out
```

Поэтому используется обратная схема: **сервер Timeweb сам выполняет
`git pull` из GitHub**.

------------------------------------------------------------------------

# Короткая памятка

Обычно достаточно этих команд.

Локально:

``` bash
cd ~/projects/mcv26_price_app
git add .
git commit -m "описание изменений"
git push
```

На сервере:

``` bash
ssh mcv26@mcv26.ru
~/deploy-price.sh
```

Успешный результат:

``` text
DEPLOY OK
```

Если изменения не появились --- первым делом сравнить:

``` bash
git log -1 --oneline
```

локально и на сервере. Если коммиты различаются, проблема находится
между локальным Git и GitHub, а не в production.

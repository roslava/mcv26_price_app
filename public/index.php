<?php

declare(strict_types=1);

use Mcv26\Price\Database\DatabaseConfig;
use Mcv26\Price\Database\DatabasePublicPriceReader;
use Mcv26\Price\Database\PdoConnectionFactory;
use Mcv26\Price\AppUrl;

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: https://storage.yandexcloud.net; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");

require dirname(__DIR__) . '/src/load_env.php';
require dirname(__DIR__) . '/vendor/autoload.php';

function public_e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function public_price(mixed $value): string
{
    $price = trim((string) $value);
    if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/D', $price, $matches)) {
        return '';
    }

    $formatted = number_format((int) $matches[1], 0, ',', "\u{00A0}");
    $fraction = $matches[2] ?? '';
    if ($fraction !== '') {
        $formatted .= ',' . $fraction;
    }
    return $formatted . "\u{00A0}₽";
}

function public_price_date(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if ($date === false || $date->format('Y-m-d') !== $value) {
        return null;
    }

    return $date->format('d.m.Y');
}

try {
    $priceData = (new DatabasePublicPriceReader(
        PdoConnectionFactory::create(DatabaseConfig::fromEnvironment())
    ))->read();
} catch (Throwable $exception) {
    error_log('Public price read failed: ' . $exception->getMessage());
    http_response_code(503);
    ?>
    <!doctype html>
    <html lang="ru">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>Прайс-лист временно недоступен — MCV26</title>
        <link rel="stylesheet" href="<?= public_e(AppUrl::assetPath('price.css')) ?>">
    </head>
    <body>
    <main class="error-shell">
        <p class="eyebrow">MCV26</p>
        <h1>Прайс-лист временно недоступен.</h1>
        <p>Пожалуйста, попробуйте открыть страницу позднее.</p>
    </main>
    </body>
    </html>
    <?php
    exit;
}

$sections = $priceData['sections'] ?? [];
$priceDate = public_price_date($priceData['source']['price_date'] ?? null);
$serviceCount = 0;
foreach ($sections as $section) {
    $serviceCount += count($section['items']);
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Актуальный прайс-лист медицинского центра MCV26: услуги, коды и цены.">
    <meta name="robots" content="index,follow">
    <title>Прайс-лист — MCV26</title>
    <link rel="stylesheet" href="<?= public_e(AppUrl::assetPath('price.css')) ?>">
    <script src="<?= public_e(AppUrl::assetPath('price.js')) ?>" defer></script>
</head>
<body>
<div class="public-hero-area">
<header class="public-header">
    <div class="container header-inner">
        <a class="brand" href="<?= public_e(AppUrl::publicPath('/')) ?>">
            <img class="public-logo" src="<?= public_e(AppUrl::assetPath('mcv26_logo_h.png')) ?>" alt="Медицинский Центр Власова">
        </a>
        <?php if ($priceDate !== null): ?>
            <p class="header-date">Прайс от <time datetime="<?= public_e($priceData['source']['price_date']) ?>"><?= public_e($priceDate) ?></time></p>
        <?php endif; ?>
    </div>
</header>

    <section class="hero">
        <div class="container">
            <h1>Прайс-лист</h1>
            <p class="intro">Цены носят информационный характер и могут обновляться.</p>
            <?php if ($priceData !== null): ?>
                <p class="source-title"><?= public_e($priceData['source']['title']) ?></p>
            <?php endif; ?>
        </div>
    </section>
</div>

<main>
    <?php if ($priceData === null): ?>
        <div class="container price-content">
            <p class="empty-price-message" role="status">Прайс-лист пока не опубликован.</p>
        </div>
    <?php else: ?>
    <div id="price-tools" class="container price-content">
        <div class="price-layout">
            <nav class="section-nav" aria-label="Разделы прайс-листа">
                <h2>Разделы</h2>
                <ul>
                    <?php foreach ($sections as $index => $section): ?>
                        <li data-nav-section="<?= $index + 1 ?>">
                            <a href="#section-<?= $index + 1 ?>"><?= public_e($section['name']) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>

            <div class="price-main">
                <section class="search-panel" aria-labelledby="search-heading">
                    <h2 id="search-heading" class="visually-hidden">Поиск по прайс-листу</h2>
                    <label for="price-search">Найти услугу</label>
                    <input id="price-search" type="search" placeholder="Название услуги или код" autocomplete="off">
                    <p id="search-status" class="search-status" aria-live="polite">Показано услуг: <?= public_e($serviceCount) ?></p>
                    <a class="back-to-tools" href="#" aria-label="В начало страницы">В начало</a>
                    <p id="no-results" class="no-results" hidden>Ничего не найдено</p>
                </section>

                <div id="price-sections" data-total-services="<?= public_e($serviceCount) ?>">
            <?php foreach ($sections as $index => $section): ?>
                <section
                    id="section-<?= $index + 1 ?>"
                    class="price-section"
                    data-price-section="<?= $index + 1 ?>"
                >
                    <h2><?= public_e($section['name']) ?></h2>
                    <div class="service-columns" aria-hidden="true">
                        <span>Код</span>
                        <span>Наименование услуги</span>
                        <span>Цена</span>
                    </div>
                    <ul class="service-list">
                        <?php foreach ($section['items'] as $item): ?>
                            <li class="service-row" data-service>
                                <span class="service-code"><?= public_e($item['code']) ?></span>
                                <span class="service-name"><?= public_e($item['name']) ?></span>
                                <span class="service-price"><?= public_e(public_price($item['price'])) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>

<footer class="public-footer">
    <div class="container public-footer-inner">
        <p>Информация о ценах может быть обновлена.</p>
        <a class="admin-service-link" href="<?= public_e(AppUrl::adminPath('/')) ?>" title="Администрирование прайса" aria-label="Администрирование прайса">
            <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true" focusable="false"><path d="M14.5 3a6.5 6.5 0 0 0-5.9 9.23L2 18.83V22h3.17l1.5-1.5H9v-2.33l1.77-1.77A6.5 6.5 0 1 0 14.5 3Zm0 3a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Z" fill="currentColor"/></svg>
        </a>
    </div>
</footer>
</body>
</html>

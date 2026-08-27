<?php

declare(strict_types=1);

use Mcv26\Price\Exception\ImportException;
use Mcv26\Price\Exception\StructureException;
use Mcv26\Price\PriceImporter;
use Mcv26\Price\PriceRepository;
use Mcv26\Price\PriceStatusReader;
use Mcv26\Price\UploadValidator;

require dirname(__DIR__, 2) . '/src/admin_bootstrap.php';

if (!$adminSession->isAuthenticated()) {
    admin_redirect('/admin/login.php');
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $flash = null;

    if (!$adminSession->validateCsrf($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'error', 'message' => 'Сессия формы устарела. Обновите страницу и попробуйте снова.'];
    } else {
        $upload = $_FILES['price_file'] ?? null;
        if (!is_array($upload) || !isset($upload['error']) || !is_int($upload['error'])) {
            $flash = ['type' => 'error', 'message' => 'Файл не загружен.'];
        } elseif ($upload['error'] !== UPLOAD_ERR_OK) {
            $message = match ($upload['error']) {
                UPLOAD_ERR_NO_FILE => 'Файл не загружен.',
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Размер файла превышает допустимый.',
                UPLOAD_ERR_PARTIAL => 'Файл загружен не полностью.',
                default => 'Не удалось загрузить файл.',
            };
            $flash = ['type' => 'error', 'message' => $message];
        } elseif (!is_string($upload['tmp_name'] ?? null)
            || !is_uploaded_file($upload['tmp_name'])
            || !is_string($upload['name'] ?? null)
        ) {
            $flash = ['type' => 'error', 'message' => 'Загруженный файл не прошёл проверку.'];
        } elseif (!is_int($upload['size'] ?? null) || $upload['size'] > UploadValidator::DEFAULT_MAX_BYTES) {
            $flash = ['type' => 'error', 'message' => 'Размер файла превышает допустимые 10 МБ.'];
        } else {
            try {
                $validator = new UploadValidator();
                $importer = new PriceImporter($validator);
                $repository = new PriceRepository($storageDirectory);
                $data = $repository->importAndPublish(
                    $upload['tmp_name'],
                    $validator,
                    $importer,
                    $upload['name']
                );

                $flash = [
                    'type' => 'success',
                    'message' => 'Прайс-лист успешно обновлён.',
                    'sections' => $data['stats']['sections'],
                    'items' => $data['stats']['items'],
                    'warnings' => $data['warnings'],
                    'imported_at' => $data['imported_at'],
                    'price_date' => $data['source']['price_date'],
                ];
            } catch (StructureException $exception) {
                $flash = [
                    'type' => 'error',
                    'message' => 'Структура прайс-листа не соответствует ожидаемой. ' . $exception->getMessage(),
                ];
            } catch (ImportException $exception) {
                if (str_contains($exception->getMessage(), 'расширением .xlsx')) {
                    $flash = ['type' => 'error', 'message' => 'Допускаются только файлы XLSX.'];
                } else {
                    error_log('Price import failed: ' . $exception->getMessage());
                    $flash = ['type' => 'error', 'message' => 'Не удалось импортировать прайс-лист. Проверьте файл.'];
                }
            } catch (Throwable $exception) {
                error_log('Unexpected price import failure: ' . $exception->getMessage());
                $flash = ['type' => 'error', 'message' => 'Не удалось импортировать прайс-лист.'];
            }
        }
    }

    $adminSession->setFlash($flash ?? ['type' => 'error', 'message' => 'Не удалось обработать запрос.']);
    admin_redirect('/admin/');
}

$flash = $adminSession->pullFlash();
$statusError = null;
try {
    $priceStatus = (new PriceStatusReader($storageDirectory . '/data/price.json'))->read();
} catch (Throwable $exception) {
    error_log('Price status read failed: ' . $exception->getMessage());
    $priceStatus = ['published' => false];
    $statusError = 'Не удалось прочитать сведения о текущем прайс-листе.';
}

admin_page_start('Обновление прайс-листа');
?>
<div class="toolbar">
    <div>
        <h1>Обновление прайс-листа</h1>
        <p class="muted">Загрузите актуальный файл в формате XLSX.</p>
    </div>
    <form method="post" action="/admin/logout.php">
        <input type="hidden" name="csrf_token" value="<?= admin_e($adminSession->csrfToken()) ?>">
        <button type="submit" class="button-secondary">Выйти</button>
    </form>
</div>

<?php if ($flash !== null): ?>
    <section class="notice <?= ($flash['type'] ?? '') === 'success' ? 'success' : 'error' ?>" role="status">
        <strong><?= admin_e($flash['message'] ?? '') ?></strong>
        <?php if (($flash['type'] ?? '') === 'success'): ?>
            <dl class="result-grid">
                <div><dt>Разделов</dt><dd><?= admin_e($flash['sections'] ?? '') ?></dd></div>
                <div><dt>Услуг</dt><dd><?= admin_e($flash['items'] ?? '') ?></dd></div>
                <div><dt>Предупреждений</dt><dd><?= count(is_array($flash['warnings'] ?? null) ? $flash['warnings'] : []) ?></dd></div>
                <div><dt>Импортирован</dt><dd><?= admin_e($flash['imported_at'] ?? '') ?></dd></div>
                <?php if (($flash['price_date'] ?? null) !== null): ?>
                    <div><dt>Дата прайса</dt><dd><?= admin_e($flash['price_date']) ?></dd></div>
                <?php endif; ?>
            </dl>
            <?php if (is_array($flash['warnings'] ?? null) && $flash['warnings'] !== []): ?>
                <ul class="warning-list">
                    <?php foreach ($flash['warnings'] as $warning): ?>
                        <li><?= admin_e($warning['message'] ?? '') ?>, строка <?= admin_e($warning['row'] ?? '') ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="card">
    <h2>Текущий прайс-лист</h2>
    <?php if ($statusError !== null): ?>
        <div class="notice error" role="alert"><?= admin_e($statusError) ?></div>
    <?php elseif (($priceStatus['published'] ?? false) !== true): ?>
        <p>Прайс-лист пока не опубликован.</p>
    <?php else: ?>
        <p class="source-title"><?= admin_e($priceStatus['title']) ?></p>
        <dl class="status-grid">
            <div><dt>Импортирован</dt><dd><?= admin_e($priceStatus['imported_at']) ?></dd></div>
            <?php if ($priceStatus['price_date'] !== null): ?>
                <div><dt>Дата прайса</dt><dd><?= admin_e($priceStatus['price_date']) ?></dd></div>
            <?php endif; ?>
            <div><dt>Разделов</dt><dd><?= admin_e($priceStatus['sections']) ?></dd></div>
            <div><dt>Услуг</dt><dd><?= admin_e($priceStatus['items']) ?></dd></div>
        </dl>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Загрузить новый файл</h2>
    <form method="post" action="/admin/" enctype="multipart/form-data" class="form-stack">
        <input type="hidden" name="csrf_token" value="<?= admin_e($adminSession->csrfToken()) ?>">
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= UploadValidator::DEFAULT_MAX_BYTES ?>">
        <label>
            <span>Файл прайс-листа</span>
            <input type="file" name="price_file" accept=".xlsx" required>
        </label>
        <p class="hint">Только XLSX, не более 10 МБ.</p>
        <button type="submit">Импортировать прайс-лист</button>
    </form>
</section>
<?php
admin_page_end();

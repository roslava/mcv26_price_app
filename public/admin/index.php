<?php
declare(strict_types=1);
use Mcv26\Price\Admin\AdminUploadImporter;
use Mcv26\Price\Database\DatabaseConfig;
use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\Database\DatabasePublicPriceReader;
use Mcv26\Price\Database\PdoConnectionFactory;
use Mcv26\Price\Exception\ImportException;
use Mcv26\Price\Exception\StructureException;
use Mcv26\Price\UploadValidator;
require dirname(__DIR__, 2) . '/src/admin_bootstrap.php';
if (!$adminSession->isAuthenticated()) admin_redirect('/admin/login.php');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) { http_response_code(405); header('Allow: GET, POST'); exit; }
$isAjaxUpload = $method === 'POST' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
if ($method === 'POST') {
    $flash = null;
    if (!$adminSession->validateCsrf($_POST['csrf_token'] ?? null)) $flash = ['type' => 'error', 'message' => 'Сессия формы устарела. Обновите страницу и попробуйте снова.'];
    else {
        $upload = $_FILES['price_file'] ?? null;
        if (!is_array($upload) || !isset($upload['error']) || !is_int($upload['error'])) $flash = ['type' => 'error', 'message' => 'Файл не загружен.'];
        elseif ($upload['error'] !== UPLOAD_ERR_OK) $flash = ['type' => 'error', 'message' => match ($upload['error']) { UPLOAD_ERR_NO_FILE => 'Файл не загружен.', UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Размер файла превышает допустимый.', UPLOAD_ERR_PARTIAL => 'Файл загружен не полностью.', default => 'Не удалось загрузить файл.' }];
        elseif (!is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name']) || !is_string($upload['name'] ?? null)) $flash = ['type' => 'error', 'message' => 'Загруженный файл не прошёл проверку.'];
        elseif (!is_int($upload['size'] ?? null) || $upload['size'] > UploadValidator::DEFAULT_MAX_BYTES) $flash = ['type' => 'error', 'message' => 'Размер файла превышает допустимые 10 МБ.'];
        else {
            try {
                $pdo = PdoConnectionFactory::create(DatabaseConfig::fromEnvironment());
                $repository = new DatabasePriceRepository($pdo);
                $data = (new AdminUploadImporter($pdo, $storageDirectory, $projectRoot . '/public'))->import($upload['tmp_name'], $upload['name']);
                $stored = $repository->loadVersion($data['version_id']);
                $message = match ($data['outcome']) {
                    'existing_draft' => 'Этот прайс уже загружен и готов к проверке.',
                    'unchanged_published' => 'В загруженном файле нет изменений по сравнению с текущим прайсом.',
                    default => 'Файл проверен. Новый прайс готов к публикации.',
                };
                $flash = ['type' => 'success', 'message' => $message, 'outcome' => $data['outcome'], 'version_id' => $data['version_id'], 'sections' => $data['categories'], 'items' => $data['services'], 'price_date' => $stored['price_date'] ?? null];
            } catch (StructureException) { $flash = ['type' => 'error', 'message' => 'Структура файла отличается от ожидаемой. Проверьте заголовки и заполнение строк.'];
            } catch (ImportException) { $flash = ['type' => 'error', 'message' => 'Не удалось прочитать файл. Проверьте, что это Excel-файл прайс-листа в формате XLSX.'];
            } catch (Throwable $exception) { error_log('Admin price upload failed: ' . $exception->getMessage()); $flash = ['type' => 'error', 'message' => 'Не удалось обработать прайс. Текущий прайс на сайте не изменён. Попробуйте ещё раз или обратитесь к разработчику.']; }
        }
    }
    $flash ??= ['type' => 'error', 'message' => 'Не удалось обработать запрос.'];
    $flash['context'] = 'upload';
    if ($isAjaxUpload) {
        header('Content-Type: application/json; charset=UTF-8');
        if (($flash['type'] ?? null) !== 'success') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => $flash['message']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $publishedId = $repository->publishedVersionId();
        $current = $publishedId === null ? null : $repository->loadVersion($publishedId);
        $currentItems = is_array($current) ? array_sum(array_map(static fn (array $category): int => count($category['services']), $current['categories'])) : 0;
        $reviewData = ($flash['outcome'] ?? null) === 'unchanged_published' ? null : [
            'version_id' => (int) $flash['version_id'],
            'revision' => (int) ($stored['revision'] ?? 0),
            'original_filename' => (string) ($stored['original_filename'] ?? ''),
            'price_date' => $stored['price_date'] ?? null,
            'sections' => (int) $flash['sections'],
            'items' => (int) $flash['items'],
            'expected_published_version_id' => $publishedId,
            'current_price_date' => $current['price_date'] ?? null,
            'current_items' => $currentItems,
        ];
        echo json_encode([
            'ok' => true,
            'message' => $flash['message'],
            'status_message' => 'Файл проверен, ошибок не найдено.',
            'review' => $reviewData,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $adminSession->setFlash($flash); admin_redirect('/admin/');
}
$flash = $adminSession->pullFlash(); $repository = null; $versions = []; $publishedVersionId = null; $statusError = null;
try { $repository = new DatabasePriceRepository(PdoConnectionFactory::create(DatabaseConfig::fromEnvironment())); $versions = $repository->listVersions(); $publishedVersionId = $repository->publishedVersionId(); }
catch (Throwable $exception) { error_log('Price version list failed: ' . $exception->getMessage()); $statusError = 'Не удалось загрузить текущий прайс.'; }
$editableVersion = null;
foreach ($versions as $version) { if (($version['status'] ?? null) === 'draft') { $editableVersion = $version; break; } }
$priceStatus = null;
try { $publishedData = (new DatabasePublicPriceReader(PdoConnectionFactory::create(DatabaseConfig::fromEnvironment())))->read(); if ($publishedData !== null) $priceStatus = ['title' => $publishedData['source']['title'], 'price_date' => $publishedData['source']['price_date'], 'sections' => $publishedData['stats']['sections'], 'items' => $publishedData['stats']['items']]; }
catch (Throwable $exception) { error_log('Published DB price status read failed: ' . $exception->getMessage()); $statusError = $statusError ?? 'Не удалось загрузить текущий прайс.'; }
$review = null;
if (is_array($flash) && ($flash['type'] ?? null) === 'success' && ($flash['outcome'] ?? null) !== 'unchanged_published' && $repository instanceof DatabasePriceRepository) {
    try {
        $newVersion = $repository->loadVersion((int) $flash['version_id']);
        $currentVersion = $publishedVersionId === null ? null : $repository->loadVersion($publishedVersionId);
        if (is_array($newVersion)) {
            $review = ['version' => $newVersion, 'current' => $currentVersion];
            if (($newVersion['status'] ?? null) === 'draft') $editableVersion = $newVersion;
        }
    } catch (Throwable $exception) { error_log('Price review load failed: ' . $exception->getMessage()); }
}
function admin_human_date(mixed $value): string { if (!is_string($value)) return 'не указана'; $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value); if (!$date || $date->format('Y-m-d') !== $value) return 'не указана'; $months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря']; return $date->format('j') . ' ' . $months[(int) $date->format('n') - 1] . ' ' . $date->format('Y'); }
$uploadExpanded = is_array($flash)
    && ($flash['type'] ?? null) === 'error'
    && ($flash['context'] ?? null) === 'upload';
admin_page_start('Прайс-лист', '', $adminSession->csrfToken()); ?>
<?php if (is_array($flash) && ($flash['type'] ?? null) === 'error'): ?><div class="notice error" role="alert"><?= admin_e($flash['message'] ?? 'Не удалось обработать запрос.') ?></div><?php endif; ?>
<?php if (is_array($flash) && ($flash['type'] ?? null) === 'success' && in_array($flash['outcome'] ?? null, ['existing_draft', 'unchanged_published'], true)): ?><div class="notice success" role="status"><?= admin_e($flash['message'] ?? '') ?></div><?php endif; ?>
<div class="admin-main-layout" data-version-actions data-csrf-token="<?= admin_e($adminSession->csrfToken()) ?>" data-published-version-id="<?= admin_e($publishedVersionId ?? '') ?>">
    <main class="primary-actions">
        <section class="card primary-action-card upload-accordion" data-primary-edit data-edit-accordion>
            <h2 class="upload-accordion-heading"><button class="upload-accordion-toggle" type="button" aria-expanded="false" aria-controls="edit-accordion-content" data-edit-accordion-toggle><span>Редактировать уже имеющийся прайс</span><span class="upload-accordion-chevron" aria-hidden="true"></span></button></h2>
            <div id="edit-accordion-content" class="upload-accordion-content" data-edit-accordion-content hidden>
            <p>Изменить услуги или цены вручную.</p>
            <?php if (is_array($editableVersion)): ?><a class="button-link" href="/admin/draft.php?id=<?= admin_e($editableVersion['id']) ?>">Продолжить редактирование</a><?php elseif ($publishedVersionId !== null): ?><form method="post" action="/admin/edit-current.php"><input type="hidden" name="csrf_token" value="<?= admin_e($adminSession->csrfToken()) ?>"><button type="submit">Редактировать прайс</button></form><?php else: ?><p>Прайс-лист ещё не загружен.</p><?php endif; ?>
            </div>
        </section>
        <section class="card primary-action-card upload-accordion" data-primary-upload data-upload-accordion>
            <h2 class="upload-accordion-heading"><button class="upload-accordion-toggle" type="button" aria-expanded="<?= $uploadExpanded ? 'true' : 'false' ?>" aria-controls="upload-accordion-content" data-upload-accordion-toggle><span>Загрузить новый прайс</span><span class="upload-accordion-chevron" aria-hidden="true"></span></button></h2>
            <div id="upload-accordion-content" class="upload-accordion-content" data-upload-accordion-content<?= $uploadExpanded ? '' : ' hidden' ?>>
            <p>Загрузить новый Excel-файл с прайс-листом.</p>
            <form method="post" action="/admin/" enctype="multipart/form-data" class="form-stack" data-upload-form><input type="hidden" name="csrf_token" value="<?= admin_e($adminSession->csrfToken()) ?>"><input type="hidden" name="MAX_FILE_SIZE" value="<?= UploadValidator::DEFAULT_MAX_BYTES ?>"><div class="file-picker"><input class="visually-hidden-file" id="price-file" type="file" name="price_file" accept=".xlsx" required data-file-input><label class="button-link file-picker-button" for="price-file">Выбрать Excel-файл</label><span class="file-picker-name" data-file-name aria-live="polite">Файл не выбран</span></div><p class="upload-check-status" data-upload-status role="status" aria-live="polite" hidden><span class="upload-spinner" data-upload-spinner aria-hidden="true"></span><span data-upload-status-text></span></p></form>
            <div data-upload-result><?php if ($review !== null): $v = $review['version']; $current = $review['current']; $newServiceCount = array_sum(array_map(static fn (array $x): int => count($x['services']), $v['categories'])); $currentServiceCount = is_array($current) ? array_sum(array_map(static fn (array $x): int => count($x['services']), $current['categories'])) : 0; ?><div class="upload-review"><h3>Новый прайс готов к публикации</h3><div class="notice success"><strong>Прайс можно опубликовать</strong><br>Структура файла корректна. Ошибок не найдено.</div><dl class="status-grid"><div><dt>Файл</dt><dd><?= admin_e($v['original_filename']) ?></dd></div><div><dt>Дата прайса</dt><dd><?= admin_e(admin_human_date($v['price_date'])) ?></dd></div><div><dt>Разделов</dt><dd><?= count($v['categories']) ?></dd></div><div><dt>Услуг в новом прайсе</dt><dd><?= $newServiceCount ?></dd></div><div><dt>Сейчас на сайте</dt><dd><?= $currentServiceCount ?> услуг</dd></div></dl><p class="reassurance"><?php if (is_array($current)): ?>Прайс ещё не опубликован. На сайте продолжает действовать прайс от <?= admin_e(admin_human_date($current['price_date'] ?? null)) ?>.<?php else: ?>Прайс ещё не опубликован. Сейчас на сайте нет прайс-листа.<?php endif; ?></p><div class="review-actions"><button type="button" data-review-publish data-version-id="<?= admin_e($v['id']) ?>" data-revision="<?= admin_e($v['revision']) ?>" data-published-version-id="<?= admin_e($publishedVersionId ?? '') ?>">Опубликовать загруженный прайс на сайте</button><a class="button-link button-secondary" href="/admin/">Отменить и вернуться</a></div></div><?php endif; ?></div>
            </div>
        </section>
    </main>
    <aside class="secondary-sidebar" aria-labelledby="additional-title">
        <h2 id="additional-title">Дополнительно</h2>
        <details><summary>Текущий прайс</summary><div class="details-content"><?php if ($priceStatus === null): ?><p><?= admin_e($statusError ?? 'Прайс-лист ещё не загружен.') ?></p><?php else: ?><dl class="sidebar-stats"><div><dt>Дата прайса</dt><dd><?= admin_e(admin_human_date($priceStatus['price_date'])) ?></dd></div><div><dt>Разделов</dt><dd><?= admin_e($priceStatus['sections']) ?></dd></div><div><dt>Услуг</dt><dd><?= admin_e($priceStatus['items']) ?></dd></div></dl><a class="button-link button-secondary" href="/" target="_blank" rel="noopener">Посмотреть прайс на сайте</a><?php endif; ?></div></details>
        <details id="history"><summary>История прайс-листов</summary><div class="details-content"><p class="version-message" data-version-message role="status" aria-live="polite"></p><?php if ($versions === []): ?><p>История пока пуста.</p><?php else: ?><div class="version-list"><?php foreach ($versions as $version): ?><article class="version-row"><div><strong><?= admin_e($version['title']) ?></strong><span class="muted"><?= $version['status'] === 'published' ? 'Сейчас на сайте' : ($version['status'] === 'archived' ? 'Предыдущий прайс' : 'Новый прайс на проверке') ?></span></div><div class="version-actions"><?php if ($version['status'] === 'draft'): ?><a class="button-link button-secondary" href="/admin/draft.php?id=<?= admin_e($version['id']) ?>">Продолжить редактирование</a><button type="button" data-version-action="publish" data-version-id="<?= admin_e($version['id']) ?>" data-revision="<?= admin_e($version['revision']) ?>">Опубликовать</button><?php elseif ($version['status'] === 'archived'): ?><button type="button" class="button-secondary" data-version-action="restore" data-version-id="<?= admin_e($version['id']) ?>">Восстановить</button><?php else: ?><span class="status-published">Сейчас на сайте</span><?php endif; ?></div></article><?php endforeach; ?></div><?php endif; ?></div></details>
        <details><summary>Скачать Excel</summary><div class="details-content"><?php if ($versions === []): ?><p>Пока нечего скачивать.</p><?php else: ?><div class="download-list"><?php foreach ($versions as $version): ?><a class="button-link button-secondary" href="/admin/export-version.php?id=<?= admin_e($version['id']) ?>"><?= admin_e($version['title']) ?></a><?php endforeach; ?></div><?php endif; ?></div></details>
    </aside>
</div>
<dialog class="publish-dialog" data-upload-publish-dialog role="dialog" aria-modal="true" aria-labelledby="upload-publish-dialog-title">
    <h2 id="upload-publish-dialog-title">Опубликовать новый прайс?</h2>
    <p>Загруженный прайс станет доступен на публичной странице сайта.</p>
    <p class="publish-dialog-message" data-upload-publish-message role="alert" hidden></p>
    <div class="publish-dialog-actions">
        <button type="button" class="button-secondary" data-upload-publish-cancel autofocus>Отмена</button>
        <button type="button" data-upload-publish-confirm>Опубликовать</button>
    </div>
</dialog>
<?php echo '<script src="/assets/admin-versions.js" defer></script>'; admin_page_end();

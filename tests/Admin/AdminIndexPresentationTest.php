<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests\Admin;

use PHPUnit\Framework\TestCase;

final class AdminIndexPresentationTest extends TestCase
{
    public function testTwoPrimaryActionsAreVisibleAndEditReusesExistingEditablePrice(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/index.php');
        $primary = $this->between($source, '<main class="primary-actions">', '</main>');

        self::assertStringContainsString('Редактировать уже имеющийся прайс', $primary);
        self::assertStringContainsString('Изменить услуги или цены вручную.', $primary);
        self::assertStringContainsString('Продолжить редактирование', $primary);
        self::assertStringContainsString("admin_url('draft.php')", $primary);
        self::assertStringContainsString("admin_url('edit-current.php')", $primary);
        self::assertStringContainsString('<button type="submit">Редактировать прайс</button>', $primary);
        self::assertStringContainsString('name="csrf_token"', $primary);
        self::assertStringContainsString('Загрузить новый прайс', $primary);
        self::assertStringContainsString('Загрузить новый Excel-файл с прайс-листом.', $primary);
        self::assertStringNotContainsString('>Проверить файл</button>', $primary);
        self::assertStringContainsString("=== 'draft'", $source);
    }

    public function testUploadedEditablePriceRemainsThePrimaryEditTargetWithoutCreatingAnother(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/index.php');
        $selection = $this->between($source, '$review = null;', "function admin_human_date");
        $editCard = $this->between($source, '<section class="card primary-action-card upload-accordion" data-primary-edit', '</section>');

        self::assertStringContainsString("$" . "repository->loadVersion((int) $" . "flash['version_id'])", $selection);
        self::assertStringContainsString("($" . "newVersion['status'] ?? null) === 'draft'", $selection);
        self::assertStringContainsString('$editableVersion = $newVersion;', $selection);
        self::assertStringContainsString('Продолжить редактирование', $editCard);
        self::assertStringContainsString("admin_url('draft.php')) ?>?id=<?= admin_e($" . "editableVersion['id']) ?>", $editCard);
        self::assertStringNotContainsString('createVersion(', $selection);
        self::assertStringNotContainsString('restore-version.php', $selection);
    }

    public function testEditExistingPriceIsACollapsedAccessibleAccordion(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/index.php');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin-versions.js');
        $editCard = $this->between($source, '<section class="card primary-action-card upload-accordion" data-primary-edit', '</section>');

        self::assertStringContainsString('aria-expanded="false"', $editCard);
        self::assertStringContainsString('aria-controls="edit-accordion-content"', $editCard);
        self::assertStringContainsString('id="edit-accordion-content"', $editCard);
        self::assertStringContainsString('data-edit-accordion-content hidden', $editCard);
        self::assertStringContainsString("editToggle.setAttribute('aria-expanded', String(expanded))", $script);
        self::assertStringContainsString('editContent.hidden = !expanded;', $script);
    }

    public function testPrimaryAreaOmitsCurrentMetadataAndDoesNotCreateOrPublishOnEdit(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/index.php');
        $primary = $this->between($source, '<main class="primary-actions">', '</main>');
        $editCard = $this->between($primary, '<section class="card primary-action-card upload-accordion" data-primary-edit', '</section>');

        self::assertStringNotContainsString('Сейчас на сайте</h2>', $editCard);
        self::assertStringNotContainsString('<dt>', $editCard);
        self::assertStringNotContainsString('Посмотреть прайс на сайте', $primary);
        self::assertStringNotContainsString('restore-version.php', $primary);
        self::assertStringNotContainsString('publish-version.php', $primary);
        self::assertStringNotContainsString('<ol class="steps">', $primary);
        self::assertStringContainsString('data-review-publish', $source);
        self::assertStringContainsString('data-version-action="publish"', $source);
        self::assertStringNotContainsString('На сайте ничего не изменится, пока вы не нажмёте «Опубликовать».', $primary);
    }

    public function testSecondaryInformationIsInCollapsedNativeDisclosures(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/index.php');
        $sidebar = $this->between($source, '<aside class="secondary-sidebar"', '</aside>');

        self::assertStringContainsString('Дополнительно', $sidebar);
        self::assertSame(3, substr_count($sidebar, '<details'));
        self::assertStringNotContainsString('<details open', $sidebar);
        self::assertStringContainsString('<summary>Текущий прайс</summary>', $sidebar);
        self::assertStringContainsString('<summary>История прайс-листов</summary>', $sidebar);
        self::assertStringContainsString('<summary>Скачать Excel</summary>', $sidebar);
        self::assertStringContainsString('Посмотреть прайс на сайте', $sidebar);
        self::assertStringContainsString('Продолжить редактирование', $sidebar);
        self::assertStringContainsString("admin_url('export-version.php')) ?>?id=", $sidebar);
        self::assertStringContainsString('data-version-action="restore"', $sidebar);
    }

    public function testReviewUsesNeutralFactsInsteadOfPositionalIdentityClaims(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/index.php');
        self::assertStringContainsString('Новый прайс готов к публикации', $source);
        self::assertStringContainsString('Услуг в новом прайсе', $source);
        self::assertStringContainsString('Сейчас на сайте', $source);
        self::assertStringContainsString('На сайте продолжает действовать прайс', $source);
        self::assertStringNotContainsString('PriceVersionComparison', $source);
        self::assertStringNotContainsString('цен изменено', $source);
        self::assertStringNotContainsString('услуги добавлено', $source);
        self::assertStringNotContainsString('услуг удалено', $source);
        self::assertStringNotContainsString('Рост:', $source);
        self::assertStringNotContainsString('Снижение:', $source);
    }

    public function testValidDuplicateUploadOutcomesUseNeutralClientMessages(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/index.php');

        self::assertStringContainsString('Этот прайс уже загружен и готов к проверке.', $source);
        self::assertStringContainsString('В загруженном файле нет изменений по сравнению с текущим прайсом.', $source);
        self::assertStringContainsString("'existing_draft'", $source);
        self::assertStringContainsString("'unchanged_published'", $source);
    }

    public function testUploadUsesRussianAccessibleCustomFilePicker(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/index.php');

        self::assertStringContainsString('id="price-file"', $source);
        self::assertStringContainsString('for="price-file">Выбрать Excel-файл</label>', $source);
        self::assertStringContainsString('data-file-input', $source);
        self::assertStringContainsString('data-file-name aria-live="polite">Файл не выбран', $source);
        self::assertStringContainsString('accept=".xlsx"', $source);
        self::assertStringNotContainsString('Choose File', $source);
        self::assertStringNotContainsString('No file chosen', $source);
    }

    public function testUploadIsAnAccessibleAccordionOpenedOnlyForUploadErrors(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/index.php');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin-versions.js');

        self::assertStringContainsString("$" . "flash['context'] = 'upload';", $source);
        self::assertStringContainsString("($" . "flash['type'] ?? null) === 'error'", $source);
        self::assertStringContainsString("($" . "flash['context'] ?? null) === 'upload'", $source);
        self::assertStringContainsString('aria-expanded="<?= $uploadExpanded ? \'true\' : \'false\' ?>"', $source);
        self::assertStringContainsString('aria-controls="upload-accordion-content"', $source);
        self::assertStringContainsString('id="upload-accordion-content"', $source);
        self::assertStringContainsString("$" . "uploadExpanded ? '' : ' hidden'", $source);
        self::assertStringContainsString("setAttribute('aria-expanded', String(expanded))", $script);
        self::assertStringContainsString('uploadContent.hidden = !expanded;', $script);
    }

    public function testFileSelectionAutomaticallyRunsServerCheckAndRejectsStaleResponses(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/index.php');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin-versions.js');

        self::assertStringContainsString('data-upload-form', $source);
        self::assertStringContainsString('data-upload-status', $source);
        self::assertStringContainsString('data-upload-spinner', $source);
        self::assertStringContainsString("'X-Requested-With': 'XMLHttpRequest'", $script);
        self::assertStringContainsString("fileInput.addEventListener('change', async () =>", $script);
        self::assertStringContainsString("setUploadStatus('checking', 'Проверяем файл…')", $script);
        self::assertStringContainsString('uploadController?.abort();', $script);
        self::assertStringContainsString('if (request !== uploadRequest) return;', $script);
        self::assertStringContainsString("'Файл проверен, ошибок не найдено.'", $source);
        self::assertStringContainsString('Опубликовать загруженный прайс на сайте', $source);
        self::assertStringNotContainsString('data-upload-publish-placeholder', $source);
        self::assertStringNotContainsString('На сайте ничего не изменится, пока вы не нажмёте «Опубликовать».', $source);
        self::assertStringNotContainsString('>Проверить файл</button>', $source);
    }

    public function testAjaxCheckUsesExistingUploadBackendAndReturnsPublishDataOnlyAfterSuccess(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/index.php');

        self::assertSame(1, substr_count($source, 'new AdminUploadImporter('));
        self::assertStringContainsString("$" . "isAjaxUpload = $" . "method === 'POST'", $source);
        self::assertStringContainsString("if ($" . "isAjaxUpload)", $source);
        self::assertStringContainsString("'review' => $" . "reviewData", $source);
        self::assertStringContainsString("'expected_published_version_id' => $" . "publishedId", $source);
    }

    public function testUploadedPricePublicationUsesCustomAccessibleDialog(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/index.php');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin-versions.js');

        self::assertStringContainsString('role="dialog" aria-modal="true" aria-labelledby="upload-publish-dialog-title"', $source);
        self::assertStringContainsString('<h2 id="upload-publish-dialog-title">Опубликовать новый прайс?</h2>', $source);
        self::assertStringContainsString('Загруженный прайс станет доступен на публичной странице сайта.', $source);
        self::assertStringContainsString('data-upload-publish-cancel autofocus>Отмена</button>', $source);
        self::assertStringContainsString('data-upload-publish-confirm>Опубликовать</button>', $source);
        self::assertStringNotContainsString("window.confirm('Опубликовать новый прайс на сайте?')", $script);
        self::assertStringContainsString('uploadPublishDialog.showModal();', $script);
        self::assertStringContainsString("uploadPublishConfirm.textContent = 'Публикуем…';", $script);
        self::assertStringContainsString('if (!pendingReviewButton || reviewPublishing) return;', $script);
        self::assertStringContainsString('uploadPublishMessage.hidden = false;', $script);
        self::assertStringContainsString("pendingReviewButton.textContent = 'Прайс опубликован';", $script);
        self::assertStringContainsString("setUploadStatus('success', 'Прайс опубликован.');", $script);
    }

    public function testAdminHeaderUsesLocalClientLogo(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/admin_bootstrap.php');

        self::assertStringContainsString("AppUrl::assetPath('mcv26_logo_h.png')", $source);
        self::assertStringContainsString('alt="Медицинский Центр Власова"', $source);
        self::assertStringNotContainsString('class="brand-mark"', $source);
    }

    public function testLogoutIsRenderedInTheBrandHeader(): void
    {
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/src/admin_bootstrap.php');
        $index = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/index.php');
        $header = $this->between($bootstrap, '<header class="site-header">', '</header>');

        self::assertStringContainsString('class="site-header-brand"', $header);
        self::assertStringContainsString('Управление прайс-листом', $header);
        self::assertStringContainsString('class="site-header-logout"', $header);
        self::assertStringContainsString("AppUrl::adminPath('logout.php')", $header);
        self::assertStringContainsString('>Выйти</button>', $header);
        self::assertStringContainsString("admin_page_start('Прайс-лист', '', $" . "adminSession->csrfToken())", $index);
        self::assertSame(1, substr_count($index, '>Выйти</button>') + substr_count($header, '>Выйти</button>'));
    }

    public function testBrandHeaderUsesRequestedBlueBackground(): void
    {
        $styles = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');

        self::assertMatchesRegularExpression('/\.site-header\s*\{[^}]*background:\s*#004e89;/s', $styles);
        self::assertMatchesRegularExpression('/\.site-header-brand span\s*\{[^}]*color:\s*#fff;/s', $styles);
    }

    public function testPageDoesNotRepeatPriceListHeadingBelowBrandHeader(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/index.php');

        self::assertStringNotContainsString('<h1>Прайс-лист</h1>', $source);
        self::assertStringNotContainsString('admin-main-toolbar', $source);
    }

    public function testFreshInstallShowsEmptyStateAndKeepsUploadAvailable(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/index.php');

        self::assertStringContainsString('Прайс-лист ещё не загружен.', $source);
        self::assertStringContainsString('if ($publishedData !== null)', $source);
        self::assertStringContainsString('name="price_file"', $source);
        self::assertStringContainsString('data-upload-form', $source);
    }

    private function between(string $source, string $start, string $end): string
    {
        $offset = strpos($source, $start);
        self::assertNotFalse($offset);
        $fragment = substr($source, $offset);
        $endOffset = strpos($fragment, $end);
        self::assertNotFalse($endOffset);
        return substr($fragment, 0, $endOffset + strlen($end));
    }
}

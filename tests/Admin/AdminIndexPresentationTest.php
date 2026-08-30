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
        self::assertStringContainsString('href="/admin/draft.php?id=', $primary);
        self::assertStringContainsString('action="/admin/edit-current.php"', $primary);
        self::assertStringContainsString('<button type="submit">Редактировать прайс</button>', $primary);
        self::assertStringContainsString('name="csrf_token"', $primary);
        self::assertStringContainsString('Загрузить новый прайс', $primary);
        self::assertStringContainsString('Загрузить новый Excel-файл с прайс-листом.', $primary);
        self::assertStringContainsString('Проверить файл', $primary);
        self::assertStringContainsString("=== 'draft'", $source);
    }

    public function testUploadedEditablePriceRemainsThePrimaryEditTargetWithoutCreatingAnother(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/index.php');
        $selection = $this->between($source, '$review = null;', "function admin_human_date");
        $editCard = $this->between($source, '<section class="card primary-action-card" data-primary-edit>', '</section>');

        self::assertStringContainsString("$" . "repository->loadVersion((int) $" . "flash['version_id'])", $selection);
        self::assertStringContainsString("($" . "newVersion['status'] ?? null) === 'draft'", $selection);
        self::assertStringContainsString('$editableVersion = $newVersion;', $selection);
        self::assertStringContainsString('Продолжить редактирование', $editCard);
        self::assertStringContainsString("/admin/draft.php?id=<?= admin_e($" . "editableVersion['id']) ?>", $editCard);
        self::assertStringNotContainsString('createVersion(', $selection);
        self::assertStringNotContainsString('restore-version.php', $selection);
    }

    public function testPrimaryAreaOmitsCurrentMetadataAndDoesNotCreateOrPublishOnEdit(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/index.php');
        $primary = $this->between($source, '<main class="primary-actions">', '</main>');
        $editCard = $this->between($primary, '<section class="card primary-action-card" data-primary-edit>', '</section>');

        self::assertStringNotContainsString('Сейчас на сайте</h2>', $editCard);
        self::assertStringNotContainsString('<dt>', $editCard);
        self::assertStringNotContainsString('Посмотреть прайс на сайте', $primary);
        self::assertStringNotContainsString('restore-version.php', $primary);
        self::assertStringNotContainsString('publish-version.php', $primary);
        self::assertStringNotContainsString('<ol class="steps">', $primary);
        self::assertStringContainsString('data-review-publish', $source);
        self::assertStringContainsString('data-version-action="publish"', $source);
        self::assertStringContainsString('На сайте ничего не изменится, пока вы не нажмёте «Опубликовать».', $primary);
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
        self::assertStringContainsString('/admin/export-version.php?id=', $sidebar);
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

    public function testAdminHeaderUsesLocalClientLogo(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/admin_bootstrap.php');

        self::assertStringContainsString('src="/assets/mcv26_logo_h.png"', $source);
        self::assertStringContainsString('alt="Медицинский Центр Власова"', $source);
        self::assertStringNotContainsString('class="brand-mark"', $source);
    }

    public function testFreshInstallShowsEmptyStateAndKeepsUploadAvailable(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/index.php');

        self::assertStringContainsString('Прайс-лист ещё не загружен.', $source);
        self::assertStringContainsString('if ($publishedData !== null)', $source);
        self::assertStringContainsString('name="price_file"', $source);
        self::assertStringContainsString('Проверить файл', $source);
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

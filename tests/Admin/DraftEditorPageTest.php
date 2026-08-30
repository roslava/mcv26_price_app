<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests\Admin;

use Mcv26\Price\Admin\DraftEditorPage;
use PHPUnit\Framework\TestCase;

final class DraftEditorPageTest extends TestCase
{
    public function testTableHeaderSticksToTableScrollportWithoutRowOverlap(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');
        self::assertMatchesRegularExpression(
            '/\.draft-table thead th\s*\{[^}]*position:\s*sticky;[^}]*z-index:\s*8;[^}]*top:\s*0;[^}]*background:\s*#e9f1f3;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.draft-table-wrap\s*\{[^}]*max-height:\s*calc\(100vh - 32px\);[^}]*overflow:\s*auto;/s',
            $css
        );
        self::assertStringNotContainsString('top: 118px', $css);
        self::assertMatchesRegularExpression('/\.service-edit-row:not\(\.price-invalid\):hover\s*\{[^}]*background:/s', $css);
        self::assertMatchesRegularExpression('/\.service-edit-row:not\(\.price-invalid\):focus-within\s*\{[^}]*background:/s', $css);
        self::assertStringContainsString('.service-edit-row { transition: none; }', $css);
    }

    public function testFormatsMoneyFromIntegerMinorUnits(): void
    {
        self::assertSame("370\u{00A0}₽", DraftEditorPage::money(37000));
        self::assertSame("370,50\u{00A0}₽", DraftEditorPage::money(37050));
        self::assertSame('370', DraftEditorPage::decimal(37000));
        self::assertSame('370,50', DraftEditorPage::decimal(37050));
    }

    public function testPublishActionUsesSavedRevisionAndExistingPublicationEndpoint(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin-draft.js');

        self::assertStringContainsString("fetch('/admin/publish-version.php'", $script);
        self::assertStringContainsString('expected_revision: editor.dataset.revision', $script);
        self::assertStringContainsString('expected_published_version_id: editor.dataset.publishedVersionId || null', $script);
        self::assertStringContainsString('publishButton.disabled = unsaved > 0 || invalid > 0 || isSaving;', $script);
        self::assertStringContainsString('saveButton.hidden = unsaved === 0 && invalid === 0;', $script);
        self::assertStringContainsString('downloadButton.hidden = unsaved > 0 || invalid > 0 || isSaving;', $script);
        self::assertStringContainsString('resetButton.hidden = (unsaved === 0 && invalid === 0) || isSaving;', $script);
        self::assertStringContainsString("window.location.assign('/')", $script);
        self::assertStringNotContainsString('window.confirm(', $script);
        self::assertStringContainsString("publishDialog.showModal();", $script);
        self::assertStringContainsString("publishConfirmButton.textContent = 'Публикуем…';", $script);
        self::assertStringContainsString('if (isPublishing) return;', $script);
        self::assertStringContainsString("const isCurrentPublishedClone = editor.dataset.currentPublishedClone === 'true';", $script);
        self::assertStringContainsString('const alreadyPublished = isCurrentPublishedClone && changed === 0 && unsaved === 0 && invalid === 0;', $script);
        self::assertStringContainsString('publishButton.hidden = alreadyPublished;', $script);
        self::assertStringContainsString('publicationState.hidden = !alreadyPublished;', $script);
        self::assertStringContainsString("draftStatus.textContent = alreadyPublished ? 'Опубликован' : 'Черновик';", $script);
    }

    public function testSidebarLayoutAndChangeCountersUseConsistentBaselines(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin-draft.js');

        self::assertMatchesRegularExpression('/\.draft-editor-layout\s*\{[^}]*grid-template-columns:\s*minmax\(0, 1fr\) 300px;/s', $css);
        self::assertMatchesRegularExpression('/\.draft-sidebar\s*\{[^}]*position:\s*sticky;[^}]*top:\s*16px;/s', $css);
        self::assertStringContainsString('.draft-sidebar-list[hidden] { display: none; }', $css);
        self::assertStringContainsString('if (current !== imported) changed++;', $script);
        self::assertStringContainsString('if (current !== loaded) unsaved++;', $script);
        self::assertStringContainsString('summary.changed.textContent = String(changed);', $script);
        self::assertStringContainsString('summary.section.hidden = changed === 0;', $script);
        self::assertStringContainsString('summary.increasedRow.hidden = increased === 0;', $script);
        self::assertStringContainsString('summary.decreasedRow.hidden = decreased === 0;', $script);
        self::assertStringContainsString('summary.percentRow.hidden = changed === 0;', $script);
        self::assertStringContainsString("aboutToggle.setAttribute('aria-expanded', String(expanded))", $script);
        self::assertStringContainsString('aboutContent.hidden = !expanded;', $script);
    }

    public function testEditorBackLinkIsRenderedAsSimpleRightAlignedHeaderLink(): void
    {
        $endpoint = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/draft.php');
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/src/admin_bootstrap.php');
        $styles = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');

        self::assertStringContainsString("'Вернуться на главную страницу админки'", $endpoint);
        self::assertStringContainsString('<a class="site-header-link"', $bootstrap);
        self::assertMatchesRegularExpression('/\.site-header-link\s*\{[^}]*color:\s*#fff;[^}]*text-decoration:\s*none;/s', $styles);
    }

    public function testRendersEscapedMetadataAndOrderedCategoriesAndServices(): void
    {
        $html = DraftEditorPage::render([
            'id' => 7,
            'status' => 'draft',
            'title' => '<script>title</script>',
            'price_date' => '2025-04-01',
            'original_filename' => 'client".xlsx',
            'categories' => [
                [
                    'name' => '<First>',
                    'services' => [[
                        'id' => 991,
                        'service_number' => 2,
                        'code' => 'A&1',
                        'name' => '<Service one>',
                        'imported_price_minor' => 100000,
                        'current_price_minor' => 110050,
                    ]],
                ],
                [
                    'name' => 'Second',
                    'services' => [[
                        'id' => 992,
                        'service_number' => 3,
                        'code' => 'B2',
                        'name' => 'Service two',
                        'imported_price_minor' => 200000,
                        'current_price_minor' => 200000,
                    ]],
                ],
            ],
            'revision' => 4,
            'restored_from_version_id' => 3,
        ], 'csrf-token-value', 3);

        self::assertStringNotContainsString('<script>title</script>', $html);
        self::assertStringContainsString('&lt;script&gt;title&lt;/script&gt;', $html);
        self::assertStringContainsString('client&quot;.xlsx', $html);
        self::assertStringContainsString('&lt;First&gt;', $html);
        self::assertStringContainsString('A&amp;1', $html);
        self::assertStringContainsString('&lt;Service one&gt;', $html);
        self::assertLessThan(strpos($html, 'Second'), strpos($html, '&lt;First&gt;'));
        self::assertLessThan(strpos($html, 'Service two'), strpos($html, '&lt;Service one&gt;'));
        self::assertStringContainsString('data-imported-minor="100000"', $html);
        self::assertStringContainsString('value="1100,50"', $html);
        self::assertSame(2, substr_count($html, 'class="price-input"'));
        self::assertStringContainsString('data-revision="4"', $html);
        self::assertStringContainsString('data-csrf-token="csrf-token-value"', $html);
        self::assertStringContainsString('data-published-version-id="3"', $html);
        self::assertStringContainsString('data-current-published-clone="true"', $html);
        self::assertStringContainsString('data-service-id="991"', $html);
        self::assertStringContainsString('data-service-id="992"', $html);
        self::assertStringNotContainsString('<th scope="col">ID</th>', $html);
        self::assertStringContainsString('data-save-prices disabled hidden', $html);
        self::assertStringContainsString('data-reset-prices hidden>Отменить изменения</button>', $html);
        self::assertStringNotContainsString('data-reset-prices>Сбросить</button>', $html);
        self::assertStringContainsString('data-publish-prices', $html);
        self::assertStringContainsString('Опубликовать прайс', $html);
        self::assertStringContainsString('role="dialog" aria-modal="true" aria-labelledby="publish-dialog-title"', $html);
        self::assertStringContainsString('<h2 id="publish-dialog-title">Опубликовать прайс?</h2>', $html);
        self::assertStringContainsString('Сохранённая версия станет доступна на публичной странице.', $html);
        self::assertStringContainsString('data-publish-cancel autofocus>Отмена</button>', $html);
        self::assertStringContainsString('data-publish-confirm>Опубликовать</button>', $html);
        self::assertStringContainsString('<aside class="draft-sidebar"', $html);
        self::assertStringContainsString('<div class="draft-status-line">', $html);
        self::assertStringContainsString('data-draft-state aria-live="polite">Нет несохранённых изменений.</p>', $html);
        self::assertStringContainsString('aria-expanded="false" aria-controls="draft-about-content"', $html);
        self::assertStringContainsString('<span>О прайсе</span><span class="status-draft" data-draft-status>Черновик</span>', $html);
        self::assertStringContainsString('data-draft-about-content hidden', $html);
        self::assertStringNotContainsString('<dt>Статус</dt>', $html);
        self::assertStringContainsString('<dt>Название</dt><dd>&lt;script&gt;title&lt;/script&gt;</dd>', $html);
        self::assertStringContainsString('<dt>Версия</dt><dd>№7</dd>', $html);
        self::assertStringNotContainsString('Черновик версии №', $html);
        self::assertStringNotContainsString('Изменяйте текущие цены и сохраняйте черновик без публикации.', $html);
        self::assertStringNotContainsString('>Назад</a>', $html);
        self::assertStringContainsString('<h2>Изменения</h2>', $html);
        self::assertStringNotContainsString('Изменений цен нет', $html);
        self::assertStringContainsString('<dt>Изменено</dt><dd data-summary-changed>1</dd>', $html);
        self::assertStringContainsString('<dt>Повышено</dt><dd class="change-positive" data-summary-increased>1</dd>', $html);
        self::assertSame(1, substr_count($html, '<dt>Дата прайса</dt>'));
        self::assertLessThan(strpos($html, '<aside class="draft-sidebar"'), strpos($html, '<div class="draft-table-wrap">'));
        self::assertStringContainsString('data-download-xlsx', $html);
        self::assertStringContainsString('data-save-download', $html);
        self::assertStringContainsString('data-download-saved', $html);
        self::assertStringContainsString('Скачать сохранённую версию', $html);
    }
}

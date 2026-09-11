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
        self::assertStringContainsString('font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;', $css);
    }

    public function testCategoryNamesUseUppercasePresentationWithoutChangingSourceValues(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');
        $renderer = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/DraftEditorPage.php');

        self::assertMatchesRegularExpression(
            '/\.category-row th\s*\{[^}]*text-transform:\s*uppercase;/s',
            $css
        );
        self::assertStringContainsString("self::e(\$category['name'])", $renderer);
        self::assertStringNotContainsString('strtoupper(', $renderer);
        self::assertStringNotContainsString('mb_strtoupper(', $renderer);
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
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');

        self::assertStringContainsString("fetch(appUrl('publish-version.php')", $script);
        self::assertStringContainsString('expected_revision: editor.dataset.revision', $script);
        self::assertStringContainsString('expected_published_version_id: editor.dataset.publishedVersionId || null', $script);
        self::assertStringContainsString('publishButton.disabled = unsaved > 0 || invalid > 0 || isSaving;', $script);
        self::assertStringContainsString('publishButton.hidden = alreadyPublished || unsaved > 0;', $script);
        self::assertStringNotContainsString('publishTooltip', $script);
        self::assertStringNotContainsString('draft-publish-tooltip', $css);
        self::assertStringContainsString('saveButton.hidden = unsaved === 0 && invalid === 0;', $script);
        self::assertStringContainsString('downloadButton.hidden = unsaved > 0 || invalid > 0 || isSaving;', $script);
        self::assertStringContainsString('resetButton.hidden = (unsaved === 0 && invalid === 0) || isSaving;', $script);
        self::assertStringContainsString("window.location.assign(appUrl(''));", $script);
        self::assertStringContainsString('isRedirectingAfterPublish = true;', $script);
        self::assertStringContainsString('if (isRedirectingAfterPublish) return;', $script);
        self::assertStringContainsString("const basePath = document.querySelector('[data-admin-base-path]')?.dataset.adminBasePath || '/admin/';", $script);
        self::assertStringNotContainsString("window.location.assign('/admin/')", $script);
        self::assertStringNotContainsString('publicationCompleted', $script);
        self::assertStringNotContainsString("summary.state.textContent = 'Прайс опубликован.';", $script);
        self::assertStringNotContainsString('input.disabled = true', $script);
        self::assertStringNotContainsString('window.confirm(', $script);
        self::assertStringContainsString("publishDialog.showModal();", $script);
        self::assertStringContainsString("publishConfirmButton.textContent = 'Публикуем…';", $script);
        self::assertStringContainsString('if (isPublishing) return;', $script);
        self::assertStringContainsString("const isCurrentPublishedClone = editor.dataset.currentPublishedClone === 'true';", $script);
        self::assertStringContainsString('const alreadyPublished = isCurrentPublishedClone && changed === 0 && unsaved === 0 && invalid === 0;', $script);
        self::assertStringContainsString('publicationState.hidden = !alreadyPublished;', $script);
        self::assertStringContainsString("draftStatus.textContent = alreadyPublished ? 'Опубликован' : 'Черновик';", $script);
    }

    public function testPublishedDraftUrlRedirectsToAdminHomeWithOneTimeError(): void
    {
        $endpoint = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/draft.php');
        $renderer = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/DraftEditorPage.php');

        self::assertStringContainsString("if ((\$version['status'] ?? null) !== 'draft')", $renderer);
        self::assertStringContainsString("Черновик не найден или недоступен для редактирования.", $endpoint);
        self::assertStringContainsString("\$adminSession->setFlash(['type' => 'error'", $endpoint);
        self::assertStringContainsString("admin_redirect('');", $endpoint);
    }

    public function testSidebarLayoutAndChangeCountersUseConsistentBaselines(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin-draft.js');

        self::assertMatchesRegularExpression('/\.draft-editor-layout\s*\{[^}]*grid-template-columns:\s*minmax\(0, 1fr\) minmax\(0, 300px\);/s', $css);
        self::assertMatchesRegularExpression('/\.draft-sidebar\s*\{[^}]*position:\s*sticky;[^}]*top:\s*16px;[^}]*max-width:\s*300px;/s', $css);
        self::assertStringContainsString('.draft-sidebar-list[hidden] { display: none; }', $css);
        self::assertStringContainsString('if (hasChangedPrice(row, current)) {', $script);
        self::assertStringContainsString('changed++;', $script);
        self::assertStringContainsString('if (current !== loaded) unsaved++;', $script);
        self::assertStringContainsString('summary.changed.textContent = String(changed);', $script);
        self::assertStringContainsString('summary.section.hidden = changed === 0;', $script);
        self::assertStringContainsString('summary.increasedRow.hidden = increased === 0;', $script);
        self::assertStringContainsString('summary.decreasedRow.hidden = decreased === 0;', $script);
        self::assertStringContainsString('summary.percentRow.hidden = changed === 0;', $script);
        self::assertStringContainsString("priceInfoToggle.setAttribute('aria-expanded', String(open));", $script);
        self::assertStringContainsString('priceInfoPanel.hidden = !open;', $script);
        self::assertStringContainsString('if (!priceInfo.contains(event.target)) setPriceInfoOpen(false);', $script);
        self::assertStringContainsString("if (event.key === 'Escape') setPriceInfoOpen(false);", $script);
    }

    public function testChangeSummaryUsesInlineLeadersAndResponsiveColumns(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');

        self::assertMatchesRegularExpression(
            '/\.draft-change-list\s*\{[^}]*grid-template-columns:\s*1fr;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.draft-sidebar-list \.leader\s*\{[^}]*flex:\s*1 1 auto;[^}]*background-image:\s*radial-gradient/s',
            $css
        );
        self::assertStringNotContainsString('.draft-sidebar-list div::after', $css);
        self::assertMatchesRegularExpression('/\.draft-stats-divider\s*\{[^}]*position:\s*absolute;[^}]*width:\s*4px;[^}]*border-radius:\s*999px;/s', $css);
        self::assertMatchesRegularExpression(
            '/@media \(max-width: 1100px\)\s*\{[^}]*\.draft-editor-layout\s*\{[^}]*grid-template-columns:\s*1fr;[^}]*\}[^}]*\.draft-sidebar\s*\{[^}]*grid-row:\s*1;[^}]*\}[^}]*\.draft-table-area\s*\{[^}]*grid-row:\s*2;[^}]*\}[^}]*\.draft-change-list\s*\{[^}]*grid-template-columns:\s*repeat\(2, minmax\(0, 1fr\)\);[^}]*\}[^}]*\.draft-stats-divider\s*\{[^}]*display:\s*block;/s',
            $css
        );
        self::assertStringContainsString('.draft-sidebar { position: static; grid-row: 1; max-width: none; }', $css);
        self::assertStringContainsString('.draft-sidebar .draft-actions { grid-template-columns: repeat(2, minmax(0, 1fr)); }', $css);
        self::assertMatchesRegularExpression(
            '/@media \(max-width: 640px\)\s*\{[\s\S]*?\.draft-change-list\s*\{[^}]*grid-template-columns:\s*1fr;/s',
            $css
        );
        self::assertStringContainsString('.draft-stats-divider { display: none; }', $css);
        self::assertMatchesRegularExpression('/\.draft-actions\s*\{[^}]*grid-template-columns:\s*repeat\(3, minmax\(0, 1fr\)\);/s', $css);
        self::assertStringContainsString('.draft-sidebar .draft-actions { grid-template-columns: repeat(2, minmax(0, 1fr)); }', $css);
        self::assertStringContainsString('.draft-sidebar .draft-download-action { grid-column: 1 / -1; }', $css);
        self::assertStringContainsString('.draft-sidebar [data-publish-prices] { grid-column: 1 / -1; }', $css);
        self::assertMatchesRegularExpression('/\.draft-actions button\s*\{[^}]*width:\s*100%;/s', $css);
        self::assertStringContainsString('.draft-sidebar .draft-download-action { grid-column: auto; }', $css);
        self::assertStringContainsString('.draft-sidebar [data-publish-prices] { grid-column: auto; }', $css);
        self::assertMatchesRegularExpression('/\.draft-download-action,\s*\[data-publish-prices\]\s*\{[^}]*gap:\s*8px;/s', $css);
        self::assertStringContainsString('.draft-action-icon { color: inherit; }', $css);
        self::assertMatchesRegularExpression(
            '/@media \(max-width: 640px\)\s*\{[\s\S]*?\.draft-actions,\s*\.draft-sidebar \.draft-actions\s*\{[^}]*grid-template-columns:\s*1fr;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/@media \(max-width: 640px\)\s*\{[\s\S]*?\.draft-table-tools\s*\{[^}]*justify-content:\s*flex-start;[^}]*flex-wrap:\s*wrap;/s',
            $css
        );
    }

    public function testPriceAndPercentInputsAreRecalculatedInBothDirections(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin-draft.js');

        self::assertStringContainsString("const percentInputs = rows.map((row) => row.querySelector('.percent-input'));", $script);
        self::assertStringContainsString('function priceFromPercent(imported, parsedPercent)', $script);
        self::assertStringContainsString('const numerator = imported * (denominator + parsedPercent.value);', $script);
        self::assertStringContainsString('const hundredths = roundedDivide((current - imported) * 10000n, imported);', $script);
        self::assertStringContainsString("input.addEventListener('input', () => {", $script);
        self::assertStringContainsString('if (current !== null) inputs[index].value = decimal(current);', $script);
        self::assertStringContainsString('if (index !== percentSourceIndex) percentInput.value = percentValue(current, imported);', $script);
        self::assertStringContainsString('const result = (absolute + denominator / 2n) / denominator;', $script);
        self::assertStringContainsString(
            "row.classList.remove('price-increased', 'price-decreased', 'price-unchanged', 'price-changed', 'price-invalid');",
            $script
        );
        self::assertStringContainsString("if (hasChangedPrice(row, current)) {", $script);
        self::assertStringContainsString("row.classList.add('price-changed');", $script);
        self::assertMatchesRegularExpression(
            '/\.price-changed \\.price-input\\s*\\{[^}]*background:\\s*#fff2c7;/s',
            $css
        );
    }

    public function testMassPriceControlReusesCurrentPricePercentCalculationAndConfirmsBeforeApplying(): void
    {
        $renderer = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/DraftEditorPage.php');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin-draft.js');
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');

        self::assertStringContainsString('data-mass-price-form', $renderer);
        self::assertStringContainsString('Изменить все цены на %', $renderer);
        self::assertStringContainsString('placeholder="Изменить все цены на %"', $renderer);
        self::assertStringContainsString('aria-label="Изменить все цены на %"', $renderer);
        self::assertStringNotContainsString('<label for="mass-price-percent">', $renderer);
        self::assertStringContainsString('data-mass-price-percent', $renderer);
        self::assertStringContainsString('data-mass-price-apply', $renderer);
        self::assertStringContainsString('data-mass-price-dialog', $renderer);
        self::assertStringContainsString('data-mass-price-confirm', $renderer);
        self::assertStringContainsString('aria-describedby="mass-price-dialog-message mass-price-dialog-note"', $renderer);
        self::assertStringContainsString('Изменения можно будет отменить до сохранения.', $renderer);
        self::assertStringContainsString('function validateMassPriceChange()', $script);
        self::assertStringContainsString('if (parsedPercent.value === 0n)', $script);
        self::assertStringContainsString('priceFromPercent(current, parsedPercent) === null', $script);
        self::assertStringContainsString('const next = priceFromPercent(current, parsedPercent);', $script);
        self::assertStringContainsString('percentInputs[index].value = percentValue(next, BigInt(row.dataset.importedMinor));', $script);
        self::assertStringContainsString("massPriceInput.value = '';", $script);
        self::assertStringContainsString("setMassPriceMessage('');", $script);
        self::assertStringNotContainsString('Изменены цены:', $script);
        self::assertStringContainsString('rows.forEach((row, index) => {', $script);
        self::assertStringContainsString('massPriceDialog.showModal();', $script);
        self::assertStringContainsString('Все текущие цены будут ${direction}', $script);
        self::assertStringContainsString('Будет изменено ${rows.length} позиций.', $script);
        self::assertStringNotContainsString('Будет изменено ${rows.length} позиций. Продолжить?', $script);
        self::assertStringContainsString("massPriceDialog.addEventListener('cancel'", $script);
        self::assertStringContainsString('massPriceDialog.addEventListener(\'close\', () => massPriceApplyButton.focus())', $script);
        self::assertStringContainsString('applyMassPriceChange(parsedPercent);', $script);
        self::assertMatchesRegularExpression('/\.draft-mass-price-control\s*\{[^}]*flex-wrap:\s*wrap;/s', $css);
        self::assertMatchesRegularExpression('/\.mass-price-percent-input\s*\{[^}]*flex:\s*0 1 220px;[^}]*width:\s*220px;[^}]*min-height:\s*var\(--control-height\);[^}]*border-radius:\s*var\(--control-radius\);/s', $css);
        self::assertStringContainsString('.draft-mass-price-control button { min-height: var(--control-height); padding: 6px 18px; }', $css);
        self::assertStringContainsString('.mass-price-dialog-note { margin: 10px 0 0; color: var(--muted); font-size: 14px; }', $css);
        self::assertStringContainsString('border-radius: 16px;', $css);
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

    public function testEditorHeaderSearchFiltersServicesAndEmptyCategoriesClientSide(): void
    {
        $endpoint = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/draft.php');
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/src/admin_bootstrap.php');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin-draft.js');

        self::assertStringContainsString("'Вернуться на главную страницу админки',\n    true", $endpoint);
        self::assertStringContainsString('font-awesome/6.7.2/css/all.min.css', $bootstrap);
        self::assertStringContainsString("style-src 'self' https://cdnjs.cloudflare.com", $bootstrap);
        self::assertStringContainsString("font-src 'self' https://cdnjs.cloudflare.com", $bootstrap);
        self::assertStringContainsString('fa-solid fa-magnifying-glass site-header-search-icon" aria-hidden="true"', $bootstrap);
        self::assertStringContainsString('placeholder="Поиск по услугам" aria-label="Поиск по услугам"', $bootstrap);
        self::assertStringContainsString('data-service-search-clear hidden', $bootstrap);
        self::assertMatchesRegularExpression('/\.site-header-search input\s*\{[^}]*padding:\s*7px 38px 7px 38px;/s', $styles = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css'));
        self::assertMatchesRegularExpression('/\.site-header-search-icon\s*\{[^}]*position:\s*absolute;[^}]*pointer-events:\s*none;[^}]*color:\s*var\(--muted\);/s', $styles);
        self::assertStringContainsString('input::-webkit-search-cancel-button', $styles);
        self::assertStringContainsString('input::-ms-clear', $styles);
        self::assertStringContainsString("row.querySelector('.service-number')", $script);
        self::assertStringContainsString("row.querySelector('.service-code')", $script);
        self::assertStringContainsString("row.querySelector('.service-name')", $script);
        self::assertStringContainsString(".toLocaleLowerCase('ru-RU')", $script);
        self::assertStringContainsString("searchInput.addEventListener('input', filterServices)", $script);
        self::assertStringContainsString('category.hidden = !Array.from(', $script);
        self::assertStringContainsString("searchInput.value = '';", $script);
    }

    public function testChangedPriceFilterUsesImportedAndCurrentPricesWithSearch(): void
    {
        $renderer = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/DraftEditorPage.php');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin-draft.js');
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');

        self::assertStringContainsString('data-changed-filter aria-pressed="false"', $renderer);
        self::assertStringContainsString('fa-regular fa-eye-slash draft-changed-filter-icon" aria-hidden="true"', $renderer);
        self::assertStringContainsString('<span>Отображать только изменённые <span data-changed-filter-count>', $renderer);
        self::assertStringContainsString('data-changed-filter-count>(<?= $changedCount ?>)</span>', $renderer);
        self::assertLessThan(
            strpos($renderer, '<div class="draft-table-wrap">'),
            strpos($renderer, '<div class="draft-table-tools"')
        );
        self::assertStringContainsString('function hasChangedPrice(row, current = parseMinor(row.querySelector(\'.price-input\').value))', $script);
        self::assertStringContainsString('current !== BigInt(row.dataset.importedMinor)', $script);
        self::assertStringContainsString('const matchesSearch = query === \'\' || text.includes(query);', $script);
        self::assertStringContainsString('const matchesChanged = !changedOnly || hasChangedPrice(row);', $script);
        self::assertStringContainsString('const matches = matchesSearch && matchesChanged;', $script);
        self::assertStringContainsString('category.hidden = !Array.from(', $script);
        self::assertStringContainsString('changedFilterCount.textContent = `(${changed})`;', $script);
        self::assertStringContainsString('changedFilter.hidden = changed === 0;', $script);
        self::assertStringContainsString('if (changed === 0 && changedOnly) {', $script);
        self::assertStringContainsString('changedOnly = false;', $script);
        self::assertStringContainsString("changedFilter.setAttribute('aria-pressed', 'false');", $script);
        self::assertStringContainsString("changedFilter.classList.remove('is-active');", $script);
        self::assertStringContainsString('filterServices();', $script);
        self::assertStringContainsString("searchEmpty.textContent = changedOnly && query === '' ? 'Нет изменённых позиций.' : 'Услуги не найдены';", $script);
        self::assertStringContainsString('.draft-changed-filter.is-active', $css);
        self::assertMatchesRegularExpression('/\.draft-changed-filter\s*\{[^}]*display:\s*inline-flex;[^}]*gap:\s*7px;/s', $css);
        self::assertStringContainsString('.draft-changed-filter-icon { color: inherit; }', $css);
        self::assertStringContainsString('justify-content: space-between;', $css);
        self::assertStringContainsString('.draft-price-info { position: relative; min-width: 0; z-index: 1; }', $css);
        self::assertStringContainsString('width: min(430px, calc(100vw - 32px));', $css);
    }

    public function testPriceInfoDropdownLayerIsAboveTheScrollableTable(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');

        self::assertMatchesRegularExpression(
            '/\.draft-table-tools\s*\{[^}]*position:\s*relative;[^}]*z-index:\s*1;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.draft-price-info\s*\{[^}]*position:\s*relative;[^}]*z-index:\s*1;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.draft-price-info-panel\s*\{[^}]*position:\s*absolute;[^}]*z-index:\s*2;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.draft-table-wrap\s*\{[^}]*overflow:\s*auto;[^}]*position:\s*relative;[^}]*z-index:\s*0;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.draft-table thead th\s*\{[^}]*position:\s*sticky;[^}]*z-index:\s*8;/s',
            $css
        );
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
        self::assertStringContainsString('<tr class="service-edit-row price-changed"', $html);
        self::assertStringContainsString('<tr class="service-edit-row price-unchanged"', $html);
        self::assertSame(2, substr_count($html, 'class="price-input"'));
        self::assertSame(2, substr_count($html, 'class="percent-input"'));
        self::assertStringContainsString('type="number"', $html);
        self::assertStringContainsString('step="any"', $html);
        self::assertStringContainsString('data-revision="4"', $html);
        self::assertStringContainsString('data-csrf-token="csrf-token-value"', $html);
        self::assertStringContainsString('data-published-version-id="3"', $html);
        self::assertStringContainsString('data-current-published-clone="true"', $html);
        self::assertStringContainsString('data-service-id="991"', $html);
        self::assertStringContainsString('data-service-id="992"', $html);
        self::assertStringNotContainsString('<th scope="col">ID</th>', $html);
        self::assertStringContainsString('data-save-prices disabled hidden', $html);
        self::assertStringContainsString('data-save-prices disabled hidden>Сохранить изменения</button>', $html);
        self::assertStringContainsString('data-reset-prices hidden>Отменить изменения</button>', $html);
        self::assertStringNotContainsString('data-reset-prices>Сбросить</button>', $html);
        self::assertStringContainsString('data-publish-prices', $html);
        self::assertStringContainsString('Опубликовать прайс', $html);
        self::assertStringContainsString('fa-solid fa-file-arrow-down draft-action-icon" aria-hidden="true"', $html);
        self::assertStringContainsString('fa-solid fa-globe draft-action-icon" aria-hidden="true"', $html);
        self::assertStringContainsString('<th scope="col">№</th>', $html);
        self::assertStringNotContainsString('<th scope="col">№ услуги</th>', $html);
        self::assertStringNotContainsString('draft-publish-tooltip', $html);
        self::assertStringNotContainsString('draft-publish-unsaved-tooltip', $html);
        self::assertStringContainsString('role="dialog" aria-modal="true" aria-labelledby="publish-dialog-title"', $html);
        self::assertStringContainsString('<h2 id="publish-dialog-title">Опубликовать прайс?</h2>', $html);
        self::assertStringContainsString('Сохранённая версия станет доступна на публичной странице.', $html);
        self::assertStringContainsString('data-publish-cancel autofocus>Отмена</button>', $html);
        self::assertStringContainsString('data-publish-confirm>Опубликовать</button>', $html);
        self::assertStringContainsString('<aside class="draft-sidebar"', $html);
        self::assertStringContainsString('data-service-search-empty role="status" hidden>Услуги не найдены', $html);
        self::assertStringContainsString('data-changed-filter aria-pressed="false"', $html);
        self::assertStringContainsString('fa-regular fa-eye-slash draft-changed-filter-icon" aria-hidden="true"', $html);
        self::assertStringContainsString('Отображать только изменённые <span data-changed-filter-count>(1)</span>', $html);
        self::assertStringContainsString('data-changed-filter-count>(1)</span>', $html);
        self::assertStringContainsString('<div class="draft-status-line">', $html);
        self::assertStringContainsString('data-draft-state aria-live="polite">Нет несохранённых изменений.</p>', $html);
        self::assertStringContainsString('<h2 class="draft-sidebar-title">О прайсе <span class="status-draft" data-draft-status>Черновик</span></h2>', $html);
        self::assertStringNotContainsString('<dt>Статус</dt>', $html);
        self::assertStringContainsString('aria-expanded="false" aria-controls="draft-price-info-panel" data-draft-price-info-toggle', $html);
        self::assertStringContainsString('id="draft-price-info-panel" class="draft-price-info-panel" data-draft-price-info-panel hidden', $html);
        self::assertStringContainsString('<span class="meta-label">Название:</span><span class="meta-value">&lt;script&gt;title&lt;/script&gt;</span>', $html);
        self::assertStringContainsString('<span class="meta-label">Версия:</span><span class="meta-value">№7</span>', $html);
        self::assertStringContainsString('<span class="meta-label">Дата прайса:</span><span class="meta-value">2025-04-01</span>', $html);
        self::assertStringContainsString('<span class="meta-label">Исходный файл:</span><span class="meta-value">client&quot;.xlsx</span>', $html);
        self::assertStringContainsString('<span class="meta-label">Услуг:</span><span class="meta-value">2</span>', $html);
        self::assertStringNotContainsString('draft-price-details', $html);
        self::assertStringNotContainsString('draft-about-content', $html);
        self::assertStringNotContainsString('class="meta-label">Название:</span><span class="leader"', $html);
        self::assertStringNotContainsString('Черновик версии №', $html);
        self::assertStringNotContainsString('Изменяйте текущие цены и сохраняйте черновик без публикации.', $html);
        self::assertStringNotContainsString('>Назад</a>', $html);
        self::assertStringContainsString('<h2>Изменения</h2>', $html);
        self::assertStringContainsString('class="draft-stat draft-stat-changed"', $html);
        self::assertStringContainsString('class="draft-stat draft-stat-increased"', $html);
        self::assertStringContainsString('class="draft-stat draft-stat-original"', $html);
        self::assertStringContainsString('class="draft-stat draft-stat-current"', $html);
        self::assertStringContainsString('class="draft-stat draft-stat-percent"', $html);
        self::assertStringNotContainsString('Изменений цен нет', $html);
        self::assertStringContainsString('<dt>Изменено</dt><span class="leader" aria-hidden="true"></span><dd data-summary-changed>1</dd>', $html);
        self::assertStringContainsString('<dt>Повышено</dt><span class="leader" aria-hidden="true"></span><dd class="change-positive" data-summary-increased>1</dd>', $html);
        self::assertStringContainsString('<span class="draft-stats-divider" aria-hidden="true"></span>', $html);
        self::assertSame(1, substr_count($html, 'class="meta-label">Дата прайса:</span>'));
        self::assertLessThan(strpos($html, '<aside class="draft-sidebar"'), strpos($html, '<div class="draft-table-wrap">'));
        self::assertStringContainsString('data-download-xlsx', $html);
        self::assertStringContainsString('data-save-download', $html);
        self::assertStringContainsString('data-download-saved', $html);
        self::assertStringContainsString('Скачать сохранённую версию', $html);
    }

    public function testChangedPriceFilterIsInitiallyHiddenWithoutChangedPrices(): void
    {
        $html = DraftEditorPage::render([
            'id' => 8,
            'status' => 'draft',
            'title' => 'Без изменений',
            'price_date' => null,
            'original_filename' => 'source.xlsx',
            'categories' => [[
                'name' => 'Категория',
                'services' => [[
                    'id' => 993,
                    'service_number' => 1,
                    'code' => 'C3',
                    'name' => 'Услуга',
                    'imported_price_minor' => 100000,
                    'current_price_minor' => 100000,
                ]],
            ]],
            'revision' => 1,
        ], 'csrf-token-value');

        self::assertStringContainsString('data-changed-filter aria-pressed="false" hidden', $html);
        self::assertStringContainsString('data-changed-filter-count>(0)</span>', $html);
    }
}

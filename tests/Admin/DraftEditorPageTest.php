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
            '/\.draft-table-wrap\s*\{[^}]*overflow:\s*auto;/s',
            $css
        );
        self::assertStringNotContainsString('top: 118px', $css);
        self::assertMatchesRegularExpression('/\.service-edit-row:not\(\.price-invalid\):hover\s*\{[^}]*background:/s', $css);
        self::assertMatchesRegularExpression('/\.service-edit-row:not\(\.price-invalid\):focus-within\s*\{[^}]*background:/s', $css);
        self::assertStringContainsString('.service-edit-row { transition: none; }', $css);
        self::assertStringContainsString('font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;', $css);
    }

    public function testEditorTableFillsViewportAndKeepsLowViewportScrollable(): void
    {
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/src/admin_bootstrap.php');
        $renderer = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/DraftEditorPage.php');
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');

        self::assertStringContainsString('<body<?= $showDesktopMenu ? \' class="draft-editor-page"\' : \'\' ?>>', $bootstrap);
        self::assertStringContainsString('class="draft-table-wrap" tabindex="0" role="region" aria-label="Таблица услуг"', $renderer);
        self::assertMatchesRegularExpression('/body\.draft-editor-page\s*\{[^}]*display:\s*flex;[^}]*flex-direction:\s*column;[^}]*height:\s*100vh;[^}]*height:\s*100dvh;[^}]*overflow-y:\s*auto;/s', $css);
        self::assertMatchesRegularExpression('/body\.draft-editor-page main\.admin-shell-wide\s*\{[^}]*flex:\s*1 1 auto;[^}]*min-height:\s*0;[^}]*padding:\s*0 0 20px;/s', $css);
        self::assertStringContainsString('body.draft-editor-page .draft-editor,', $css);
        self::assertStringContainsString('body.draft-editor-page .draft-table-area {', $css);
        self::assertMatchesRegularExpression('/body\.draft-editor-page \.draft-table-wrap\s*\{[^}]*flex:\s*1 1 auto;[^}]*min-height:\s*140px;[^}]*max-height:\s*none;/s', $css);
        self::assertStringContainsString('body.draft-editor-page .draft-table-wrap { flex: 0 0 220px; }', $css);
        self::assertStringContainsString('body.draft-editor-page { height: auto; min-height: 100vh; min-height: 100dvh; }', $css);
        self::assertStringContainsString('scrollbar-width: thin;', $css);
        self::assertStringContainsString('.draft-table-wrap::-webkit-scrollbar-thumb:hover', $css);
        self::assertStringContainsString('body.draft-editor-page dialog {', $css);
        self::assertStringContainsString('max-height: calc(100dvh - 32px);', $css);
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
        self::assertStringContainsString('publishButton.disabled = hasValidUnsavedChanges || invalid > 0 || isSaving;', $script);
        self::assertStringContainsString('publishButton.hidden = alreadyPublished || hasValidUnsavedChanges;', $script);
        self::assertStringNotContainsString('publishTooltip', $script);
        self::assertStringNotContainsString('draft-publish-tooltip', $css);
        self::assertStringContainsString('saveButton.hidden = !hasValidUnsavedChanges && invalid === 0;', $script);
        self::assertStringContainsString('downloadButton.disabled = isSaving;', $script);
        self::assertStringNotContainsString('downloadButton.hidden', $script);
        self::assertStringContainsString('resetButton.hidden = (!hasValidUnsavedChanges && invalid === 0) || isSaving;', $script);
        self::assertStringContainsString("window.location.assign(appUrl(''));", $script);
        self::assertStringContainsString('isRedirectingAfterPublish = true;', $script);
        self::assertStringContainsString('if (isRedirectingAfterPublish) return;', $script);
        self::assertStringContainsString("const basePath = document.querySelector('[data-admin-base-path]')?.dataset.adminBasePath || '/admin/';", $script);
        self::assertStringNotContainsString("window.location.assign('/admin/')", $script);
        self::assertStringNotContainsString('publicationCompleted', $script);
        self::assertStringNotContainsString("summary.state.textContent = 'Прайс опубликован.';", $script);
        self::assertStringNotContainsString('input.disabled = true', $script);
        self::assertStringContainsString("await confirmDeletion('category'", $script);
        self::assertStringContainsString("publishDialog.showModal();", $script);
        self::assertStringContainsString("publishConfirmButton.textContent = 'Публикуем…';", $script);
        self::assertStringContainsString('if (isPublishing) return;', $script);
        self::assertStringContainsString("const isCurrentPublishedClone = editor.dataset.currentPublishedClone === 'true';", $script);
        self::assertStringContainsString('const alreadyPublished = isCurrentPublishedClone && changed === 0 && !hasValidUnsavedChanges && invalid === 0;', $script);
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

    public function testFullWidthTableAndChangeCountersUseConsistentBaselines(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin-draft.js');

        self::assertStringContainsString('.draft-table-area {', $css);
        self::assertMatchesRegularExpression('/\.draft-table-area\s*\{[^}]*width:\s*100%;/s', $css);
        self::assertStringNotContainsString('.draft-editor-layout', $css);
        self::assertStringNotContainsString('.draft-sidebar', $css);
        self::assertStringContainsString('if (hasChangedPrice(row, current)) {', $script);
        self::assertStringContainsString('changed++;', $script);
        self::assertStringContainsString('if (current !== loaded) unsaved++;', $script);
        self::assertStringContainsString('secondaryBar.append(summary.section', $script);
        self::assertStringContainsString('summary.section.hidden = changed === 0;', $script);
        self::assertStringContainsString('summary.increasedRow.hidden = increased === 0;', $script);
        self::assertStringContainsString('summary.decreasedRow.hidden = decreased === 0;', $script);
        self::assertStringContainsString('summary.percentRow.hidden = changed === 0;', $script);
        self::assertStringContainsString("document.querySelector('[data-menu-price-info]')", $script);
        self::assertStringContainsString('priceInfoDialog.showModal()', $script);
        self::assertStringContainsString('priceInfoDialog.close()', $script);
        self::assertStringContainsString("priceInfoDialog.addEventListener('close', () => priceInfoToggle.focus())", $script);
    }

    public function testChangeSummaryAndActionsUseCompactResponsiveControls(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');

        self::assertMatchesRegularExpression('/\.draft-editor-controls\s*\{[^}]*display:\s*flex;[^}]*flex-wrap:\s*wrap;/s', $css);
        self::assertMatchesRegularExpression('/\.draft-summary-list\s*\{[^}]*display:\s*flex;[^}]*flex-wrap:\s*wrap;/s', $css);
        self::assertStringContainsString('.draft-summary[hidden], .draft-summary-list [hidden] { display: none !important; }', $css);
        self::assertStringContainsString('.draft-summary-list .leader { display: none; }', $css);
        self::assertMatchesRegularExpression('/\.draft-actions\s*\{[^}]*display:\s*flex;[^}]*flex-wrap:\s*wrap;/s', $css);
        self::assertMatchesRegularExpression('/\[data-publish-prices\]\s*\{[^}]*gap:\s*8px;/s', $css);
        self::assertStringContainsString('.draft-action-icon { color: inherit; }', $css);
        self::assertStringContainsString('.draft-summary { flex-basis: 100%; }', $css);
        self::assertStringContainsString('.draft-actions { justify-content: flex-start; margin-left: 0; }', $css);
        self::assertStringNotContainsString('.draft-stats-divider', $css);
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
        self::assertStringContainsString('if (!hasRawPercent) percentInput.value = percentValue(current, imported);', $script);
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
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/src/admin_bootstrap.php');

        self::assertLessThan(strpos($bootstrap, 'data-menu-mass-price>Изменить все цены на %'), strpos($bootstrap, 'data-add-category>Добавить раздел'));
        self::assertStringContainsString('role="menuitem" aria-haspopup="dialog" data-menu-mass-price>Изменить все цены на %', $bootstrap);
        self::assertStringContainsString('data-mass-price-entry-dialog role="dialog" aria-modal="true" aria-labelledby="mass-price-entry-title" aria-describedby="mass-price-entry-note"', $renderer);
        self::assertStringContainsString('data-mass-price-form', $renderer);
        self::assertStringContainsString('<label for="mass-price-percent">Процент изменения</label>', $renderer);
        self::assertStringContainsString('data-mass-price-percent', $renderer);
        self::assertStringContainsString('data-mass-price-apply', $renderer);
        self::assertStringContainsString('data-mass-price-entry-cancel>Отмена</button>', $renderer);
        self::assertStringContainsString('data-mass-price-message role="alert" aria-live="polite"', $renderer);
        self::assertStringNotContainsString('draft-mass-price-control', $renderer);
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
        self::assertStringContainsString("document.querySelector('[data-menu-mass-price]')", $script);
        self::assertStringContainsString('massPriceEntryDialog.close();', $script);
        self::assertStringContainsString('massPriceEntryDialog.showModal();', $script);
        self::assertStringContainsString('returnToMassPriceEntry = true;', $script);
        self::assertStringContainsString('focusMassPriceMenuCommand();', $script);
        self::assertStringContainsString('applyMassPriceChange(parsedPercent);', $script);
        self::assertMatchesRegularExpression('/\.mass-price-percent-input\s*\{[^}]*width:\s*100%;[^}]*min-height:\s*var\(--control-height\);[^}]*border-radius:\s*var\(--control-radius\);/s', $css);
        self::assertStringContainsString('.mass-price-percent-input:focus-visible', $css);
        self::assertStringContainsString('.mass-price-entry-dialog,', $css);
        self::assertStringContainsString('.mass-price-dialog-note { margin: 10px 0 0; color: var(--muted); font-size: 14px; }', $css);
        self::assertStringContainsString('border-radius: 16px;', $css);
    }

    public function testFinishEditingIsLastDocumentMenuItem(): void
    {
        $endpoint = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/draft.php');
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/src/admin_bootstrap.php');
        $styles = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');

        self::assertStringContainsString("admin_url(''),\n    'Закончить редактирование'", $endpoint);
        self::assertStringContainsString('<li class="desktop-menu-separator" role="separator" aria-orientation="horizontal"></li>', $bootstrap);
        self::assertStringContainsString('<li role="none"><a role="menuitem" href="<?= admin_e($headerLinkHref) ?>" data-editor-exit-link><?= admin_e($headerLinkLabel) ?></a></li>', $bootstrap);
        self::assertLessThan(strpos($bootstrap, 'class="desktop-menu-separator"'), strpos($bootstrap, 'data-download-xlsx>Скачать Excel'));
        self::assertLessThan(strpos($bootstrap, 'data-editor-exit-link'), strpos($bootstrap, 'class="desktop-menu-separator"'));
        self::assertStringContainsString('$headerLinkHref !== null && $headerLinkLabel !== null && !$showDesktopMenu', $bootstrap);
        self::assertStringContainsString('.desktop-menu-popup .desktop-menu-separator {', $styles);
        self::assertStringContainsString('.desktop-menu-bar .desktop-menu-popup a:focus-visible', $styles);
    }

    public function testEditorBackLinkConfirmsUnsavedPriceAndStructureChanges(): void
    {
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/src/admin_bootstrap.php');
        $renderer = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/DraftEditorPage.php');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin-draft.js');
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');

        self::assertStringContainsString('role="menuitem" href="<?= admin_e($headerLinkHref) ?>" data-editor-exit-link', $bootstrap);
        self::assertStringContainsString('href="<?= admin_e($headerLinkHref) ?>"', $bootstrap);
        self::assertStringContainsString('class="exit-dialog" data-exit-dialog role="dialog" aria-modal="true"', $renderer);
        self::assertStringContainsString('Сохранить изменения перед выходом?', $renderer);
        self::assertStringContainsString('data-exit-save>Сохранить и выйти', $renderer);
        self::assertStringContainsString('data-exit-discard>Выйти без сохранения', $renderer);
        self::assertStringContainsString('data-exit-cancel autofocus>Отмена', $renderer);
        self::assertStringContainsString('data-exit-dialog-message role="alert" hidden', $renderer);
        self::assertStringContainsString('hasValidUnsavedChanges = unsaved > 0 || structuralChanges > 0;', $script);
        self::assertStringContainsString('if (!hasValidUnsavedChanges && invalidCount === 0) return;', $script);
        self::assertStringContainsString('exitDialog.showModal();', $script);
        self::assertStringContainsString("exitDialog.addEventListener('close', () => {", $script);
        self::assertStringContainsString('setDocumentMenuOpen(true);', $script);
        self::assertStringContainsString('exitLink.focus();', $script);
        self::assertStringContainsString('setDocumentMenuOpen(false);', $script);
        self::assertStringContainsString('items[(index + direction + items.length) % items.length].focus();', $script);
        self::assertStringContainsString("event.key === 'Home' || event.key === 'End'", $script);
        self::assertStringContainsString("exitDialog.addEventListener('cancel', (event) => {", $script);
        self::assertStringContainsString('exitSaveButton.disabled = invalidCount > 0;', $script);
        self::assertStringContainsString('Некорректных цен:', $script);
        self::assertStringContainsString('if (isExitSaving || isSaving) return;', $script);
        self::assertStringContainsString('const saved = await saveDraft({reloadOnStructure: false});', $script);
        self::assertStringContainsString('if (reloadOnStructure) window.location.reload();', $script);
        self::assertStringContainsString("setExitDialogMessage(saveMessage.textContent || 'Не удалось сохранить изменения. Попробуйте ещё раз.', true);", $script);
        self::assertSame(2, substr_count($script, 'window.location.assign(exitLink.href);'));
        self::assertStringContainsString('if (isExitConfirmed || (!hasValidUnsavedChanges && invalidCount === 0)) return;', $script);
        self::assertStringContainsString('.exit-dialog::backdrop,', $css);
        self::assertStringContainsString('.exit-dialog-actions { display: flex;', $css);
    }

    public function testEditorSecondaryBarSearchFiltersServicesAndEmptyCategoriesClientSide(): void
    {
        $endpoint = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/draft.php');
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/src/admin_bootstrap.php');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin-draft.js');

        self::assertStringContainsString("'Закончить редактирование',\n    true", $endpoint);
        self::assertStringContainsString('font-awesome/6.7.2/css/all.min.css', $bootstrap);
        self::assertStringContainsString("style-src 'self' https://cdnjs.cloudflare.com", $bootstrap);
        self::assertStringContainsString("font-src 'self' https://cdnjs.cloudflare.com", $bootstrap);
        self::assertStringContainsString('fa-solid fa-magnifying-glass site-header-search-icon" aria-hidden="true"', $bootstrap);
        self::assertStringContainsString('placeholder="Поиск по услугам" aria-label="Поиск по услугам"', $bootstrap);
        self::assertStringContainsString('data-service-search-clear hidden', $bootstrap);
        self::assertLessThan(strpos($bootstrap, '<main data-admin-base-path='), strpos($bootstrap, '<div class="desktop-secondary-bar">'));
        self::assertStringContainsString('<?php if ($showPriceSearch && !$showDesktopMenu): ?>', $bootstrap);
        self::assertMatchesRegularExpression('/\.site-header-search input\s*\{[^}]*padding:\s*7px 38px 7px 38px;/s', $styles = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css'));
        self::assertMatchesRegularExpression('/\.desktop-secondary-bar\s*\{[^}]*display:\s*flex;[^}]*align-items:\s*center;[^}]*height:\s*52px;/s', $styles);
        self::assertMatchesRegularExpression('/\.desktop-secondary-bar \.site-header-search\s*\{[^}]*flex:\s*0 1 420px;[^}]*min-width:\s*0;/s', $styles);
        self::assertMatchesRegularExpression('/\.site-header-search-icon\s*\{[^}]*position:\s*absolute;[^}]*pointer-events:\s*none;[^}]*color:\s*var\(--muted\);/s', $styles);
        self::assertStringContainsString('input::-webkit-search-cancel-button', $styles);
        self::assertStringContainsString('input::-ms-clear', $styles);
        self::assertStringContainsString("row.querySelector('.service-number')", $script);
        self::assertStringContainsString("row.querySelector('.service-code')", $script);
        self::assertStringContainsString("row.querySelector('.service-name')", $script);
        self::assertStringContainsString(".toLocaleLowerCase('ru-RU')", $script);
        self::assertStringContainsString("searchInput.addEventListener('input', filterServices)", $script);
        self::assertStringContainsString("document.querySelector('[data-service-search]')", $script);
        self::assertStringContainsString("document.querySelector('[data-service-search-clear]')", $script);
        self::assertStringContainsString('category.hidden = !visibleExisting && pendingCount === 0;', $script);
        self::assertStringContainsString("searchInput.value = '';", $script);
    }

    public function testEditorOmitsSharedHeaderWithoutRemovingItFromOtherAdminPages(): void
    {
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/src/admin_bootstrap.php');
        $endpoint = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/draft.php');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin-draft.js');
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');

        self::assertStringContainsString("'admin-shell-wide',", $endpoint);
        self::assertStringContainsString("    true,\n    true,\n    true\n);", $endpoint);
        self::assertStringContainsString('<?php if (!$showDesktopMenu): ?>' . "\n        <header class=\"site-header\">", $bootstrap);
        self::assertStringContainsString('</header>' . "\n        <?php endif; ?>", $bootstrap);
        self::assertStringContainsString('<nav class="desktop-menu-bar"', $bootstrap);
        self::assertStringContainsString('<div class="desktop-secondary-bar">', $bootstrap);
        self::assertStringContainsString('main.admin-shell-wide { padding-top: 0; }', $css);
        self::assertStringContainsString('.site-header {', $css);
        self::assertStringContainsString("document.querySelector('[data-service-search]')", $script);
        self::assertStringNotContainsString("querySelector('.site-header')", $script);
    }

    public function testEditorBarsStickTogetherAboveTableAndKeepScrollTargetsVisible(): void
    {
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/src/admin_bootstrap.php');
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');

        self::assertStringContainsString('<div class="desktop-editor-bars">', $bootstrap);
        self::assertLessThan(strpos($bootstrap, '<nav class="desktop-menu-bar"'), strpos($bootstrap, '<div class="desktop-editor-bars">'));
        self::assertLessThan(strpos($bootstrap, '<div class="desktop-secondary-bar">'), strpos($bootstrap, '<nav class="desktop-menu-bar"'));
        self::assertLessThan(strpos($bootstrap, '<main data-admin-base-path='), strpos($bootstrap, '<div class="desktop-secondary-bar">'));
        self::assertMatchesRegularExpression('/\.desktop-editor-bars\s*\{[^}]*position:\s*sticky;[^}]*top:\s*0;[^}]*z-index:\s*20;[^}]*width:\s*100%;[^}]*background:\s*#f7f9fa;/s', $css);
        self::assertStringContainsString('scroll-margin-top: 90px;', $css);
        self::assertMatchesRegularExpression('/\.desktop-menu-bar\s*\{[^}]*height:\s*26px;/s', $css);
        self::assertMatchesRegularExpression('/\.desktop-secondary-bar\s*\{[^}]*height:\s*52px;/s', $css);
        self::assertMatchesRegularExpression('/\.draft-table-wrap\s*\{[^}]*overflow:\s*auto;[^}]*position:\s*relative;[^}]*z-index:\s*0;/s', $css);
        self::assertMatchesRegularExpression('/\.draft-table thead th\s*\{[^}]*position:\s*sticky;[^}]*z-index:\s*8;[^}]*top:\s*0;/s', $css);
    }

    public function testChangedPriceFilterUsesImportedAndCurrentPricesWithSearch(): void
    {
        $renderer = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/DraftEditorPage.php');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin-draft.js');
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');

        $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/src/admin_bootstrap.php');
        self::assertStringContainsString('data-menu-view>Вид</button>', $bootstrap);
        self::assertStringContainsString('role="menuitemcheckbox" aria-checked="false" data-changed-filter', $bootstrap);
        self::assertStringContainsString('Отображать только изменённые <span data-changed-filter-count>(0)</span>', $bootstrap);
        self::assertStringNotContainsString('data-changed-filter', $renderer);
        self::assertStringNotContainsString('draft-table-tools', $renderer);
        self::assertStringContainsString('function hasChangedPrice(row, current = parseMinor(row.querySelector(\'.price-input\').value))', $script);
        self::assertStringContainsString('current !== BigInt(row.dataset.importedMinor)', $script);
        self::assertStringContainsString('const matchesSearch = query === \'\' || text.includes(query);', $script);
        self::assertStringContainsString('const matchesChanged = !changedOnly || hasChangedPrice(row);', $script);
        self::assertStringContainsString('const matches = matchesSearch && matchesChanged;', $script);
        self::assertStringContainsString('category.hidden = !visibleExisting && pendingCount === 0;', $script);
        self::assertStringContainsString('changedFilterCount.textContent = `(${changed})`;', $script);
        self::assertStringContainsString('changedFilter.disabled = changed === 0;', $script);
        self::assertStringContainsString('if (changed === 0 && changedOnly) {', $script);
        self::assertStringContainsString('changedOnly = false;', $script);
        self::assertStringContainsString("changedFilter.setAttribute('aria-checked', 'false');", $script);
        self::assertStringContainsString('setViewMenuOpen(false);', $script);
        self::assertStringContainsString('filterServices();', $script);
        self::assertStringContainsString("searchEmpty.textContent = changedOnly && query === '' ? 'Нет изменённых позиций.' : 'Услуги не найдены';", $script);
        self::assertStringContainsString('.desktop-menu-bar > ul {', $css);
        self::assertStringContainsString('.desktop-menu-bar > ul > li {', $css);
        self::assertStringContainsString('.desktop-menu-popup[hidden] { display: none; }', $css);
        self::assertStringContainsString('.desktop-menu-popup button[aria-checked="true"] .desktop-menu-check', $css);
        self::assertStringContainsString('justify-content: space-between;', $css);
        self::assertStringContainsString('.draft-price-info-dialog {', $css);
        self::assertStringContainsString('width: min(460px, calc(100vw - 32px));', $css);
    }

    public function testAddCategoryCommandUsesEditMenuAndExistingDialog(): void
    {
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/src/admin_bootstrap.php');
        $renderer = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/DraftEditorPage.php');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin-draft.js');
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');

        self::assertLessThan(strpos($bootstrap, 'data-menu-edit>Редактировать'), strpos($bootstrap, '>Документ</button>'));
        self::assertLessThan(strpos($bootstrap, 'data-menu-view>Вид'), strpos($bootstrap, 'data-menu-edit>Редактировать'));
        self::assertLessThan(strpos($bootstrap, 'data-menu-price-info>Информация'), strpos($bootstrap, 'data-menu-view>Вид'));
        self::assertStringContainsString('aria-haspopup="menu" aria-expanded="false" aria-controls="desktop-edit-menu" data-menu-edit', $bootstrap);
        self::assertStringContainsString('id="desktop-edit-menu" class="desktop-menu-popup" role="menu"', $bootstrap);
        self::assertStringContainsString('role="menuitem" data-add-category>Добавить раздел', $bootstrap);
        self::assertSame(1, substr_count($bootstrap . $renderer, 'data-add-category'));
        self::assertStringContainsString('data-category-dialog', $renderer);
        self::assertStringContainsString("const addCategoryButton = document.querySelector('[data-add-category]');", $script);
        self::assertStringContainsString('setEditMenuOpen(false);', $script);
        self::assertStringContainsString('categoryDialog.showModal();', $script);
        self::assertStringContainsString("event.key === 'Escape' && !popup.hidden", $script);
        self::assertStringContainsString('if (!item.contains(event.target)) setOpen(false);', $script);
        self::assertStringContainsString("event.key === 'ArrowDown' || event.key === 'ArrowUp'", $script);
        self::assertStringContainsString('popup.querySelector(\'[role^="menuitem"]:not(:disabled)\')?.focus();', $script);
        self::assertStringContainsString('height: 26px;', $css);
        self::assertStringContainsString('.desktop-menu-bar button:focus-visible', $css);
    }

    public function testExcelDownloadCommandUsesDocumentMenuAndExistingVersionDialog(): void
    {
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/src/admin_bootstrap.php');
        $renderer = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/DraftEditorPage.php');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin-draft.js');
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');

        self::assertStringContainsString('aria-haspopup="menu" aria-expanded="false" aria-controls="desktop-document-menu" data-menu-document>Документ', $bootstrap);
        self::assertStringContainsString('id="desktop-document-menu" class="desktop-menu-popup" role="menu" aria-label="Документ" data-menu-document-popup hidden', $bootstrap);
        self::assertStringContainsString('role="menuitem" data-download-xlsx>Скачать Excel', $bootstrap);
        self::assertStringNotContainsString('data-download-xlsx', $renderer);
        self::assertStringContainsString('data-export-dialog', $renderer);
        self::assertStringContainsString('data-save-download', $renderer);
        self::assertStringContainsString('data-download-saved', $renderer);
        self::assertStringContainsString("const downloadButton = document.querySelector('[data-download-xlsx]');", $script);
        self::assertStringContainsString('setDocumentMenuOpen(false);', $script);
        self::assertStringContainsString('downloadButton.disabled = isSaving;', $script);
        self::assertStringContainsString('if (!hasValidUnsavedChanges && invalidCount === 0)', $script);
        self::assertStringContainsString('saveDownloadButton.disabled = invalidCount > 0;', $script);
        self::assertStringContainsString('exportDialog.showModal();', $script);
        self::assertStringNotContainsString('draft-download-action', $css);
    }

    public function testDocumentStatusLivesAtRightOfMenuAndKeepsExistingUpdates(): void
    {
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/src/admin_bootstrap.php');
        $renderer = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/DraftEditorPage.php');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin-draft.js');
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');

        self::assertStringContainsString('class="desktop-menu-status" data-status-container', $bootstrap);
        self::assertStringContainsString('data-draft-status role="button" tabindex="0" aria-describedby="desktop-status-tooltip" aria-expanded="false" hidden', $bootstrap);
        self::assertLessThan(strpos($bootstrap, 'class="desktop-menu-status"'), strpos($bootstrap, 'data-menu-price-info>Информация'));
        self::assertStringNotContainsString('data-draft-status', $renderer);
        self::assertStringNotContainsString('draft-sidebar-title', $renderer);
        self::assertStringContainsString("const draftStatus = document.querySelector('[data-draft-status]');", $script);
        self::assertStringContainsString("draftStatus.textContent = alreadyPublished ? 'Опубликован' : 'Черновик';", $script);
        self::assertStringContainsString("draftStatus.className = alreadyPublished ? 'status-published-badge' : 'status-draft';", $script);
        self::assertStringContainsString('draftStatus.hidden = false;', $script);
        self::assertMatchesRegularExpression('/\.desktop-menu-bar\s*\{[^}]*display:\s*flex;[^}]*align-items:\s*center;[^}]*height:\s*26px;/s', $css);
        self::assertMatchesRegularExpression('/\.desktop-menu-status\s*\{[^}]*margin-left:\s*auto;/s', $css);
        self::assertStringContainsString('.desktop-menu-bar > ul > li > button {', $css);
        self::assertStringContainsString('.desktop-menu-popup { position: fixed; top: 26px; left: 6px; }', $css);
    }

    public function testStatusExplanationsUseAccessibleTooltipAndLiveAnnouncements(): void
    {
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/src/admin_bootstrap.php');
        $renderer = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/DraftEditorPage.php');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin-draft.js');
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');

        self::assertStringContainsString('id="desktop-status-tooltip" data-status-tooltip role="tooltip" hidden', $bootstrap);
        self::assertStringContainsString('data-draft-state>Нет несохранённых изменений.', $bootstrap);
        self::assertStringContainsString('data-publication-state hidden>Текущий прайс уже опубликован на сайте.', $bootstrap);
        self::assertStringContainsString('data-status-live role="status" aria-live="polite"', $bootstrap);
        self::assertStringNotContainsString('data-draft-state', $renderer);
        self::assertStringNotContainsString('data-publication-state', $renderer);
        self::assertStringContainsString("state: document.querySelector('[data-draft-state]')", $script);
        self::assertStringContainsString("document.querySelector('[data-publication-state]')", $script);
        self::assertStringContainsString("statusContainer.addEventListener('pointerenter'", $script);
        self::assertStringContainsString("statusContainer.addEventListener('pointerleave'", $script);
        self::assertStringContainsString("draftStatus.addEventListener('focus'", $script);
        self::assertStringContainsString("draftStatus.addEventListener('blur'", $script);
        self::assertStringContainsString("draftStatus.addEventListener('click'", $script);
        self::assertStringContainsString("event.key !== 'Escape' || statusTooltip.hidden", $script);
        self::assertStringContainsString('visibleStatusMessages().map((message) => message.textContent.trim()).join(\' \')', $script);
        self::assertStringContainsString('if (statusLive.textContent !== statusAnnouncement) statusLive.textContent = statusAnnouncement;', $script);
        self::assertStringContainsString('.desktop-status-tooltip {', $css);
        self::assertStringContainsString('.desktop-status-tooltip > [hidden] { display: none; }', $css);
        self::assertStringContainsString('.desktop-status-live {', $css);
    }

    public function testPriceInfoDialogIsSeparateFromScrollableTable(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin.css');
        $renderer = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/DraftEditorPage.php');

        self::assertLessThan(strpos($renderer, '<div class="draft-table-wrap"'), strpos($renderer, '<dialog class="draft-price-info-dialog"'));
        self::assertStringContainsString('.draft-price-info-dialog::backdrop {', $css);
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
        self::assertStringContainsString('data-structure-menu-toggle="category" aria-label="Действия с разделом &lt;First&gt;" aria-haspopup="menu" aria-controls="draft-structure-action-menu" aria-expanded="false">⋯', $html);
        self::assertStringContainsString('data-structure-menu-toggle="service" aria-label="Действия с услугой &lt;Service one&gt;" aria-haspopup="menu" aria-controls="draft-structure-action-menu" aria-expanded="false">⋯', $html);
        self::assertStringNotContainsString('data-add-service>Добавить услугу', $html);
        self::assertStringNotContainsString('data-delete-category>Удалить раздел', $html);
        self::assertStringNotContainsString('data-delete-service', $html);
        self::assertStringNotContainsString('<th scope="col">ID</th>', $html);
        self::assertStringContainsString('data-save-prices disabled hidden', $html);
        self::assertStringContainsString('data-save-prices disabled hidden>Сохранить изменения</button>', $html);
        self::assertStringContainsString('data-reset-prices hidden>Отменить изменения</button>', $html);
        self::assertStringNotContainsString('data-reset-prices>Сбросить</button>', $html);
        self::assertStringContainsString('data-publish-prices', $html);
        self::assertStringContainsString('Опубликовать прайс', $html);
        self::assertStringNotContainsString('fa-solid fa-file-arrow-down draft-action-icon" aria-hidden="true"', $html);
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
        self::assertStringNotContainsString('draft-sidebar', $html);
        self::assertStringNotContainsString('draft-editor-layout', $html);
        self::assertStringContainsString('data-service-search-empty role="status" hidden>Услуги не найдены', $html);
        self::assertStringNotContainsString('data-changed-filter', $html);
        self::assertStringNotContainsString('draft-status-line', $html);
        self::assertStringNotContainsString('data-draft-state', $html);
        self::assertStringNotContainsString('draft-sidebar-title', $html);
        self::assertStringNotContainsString('data-draft-status', $html);
        self::assertStringNotContainsString('<dt>Статус</dt>', $html);
        self::assertStringContainsString('class="draft-price-info-dialog" aria-labelledby="draft-price-info-title" data-draft-price-info-dialog', $html);
        self::assertStringContainsString('data-draft-price-info-close>Закрыть</button>', $html);
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
        self::assertStringContainsString('<summary>Сводка изменений</summary>', $html);
        self::assertStringNotContainsString('class="draft-stat draft-stat-changed"', $html);
        self::assertStringContainsString('class="draft-stat draft-stat-increased"', $html);
        self::assertStringContainsString('class="draft-stat draft-stat-original"', $html);
        self::assertStringContainsString('class="draft-stat draft-stat-current"', $html);
        self::assertStringContainsString('class="draft-stat draft-stat-percent"', $html);
        self::assertStringNotContainsString('Изменений цен нет', $html);
        self::assertStringNotContainsString('data-summary-changed', $html);
        self::assertStringContainsString('<dt>Повышено</dt><span class="leader" aria-hidden="true"></span><dd class="change-positive" data-summary-increased>1</dd>', $html);
        self::assertStringNotContainsString('draft-stats-divider', $html);
        self::assertSame(1, substr_count($html, 'class="meta-label">Дата прайса:</span>'));
        self::assertLessThan(strpos($html, '<div class="draft-table-wrap"'), strpos($html, '<div class="draft-editor-controls">'));
        self::assertSame(1, substr_count($html, 'data-summary-section'));
        self::assertSame(1, substr_count($html, 'data-save-message'));
        self::assertSame(1, substr_count($html, 'data-reset-prices'));
        self::assertSame(1, substr_count($html, 'data-save-prices'));
        self::assertSame(1, substr_count($html, 'data-publish-prices'));
        self::assertStringNotContainsString('data-download-xlsx', $html);
        self::assertStringContainsString('data-save-download', $html);
        self::assertStringContainsString('data-download-saved', $html);
        self::assertStringContainsString('Скачать сохранённую версию', $html);
    }

    public function testChangedPriceFilterRemainsInViewMenuWithoutChangedPrices(): void
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

        self::assertStringNotContainsString('data-changed-filter', $html);
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/src/admin_bootstrap.php');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/admin-draft.js');
        self::assertStringContainsString('data-changed-filter-count>(0)</span>', $bootstrap);
        self::assertStringContainsString('changedFilter.disabled = changed === 0;', $script);
    }
}

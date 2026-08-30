<?php

declare(strict_types=1);

namespace Mcv26\Price\Admin;

use Mcv26\Price\Database\DatabasePriceRepository;
use RuntimeException;

final class DraftEditorPage
{
    public function __construct(private readonly DatabasePriceRepository $repository)
    {
    }

    /** @return array<string, mixed> */
    public function loadDraft(int $versionId): array
    {
        if ($versionId < 1) {
            throw new RuntimeException('Draft version ID must be a positive integer.');
        }
        $version = $this->repository->loadVersion($versionId);
        if ($version === null) {
            throw new RuntimeException('Draft version was not found.');
        }
        if (($version['status'] ?? null) !== 'draft') {
            throw new RuntimeException('Only draft versions can be edited.');
        }
        return $version;
    }

    /** @param array<string, mixed> $version */
    public static function render(array $version, string $csrfToken, ?int $publishedVersionId = null): string
    {
        if (($version['status'] ?? null) !== 'draft') {
            throw new RuntimeException('Only draft versions can be rendered as editable.');
        }

        $originalTotal = 0;
        $currentTotal = 0;
        $serviceCount = 0;
        $increasedCount = 0;
        $decreasedCount = 0;
        foreach ($version['categories'] as $category) {
            foreach ($category['services'] as $service) {
                $importedPrice = (int) $service['imported_price_minor'];
                $currentPrice = (int) $service['current_price_minor'];
                $originalTotal += $importedPrice;
                $currentTotal += $currentPrice;
                if ($currentPrice > $importedPrice) $increasedCount++;
                if ($currentPrice < $importedPrice) $decreasedCount++;
                $serviceCount++;
            }
        }
        $changedCount = $increasedCount + $decreasedCount;
        $isCurrentPublishedClone = $publishedVersionId !== null
            && (int) ($version['restored_from_version_id'] ?? 0) === $publishedVersionId;

        ob_start();
        ?>
        <div class="draft-editor"
             data-draft-editor
             data-version-id="<?= self::e($version['id']) ?>"
             data-revision="<?= self::e($version['revision']) ?>"
             data-published-version-id="<?= self::e($publishedVersionId ?? '') ?>"
             data-current-published-clone="<?= $isCurrentPublishedClone ? 'true' : 'false' ?>"
             data-csrf-token="<?= self::e($csrfToken) ?>">
            <dialog class="export-dialog" data-export-dialog>
                <form method="dialog">
                    <h2>Скачать Excel</h2>
                    <p data-export-dialog-text>В черновике есть несохранённые изменения.</p>
                    <div class="export-dialog-actions">
                        <button type="button" data-save-download>Сохранить и скачать</button>
                        <button type="button" class="button-secondary" data-download-saved>Скачать сохранённую версию</button>
                        <button type="submit" class="button-secondary">Отмена</button>
                    </div>
                </form>
            </dialog>

            <dialog class="publish-dialog" data-publish-dialog role="dialog" aria-modal="true" aria-labelledby="publish-dialog-title">
                <h2 id="publish-dialog-title">Опубликовать прайс?</h2>
                <p>Сохранённая версия станет доступна на публичной странице.</p>
                <p class="publish-dialog-message" data-publish-dialog-message role="alert" hidden></p>
                <div class="publish-dialog-actions">
                    <button type="button" class="button-secondary" data-publish-cancel autofocus>Отмена</button>
                    <button type="button" data-publish-confirm>Опубликовать</button>
                </div>
            </dialog>

            <div class="draft-editor-layout">
            <div class="draft-table-wrap">
                <table class="draft-table">
                    <thead>
                    <tr>
                        <th scope="col">№ услуги</th>
                        <th scope="col">Код услуги</th>
                        <th scope="col">Наименование услуги</th>
                        <th scope="col">Импортная цена</th>
                        <th scope="col">Текущая цена</th>
                        <th scope="col">Изменение, %</th>
                    </tr>
                    </thead>
                    <?php foreach ($version['categories'] as $category): ?>
                        <tbody class="draft-category">
                        <tr class="category-row">
                            <th colspan="6" scope="rowgroup"><?= self::e($category['name']) ?></th>
                        </tr>
                        <?php foreach ($category['services'] as $service):
                            $imported = (int) $service['imported_price_minor'];
                            $current = (int) $service['current_price_minor'];
                            ?>
                            <tr class="service-edit-row<?= $current === $imported ? ' price-unchanged' : '' ?>"
                                data-price-row
                                data-service-id="<?= self::e($service['id']) ?>"
                                data-imported-minor="<?= $imported ?>"
                                data-loaded-minor="<?= $current ?>">
                                <td class="service-number"><?= self::e($service['service_number']) ?></td>
                                <td class="service-code"><?= self::e($service['code']) ?></td>
                                <td class="service-name"><?= self::e($service['name']) ?></td>
                                <td class="money-cell"><?= self::money($imported) ?></td>
                                <td class="price-input-cell">
                                    <input type="text"
                                           class="price-input"
                                           inputmode="decimal"
                                           autocomplete="off"
                                           value="<?= self::decimal($current) ?>"
                                           aria-label="Текущая цена: <?= self::e($service['name']) ?>">
                                </td>
                                <td class="percent-cell" data-row-percent>0%</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    <?php endforeach; ?>
                </table>
                <p class="draft-search-empty" data-service-search-empty role="status" hidden>Услуги не найдены</p>
            </div>
            <aside class="draft-sidebar" aria-label="Сведения и действия с черновиком">
                <section class="draft-sidebar-section draft-about-accordion" data-draft-about-accordion>
                    <h2 class="draft-about-heading"><button type="button" class="draft-about-toggle" aria-expanded="false" aria-controls="draft-about-content" data-draft-about-toggle><span>О прайсе</span><span class="<?= $isCurrentPublishedClone && $changedCount === 0 ? 'status-published-badge' : 'status-draft' ?>" data-draft-status><?= $isCurrentPublishedClone && $changedCount === 0 ? 'Опубликован' : 'Черновик' ?></span><span class="draft-about-chevron" aria-hidden="true"></span></button></h2>
                    <dl id="draft-about-content" class="draft-sidebar-list draft-about-content" data-draft-about-content hidden>
                        <div><dt>Название</dt><dd><?= self::e($version['title']) ?></dd></div>
                        <div><dt>Версия</dt><dd>№<?= self::e($version['id']) ?></dd></div>
                        <div><dt>Дата прайса</dt><dd><?= self::e($version['price_date'] ?? 'Не указана') ?></dd></div>
                        <div><dt>Исходный файл</dt><dd><?= self::e($version['original_filename']) ?></dd></div>
                        <div><dt>Услуг</dt><dd><?= $serviceCount ?></dd></div>
                    </dl>
                </section>
                <section class="draft-sidebar-section" aria-label="Сводка изменений" data-summary-section<?= $changedCount === 0 ? ' hidden' : '' ?>>
                    <h2>Изменения</h2>
                    <dl class="draft-sidebar-list draft-change-list">
                        <div data-summary-changed-row<?= $changedCount === 0 ? ' hidden' : '' ?>><dt>Изменено</dt><dd data-summary-changed><?= $changedCount ?></dd></div>
                        <div data-summary-increased-row<?= $increasedCount === 0 ? ' hidden' : '' ?>><dt>Повышено</dt><dd class="change-positive" data-summary-increased><?= $increasedCount ?></dd></div>
                        <div data-summary-decreased-row<?= $decreasedCount === 0 ? ' hidden' : '' ?>><dt>Снижено</dt><dd class="change-negative" data-summary-decreased><?= $decreasedCount ?></dd></div>
                        <div><dt>Исходная сумма</dt><dd data-summary-original><?= self::money($originalTotal) ?></dd></div>
                        <div><dt>Текущая сумма</dt><dd data-summary-current><?= self::money($currentTotal) ?></dd></div>
                        <div data-summary-percent-row<?= $changedCount === 0 ? ' hidden' : '' ?>><dt>Общее изменение цен</dt><dd data-summary-percent>0%</dd></div>
                    </dl>
                </section>
                <div class="draft-status-line">
                    <p class="draft-state" data-draft-state aria-live="polite">Нет несохранённых изменений.</p>
                    <p class="draft-publication-state" data-publication-state<?= $isCurrentPublishedClone && $changedCount === 0 ? '' : ' hidden' ?>>Текущий прайс уже опубликован на сайте.</p>
                </div>
                <p class="draft-save-message" data-save-message role="status" aria-live="polite"></p>
                <div class="draft-actions">
                    <button type="button" class="button-secondary" data-reset-prices hidden>Отменить изменения</button>
                    <button type="button" class="button-secondary" data-download-xlsx>Скачать Excel</button>
                    <button type="button" class="button-secondary" data-save-prices disabled hidden>Сохранить</button>
                    <button type="button" data-publish-prices<?= $isCurrentPublishedClone && $changedCount === 0 ? ' hidden' : '' ?>>Опубликовать прайс</button>
                </div>
            </aside>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function money(int $minor): string
    {
        return number_format(intdiv($minor, 100), 0, ',', "\u{00A0}")
            . ($minor % 100 === 0 ? '' : ',' . sprintf('%02d', $minor % 100))
            . "\u{00A0}₽";
    }

    public static function decimal(int $minor): string
    {
        return (string) intdiv($minor, 100)
            . ($minor % 100 === 0 ? '' : ',' . sprintf('%02d', $minor % 100));
    }

    private static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

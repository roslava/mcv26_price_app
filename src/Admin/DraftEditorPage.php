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
            <p class="notice error" data-recovery-notice role="status" hidden></p>
            <dialog class="structure-dialog" data-recovery-dialog aria-labelledby="recovery-title">
                <h2 id="recovery-title">Восстановление изменений</h2>
                <p data-recovery-message></p>
                <div class="structure-dialog-actions">
                    <button type="button" class="button-secondary" data-recovery-discard>Удалить локальную копию</button>
                    <button type="button" data-recovery-restore>Восстановить изменения</button>
                </div>
            </dialog>
            <dialog class="structure-dialog" data-delete-dialog aria-labelledby="delete-dialog-title" aria-describedby="delete-dialog-description">
                <h2 id="delete-dialog-title" data-delete-title>Подтверждение удаления</h2>
                <p id="delete-dialog-description" data-delete-description></p>
                <div class="structure-dialog-actions">
                    <button type="button" class="button-secondary" data-delete-cancel autofocus>Отмена</button>
                    <button type="button" data-delete-confirm>Удалить</button>
                </div>
            </dialog>
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

            <dialog class="exit-dialog" data-exit-dialog role="dialog" aria-modal="true" aria-labelledby="exit-dialog-title" aria-describedby="exit-dialog-description exit-dialog-message">
                <h2 id="exit-dialog-title">Сохранить изменения перед выходом?</h2>
                <p id="exit-dialog-description">Выберите, что сделать с несохранёнными изменениями.</p>
                <p class="exit-dialog-message" id="exit-dialog-message" data-exit-dialog-message role="alert" hidden></p>
                <div class="exit-dialog-actions">
                    <button type="button" data-exit-save>Сохранить и выйти</button>
                    <button type="button" class="button-secondary" data-exit-discard>Выйти без сохранения</button>
                    <button type="button" class="button-secondary" data-exit-cancel autofocus>Отмена</button>
                </div>
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

            <dialog class="mass-price-entry-dialog" data-mass-price-entry-dialog role="dialog" aria-modal="true" aria-labelledby="mass-price-entry-title" aria-describedby="mass-price-entry-note">
                <form data-mass-price-form>
                    <h2 id="mass-price-entry-title">Изменить все цены на %</h2>
                    <label for="mass-price-percent">Процент изменения</label>
                    <input id="mass-price-percent" type="text" class="mass-price-percent-input" inputmode="decimal" autocomplete="off" aria-describedby="mass-price-entry-note mass-price-message" data-mass-price-percent autofocus>
                    <p id="mass-price-entry-note" class="mass-price-entry-note">Укажите положительный или отрицательный процент.</p>
                    <p class="mass-price-message" id="mass-price-message" data-mass-price-message role="alert" aria-live="polite"></p>
                    <div class="mass-price-entry-actions">
                        <button type="button" class="button-secondary" data-mass-price-entry-cancel>Отмена</button>
                        <button type="submit" data-mass-price-apply>Применить</button>
                    </div>
                </form>
            </dialog>

            <dialog class="mass-price-dialog" data-mass-price-dialog role="dialog" aria-modal="true" aria-labelledby="mass-price-dialog-title" aria-describedby="mass-price-dialog-message mass-price-dialog-note">
                <h2 id="mass-price-dialog-title">Изменить все цены?</h2>
                <p id="mass-price-dialog-message" class="mass-price-dialog-message" data-mass-price-dialog-message></p>
                <p id="mass-price-dialog-note" class="mass-price-dialog-note">Изменения можно будет отменить до сохранения.</p>
                <div class="mass-price-dialog-actions">
                    <button type="button" class="button-secondary" data-mass-price-cancel autofocus>Отмена</button>
                    <button type="button" data-mass-price-confirm>Продолжить</button>
                </div>
            </dialog>

            <dialog class="structure-dialog" data-service-dialog>
                <form method="dialog" data-service-form>
                    <h2>Добавить услугу</h2>
                    <input type="hidden" data-service-category-id>
                    <p class="structure-dialog-category" data-service-category-name></p>
                    <label>№ услуги<input type="number" min="1" step="1" required data-new-service-number></label>
                    <label>Код услуги<input type="text" maxlength="191" required data-new-service-code></label>
                    <label>Наименование услуги<input type="text" maxlength="1024" required data-new-service-name></label>
                    <label>Цена, ₽<input type="text" inputmode="decimal" required data-new-service-price></label>
                    <p class="structure-dialog-error" data-service-dialog-error role="alert"></p>
                    <div class="structure-dialog-actions">
                        <button type="button" class="button-secondary" data-service-cancel>Отмена</button>
                        <button type="submit">Добавить</button>
                    </div>
                </form>
            </dialog>

            <dialog class="structure-dialog" data-category-dialog>
                <form method="dialog" data-category-form>
                    <h2>Добавить раздел</h2>
                    <label>Название раздела<input type="text" maxlength="512" required data-new-category-name></label>
                    <fieldset>
                        <legend>Первая услуга</legend>
                        <label>№ услуги<input type="number" min="1" step="1" required data-new-category-service-number></label>
                        <label>Код услуги<input type="text" maxlength="191" required data-new-category-service-code></label>
                        <label>Наименование услуги<input type="text" maxlength="1024" required data-new-category-service-name></label>
                        <label>Цена, ₽<input type="text" inputmode="decimal" required data-new-category-service-price></label>
                    </fieldset>
                    <p class="structure-dialog-error" data-category-dialog-error role="alert"></p>
                    <div class="structure-dialog-actions">
                        <button type="button" class="button-secondary" data-category-cancel>Отмена</button>
                        <button type="submit">Добавить</button>
                    </div>
                </form>
            </dialog>

            <div class="draft-table-area">
                <dialog class="draft-price-info-dialog" aria-labelledby="draft-price-info-title" data-draft-price-info-dialog>
                    <h2 id="draft-price-info-title">Информация о прайсе</h2>
                    <div class="draft-price-info-row"><span class="meta-label">Название:</span><span class="meta-value"><?= self::e($version['title']) ?></span></div>
                    <div class="draft-price-info-row"><span class="meta-label">Версия:</span><span class="meta-value">№<?= self::e($version['id']) ?></span></div>
                    <div class="draft-price-info-row"><span class="meta-label">Дата прайса:</span><span class="meta-value"><?= self::e($version['price_date'] ?? 'Не указана') ?></span></div>
                    <div class="draft-price-info-row"><span class="meta-label">Исходный файл:</span><span class="meta-value"><?= self::e($version['original_filename']) ?></span></div>
                    <div class="draft-price-info-row"><span class="meta-label">Услуг:</span><span class="meta-value"><?= $serviceCount ?></span></div>
                    <div class="draft-price-info-actions">
                        <button type="button" data-draft-price-info-close>Закрыть</button>
                    </div>
                </dialog>
                <div class="draft-editor-controls">
                    <section class="draft-summary" aria-label="Сводка изменений" data-summary-section<?= $changedCount === 0 ? ' hidden' : '' ?>>
                        <details class="draft-summary-details"><summary>Сводка изменений</summary>
                        <dl class="draft-summary-list">
                            <div class="draft-stat draft-stat-increased" data-summary-increased-row<?= $increasedCount === 0 ? ' hidden' : '' ?>><dt>Повышено</dt><span class="leader" aria-hidden="true"></span><dd class="change-positive" data-summary-increased><?= $increasedCount ?></dd></div>
                            <div class="draft-stat draft-stat-decreased" data-summary-decreased-row<?= $decreasedCount === 0 ? ' hidden' : '' ?>><dt>Снижено</dt><span class="leader" aria-hidden="true"></span><dd class="change-negative" data-summary-decreased><?= $decreasedCount ?></dd></div>
                            <div class="draft-stat draft-stat-original"><dt>Исходная сумма</dt><span class="leader" aria-hidden="true"></span><dd data-summary-original><?= self::money($originalTotal) ?></dd></div>
                            <div class="draft-stat draft-stat-current"><dt>Текущая сумма</dt><span class="leader" aria-hidden="true"></span><dd data-summary-current><?= self::money($currentTotal) ?></dd></div>
                            <div class="draft-stat draft-stat-percent" data-summary-percent-row<?= $changedCount === 0 ? ' hidden' : '' ?>><dt>Общее изменение цен</dt><span class="leader" aria-hidden="true"></span><dd data-summary-percent>0%</dd></div>
                        </dl>
                        </details>
                    </section>
                    <div class="draft-actions">
                        <button type="button" class="button-secondary draft-reset-action" data-reset-prices hidden>Отменить изменения</button>
                        <button type="button" class="button-secondary draft-save-action" data-save-prices disabled hidden>Сохранить изменения</button>
                        <button type="button" data-publish-prices<?= $isCurrentPublishedClone && $changedCount === 0 ? ' hidden' : '' ?>>
                            <i class="fa-solid fa-globe draft-action-icon" aria-hidden="true"></i>
                            <span>Опубликовать прайс</span>
                        </button>
                    </div>
                    <p class="draft-save-message" data-save-message role="status" aria-live="polite"></p>
                </div>
                <div class="draft-table-wrap" tabindex="0" role="region" aria-label="Таблица услуг">
                    <table class="draft-table">
                    <thead>
                    <tr>
                        <th scope="col">№</th>
                        <th scope="col">Код</th>
                        <th scope="col">Наименование услуги</th>
                        <th scope="col">Начальная цена</th>
                        <th scope="col">Текущая цена</th>
                        <th scope="col">+/- %</th>
                        <th scope="col" aria-label="Действия"></th>
                    </tr>
                    </thead>
                    <?php foreach ($version['categories'] as $category): ?>
                        <tbody class="draft-category" data-category-id="<?= self::e((string) ($category['id'] ?? '')) ?>" data-category-name="<?= self::e($category['name']) ?>">
                        <tr class="category-row">
                            <th colspan="6" scope="rowgroup">
                                <span><?= self::e($category['name']) ?></span>
                            </th>
                            <td class="structure-action-cell"><button type="button" class="structure-action-toggle" data-structure-menu-toggle="category" aria-label="Действия с разделом <?= self::e($category['name']) ?>" aria-haspopup="menu" aria-controls="draft-structure-action-menu" aria-expanded="false">⋯</button></td>
                        </tr>
                        <?php foreach ($category['services'] as $service):
                            $imported = (int) $service['imported_price_minor'];
                            $current = (int) $service['current_price_minor'];
                            ?>
                            <tr class="service-edit-row <?= $current === $imported ? 'price-unchanged' : 'price-changed' ?>"
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
                                <td class="percent-cell">
                                    <input type="number"
                                           class="percent-input"
                                           step="any"
                                           autocomplete="off"
                                           value="0"
                                           data-row-percent
                                           aria-label="Изменение в процентах: <?= self::e($service['name']) ?>">
                                </td>
                                <td class="structure-action-cell">
                                    <button type="button" class="structure-action-toggle" data-structure-menu-toggle="service" aria-label="Действия с услугой <?= self::e($service['name']) ?>" aria-haspopup="menu" aria-controls="draft-structure-action-menu" aria-expanded="false">⋯</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    <?php endforeach; ?>
                    </table>
                    <p class="draft-search-empty" data-service-search-empty role="status" hidden>Услуги не найдены</p>
                </div>
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

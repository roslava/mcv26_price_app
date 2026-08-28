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
    public static function render(array $version, string $csrfToken): string
    {
        if (($version['status'] ?? null) !== 'draft') {
            throw new RuntimeException('Only draft versions can be rendered as editable.');
        }

        $originalTotal = 0;
        $currentTotal = 0;
        $serviceCount = 0;
        foreach ($version['categories'] as $category) {
            foreach ($category['services'] as $service) {
                $originalTotal += (int) $service['imported_price_minor'];
                $currentTotal += (int) $service['current_price_minor'];
                $serviceCount++;
            }
        }

        ob_start();
        ?>
        <div class="draft-editor"
             data-draft-editor
             data-version-id="<?= self::e($version['id']) ?>"
             data-revision="<?= self::e($version['revision']) ?>"
             data-csrf-token="<?= self::e($csrfToken) ?>">
            <div class="toolbar draft-toolbar">
                <div>
                    <p class="eyebrow">Черновик версии №<?= self::e($version['id']) ?></p>
                    <h1><?= self::e($version['title']) ?></h1>
                    <p class="muted">Изменяйте текущие цены и сохраняйте черновик без публикации.</p>
                </div>
                <a class="button-link button-secondary" href="/admin/">Назад</a>
            </div>

            <dl class="draft-metadata card">
                <div><dt>Дата прайса</dt><dd><?= self::e($version['price_date'] ?? 'Не указана') ?></dd></div>
                <div><dt>Исходный файл</dt><dd><?= self::e($version['original_filename']) ?></dd></div>
                <div><dt>Статус</dt><dd><span class="status-draft">Черновик</span></dd></div>
                <div><dt>Услуг</dt><dd><?= $serviceCount ?></dd></div>
            </dl>

            <section class="draft-summary" aria-label="Сводка изменений">
                <div><span>Дата прайса</span><strong><?= self::e($version['price_date'] ?? '—') ?></strong></div>
                <div><span>Изменено</span><strong data-summary-changed>0</strong></div>
                <div><span>Повышено</span><strong data-summary-increased>0</strong></div>
                <div><span>Снижено</span><strong data-summary-decreased>0</strong></div>
                <div><span>Исходная сумма</span><strong data-summary-original><?= self::money($originalTotal) ?></strong></div>
                <div><span>Текущая сумма</span><strong data-summary-current><?= self::money($currentTotal) ?></strong></div>
                <div><span>Общее изменение цен</span><strong data-summary-percent>0%</strong></div>
                <div class="draft-actions">
                    <button type="button" class="button-secondary" data-reset-prices>Сбросить</button>
                    <button type="button" data-save-prices disabled>Сохранить</button>
                </div>
                <p class="draft-state" data-draft-state aria-live="polite">Нет несохранённых изменений</p>
                <p class="draft-save-message" data-save-message role="status" aria-live="polite"></p>
            </section>

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

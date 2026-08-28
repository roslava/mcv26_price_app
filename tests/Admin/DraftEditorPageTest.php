<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests\Admin;

use Mcv26\Price\Admin\DraftEditorPage;
use PHPUnit\Framework\TestCase;

final class DraftEditorPageTest extends TestCase
{
    public function testFormatsMoneyFromIntegerMinorUnits(): void
    {
        self::assertSame("370\u{00A0}₽", DraftEditorPage::money(37000));
        self::assertSame("370,50\u{00A0}₽", DraftEditorPage::money(37050));
        self::assertSame('370', DraftEditorPage::decimal(37000));
        self::assertSame('370,50', DraftEditorPage::decimal(37050));
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
        ], 'csrf-token-value');

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
        self::assertStringContainsString('data-service-id="991"', $html);
        self::assertStringContainsString('data-service-id="992"', $html);
        self::assertStringNotContainsString('<th scope="col">ID</th>', $html);
        self::assertStringContainsString('data-save-prices disabled', $html);
    }
}

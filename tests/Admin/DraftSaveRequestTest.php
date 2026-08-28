<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests\Admin;

use Mcv26\Price\Admin\DraftSaveRequest;
use Mcv26\Price\Exception\DraftSaveException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DraftSaveRequestTest extends TestCase
{
    public function testParsesCompleteJsonContractWithoutNumericPrecisionLoss(): void
    {
        $request = DraftSaveRequest::parse('POST', 'application/json; charset=UTF-8', json_encode([
            'version_id' => '12',
            'expected_revision' => '4',
            'prices' => [['service_id' => '99', 'current_price_minor' => '37050']],
        ], JSON_THROW_ON_ERROR), true);

        self::assertSame(12, $request['version_id']);
        self::assertSame(4, $request['expected_revision']);
        self::assertSame('37050', $request['prices'][0]['current_price_minor']);
    }

    #[DataProvider('invalidRequests')]
    public function testRejectsInvalidRequest(string $method, string $contentType, string $body, bool $csrf): void
    {
        $this->expectException(DraftSaveException::class);
        DraftSaveRequest::parse($method, $contentType, $body, $csrf);
    }

    public static function invalidRequests(): iterable
    {
        $valid = '{"version_id":"1","expected_revision":"0","prices":[]}';
        yield 'non post' => ['GET', 'application/json', $valid, true];
        yield 'wrong content type' => ['POST', 'text/plain', $valid, true];
        yield 'missing csrf' => ['POST', 'application/json', $valid, false];
        yield 'malformed json' => ['POST', 'application/json', '{', true];
        yield 'invalid shape' => ['POST', 'application/json', '{}', true];
    }
}

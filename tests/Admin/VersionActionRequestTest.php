<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests\Admin;

use Mcv26\Price\Admin\VersionActionRequest;
use Mcv26\Price\Exception\VersionActionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionActionRequestTest extends TestCase
{
    public function testParsesAuthenticatedJsonShape(): void
    {
        self::assertSame(['version_id' => '12'], VersionActionRequest::parse(
            'POST', 'application/json; charset=UTF-8', '{"version_id":"12"}', true
        ));
        self::assertSame(12, VersionActionRequest::positiveInteger('12'));
        self::assertSame(0, VersionActionRequest::nonNegativeInteger('0'));
        self::assertNull(VersionActionRequest::nullablePositiveInteger(null));
    }

    #[DataProvider('invalidRequests')]
    public function testRejectsInvalidTransport(string $method, string $type, string $body, bool $csrf, string $code): void
    {
        try {
            VersionActionRequest::parse($method, $type, $body, $csrf);
            self::fail('Expected request rejection.');
        } catch (VersionActionException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }

    public static function invalidRequests(): array
    {
        return [
            ['GET', 'application/json', '{}', true, 'method_not_allowed'],
            ['POST', 'text/plain', '{}', true, 'invalid_content_type'],
            ['POST', 'application/json', '{}', false, 'csrf_failed'],
            ['POST', 'application/json', '{', true, 'malformed_json'],
            ['POST', 'application/json', '[]', true, 'invalid_request'],
        ];
    }

    public function testRejectsInvalidIntegers(): void
    {
        foreach ([null, 0, -1, '', '01', '1.0', []] as $value) {
            try {
                VersionActionRequest::positiveInteger($value);
                self::fail('Expected invalid integer rejection.');
            } catch (VersionActionException $exception) {
                self::assertSame('invalid_request', $exception->errorCode);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests;

use Mcv26\Price\AdminSession;
use PHPUnit\Framework\TestCase;

final class AdminSessionTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testFlashIsRemovedWhenItIsRead(): void
    {
        $session = new AdminSession();
        $flash = ['type' => 'error', 'message' => 'Черновик не найден или недоступен для редактирования.'];

        $session->setFlash($flash);

        self::assertSame($flash, $session->pullFlash());
        self::assertNull($session->pullFlash());
    }
}

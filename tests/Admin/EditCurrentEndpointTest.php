<?php

declare(strict_types=1);

namespace Mcv26\Price\Tests\Admin;

use PHPUnit\Framework\TestCase;

final class EditCurrentEndpointTest extends TestCase
{
    public function testEndpointIsAuthenticatedCsrfProtectedPostAndRedirectsToExistingEditor(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/edit-current.php');

        self::assertStringContainsString('$adminSession->isAuthenticated()', $source);
        self::assertStringContainsString("!== 'POST'", $source);
        self::assertStringContainsString("header('Allow: POST')", $source);
        self::assertStringContainsString('$adminSession->validateCsrf(', $source);
        self::assertStringContainsString('CurrentPublishedVersionEditorStarter', $source);
        self::assertStringContainsString("admin_redirect('/admin/draft.php?id='", $source);
    }
}

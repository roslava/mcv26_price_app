<?php

declare(strict_types=1);

namespace Mcv26\Price;

use RuntimeException;

final class AdminSession
{
    private const AUTH_KEY = 'mcv26_admin_authenticated';
    private const CSRF_KEY = 'mcv26_csrf_token';
    private const FLASH_KEY = 'mcv26_admin_flash';

    public static function start(): self
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_strict_mode', '1');
            session_name('mcv26_admin');
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/admin/',
                'secure' => self::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            if (!session_start()) {
                throw new RuntimeException('Не удалось запустить административную сессию.');
            }
        }

        return new self();
    }

    public function configurationError(): ?string
    {
        if ($this->configuredLogin() === null || $this->configuredPasswordHash() === null) {
            return 'Административный доступ не настроен. Обратитесь к администратору сайта.';
        }

        return null;
    }

    public function authenticate(string $login, string $password): bool
    {
        $configuredLogin = $this->configuredLogin();
        $passwordHash = $this->configuredPasswordHash();
        if ($configuredLogin === null || $passwordHash === null) {
            return false;
        }

        if (!hash_equals($configuredLogin, $login) || !password_verify($password, $passwordHash)) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION[self::AUTH_KEY] = true;
        return true;
    }

    public function isAuthenticated(): bool
    {
        return ($_SESSION[self::AUTH_KEY] ?? false) === true;
    }

    public function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }

        session_destroy();
    }

    public function csrfToken(): string
    {
        if (!isset($_SESSION[self::CSRF_KEY]) || !is_string($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::CSRF_KEY];
    }

    public function validateCsrf(mixed $submittedToken): bool
    {
        return is_string($submittedToken)
            && isset($_SESSION[self::CSRF_KEY])
            && is_string($_SESSION[self::CSRF_KEY])
            && hash_equals($_SESSION[self::CSRF_KEY], $submittedToken);
    }

    /** @param array<string, mixed> $flash */
    public function setFlash(array $flash): void
    {
        $_SESSION[self::FLASH_KEY] = $flash;
    }

    /** @return array<string, mixed>|null */
    public function pullFlash(): ?array
    {
        $flash = $_SESSION[self::FLASH_KEY] ?? null;
        unset($_SESSION[self::FLASH_KEY]);
        return is_array($flash) ? $flash : null;
    }

    private function configuredLogin(): ?string
    {
        $value = getenv('MCV26_ADMIN_LOGIN');
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function configuredPasswordHash(): ?string
    {
        $value = getenv('MCV26_ADMIN_PASSWORD_HASH');
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        return (is_string($https) && $https !== '' && strtolower($https) !== 'off')
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }
}

<?php

namespace App\Services;

class CsrfService
{
    private const SESSION_KEY = 'csrf_tokens';

    public function generate(string $formName): string
    {
        $this->startSession();
        $token = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_KEY][$formName] = $token;

        return $token;
    }

    public function validate(string $formName, ?string $token): bool
    {
        $this->startSession();
        $expected = $_SESSION[self::SESSION_KEY][$formName] ?? null;
        unset($_SESSION[self::SESSION_KEY][$formName]);

        return is_string($expected)
            && is_string($token)
            && hash_equals($expected, $token);
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_start();
    }
}

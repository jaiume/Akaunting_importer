<?php

declare(strict_types=1);

namespace App;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Slim builds cookie params from the Cookie header via getallheaders(); that is often
 * unavailable under nginx + PHP-FPM, leaving getCookieParams() empty while $_COOKIE is set.
 */
final class RequestCookies
{
    public static function get(ServerRequestInterface $request, string $name): ?string
    {
        $cookies = $request->getCookieParams();
        if (isset($cookies[$name]) && is_string($cookies[$name]) && $cookies[$name] !== '') {
            return $cookies[$name];
        }
        if (isset($_COOKIE[$name]) && is_string($_COOKIE[$name]) && $_COOKIE[$name] !== '') {
            return $_COOKIE[$name];
        }

        return null;
    }

    /**
     * Session value from AuthenticationService::verifyLoginTokenAndCreateSession (bin2hex(random_bytes(32))).
     * Rejects foreign cookies on a shared parent domain that reuse the same cookie name.
     */
    public static function getImporterSessionToken(ServerRequestInterface $request, string $cookieName): ?string
    {
        $raw = self::get($request, $cookieName);
        if ($raw === null) {
            return null;
        }
        if (strlen($raw) !== 64 || !ctype_xdigit($raw)) {
            return null;
        }

        return $raw;
    }
}

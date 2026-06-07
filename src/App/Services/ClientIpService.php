<?php

namespace App\Services;

use Psr\Http\Message\ServerRequestInterface as Request;

class ClientIpService
{
    public function getClientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();
        $remoteAddr = $this->normalizeIp((string)($serverParams['REMOTE_ADDR'] ?? ''));
        if ($remoteAddr === '') {
            return '0.0.0.0';
        }

        if (!$this->isTrustedProxy($remoteAddr)) {
            return $remoteAddr;
        }

        $forwardedFor = $request->getHeaderLine('X-Forwarded-For');
        foreach (explode(',', $forwardedFor) as $candidate) {
            $ip = $this->normalizeIp(trim($candidate));
            if ($ip !== '') {
                return $ip;
            }
        }

        return $remoteAddr;
    }

    private function normalizeIp(string $ip): string
    {
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    private function isTrustedProxy(string $remoteAddr): bool
    {
        $trusted = (string)ConfigService::get('auth.trusted_proxies', '');
        if (trim($trusted) === '') {
            return false;
        }

        foreach (explode(',', $trusted) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if ($entry === $remoteAddr || $this->matchesCidr($remoteAddr, $entry)) {
                return true;
            }
        }

        return false;
    }

    private function matchesCidr(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return false;
        }

        [$subnet, $maskBits] = explode('/', $cidr, 2);
        if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $maskBits = (int)$maskBits;
        if ($maskBits < 0 || $maskBits > 32) {
            return false;
        }

        $mask = $maskBits === 0 ? 0 : ~((1 << (32 - $maskBits)) - 1);
        return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
    }
}

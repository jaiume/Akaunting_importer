<?php

namespace App\Services;

use PDO;

class LoginThrottleService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function check(string $email, string $ipAddress): ?string
    {
        $this->cleanupOldAttempts();

        $email = $this->normalizeEmail($email);
        $emailCooldown = (int)ConfigService::get('auth.login_email_cooldown_seconds', 900);
        $emailMaxHourly = (int)ConfigService::get('auth.login_email_max_per_hour', 3);
        $ipMaxHourly = (int)ConfigService::get('auth.login_ip_max_per_hour', 10);
        $ipMaxDaily = (int)ConfigService::get('auth.login_ip_max_per_day', 50);

        if ($emailCooldown > 0 && $this->countAttempts('email', $email, $emailCooldown) > 0) {
            return 'email_cooldown';
        }
        if ($emailMaxHourly > 0 && $this->countAttempts('email', $email, 3600) >= $emailMaxHourly) {
            return 'email_hourly';
        }
        if ($ipMaxHourly > 0 && $this->countAttempts('ip_address', $ipAddress, 3600) >= $ipMaxHourly) {
            return 'ip_hourly';
        }
        if ($ipMaxDaily > 0 && $this->countAttempts('ip_address', $ipAddress, 86400) >= $ipMaxDaily) {
            return 'ip_daily';
        }

        return null;
    }

    public function record(string $email, string $ipAddress, bool $success, ?string $blockedReason = null): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO login_attempts (email, ip_address, success, blocked_reason)
            VALUES (:email, :ip_address, :success, :blocked_reason)
        ");
        $stmt->execute([
            'email' => $this->normalizeEmail($email),
            'ip_address' => $ipAddress,
            'success' => $success ? 1 : 0,
            'blocked_reason' => $blockedReason,
        ]);
    }

    private function countAttempts(string $column, string $value, int $seconds): int
    {
        if (!in_array($column, ['email', 'ip_address'], true)) {
            return 0;
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM login_attempts
            WHERE {$column} = :value
            AND created_at >= DATE_SUB(NOW(), INTERVAL :seconds SECOND)
        ");
        $stmt->execute([
            'value' => $value,
            'seconds' => $seconds,
        ]);

        return (int)$stmt->fetchColumn();
    }

    private function cleanupOldAttempts(): void
    {
        $retentionDays = (int)ConfigService::get('auth.login_attempt_retention_days', 7);
        if ($retentionDays < 1) {
            $retentionDays = 7;
        }

        $stmt = $this->db->prepare("
            DELETE FROM login_attempts
            WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)
        ");
        $stmt->execute(['days' => $retentionDays]);
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}

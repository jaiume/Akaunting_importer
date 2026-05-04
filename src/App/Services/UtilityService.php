<?php

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class UtilityService
{
    private $config;
    
    public function __construct(ConfigService $config)
    {
        $this->config = $config;
    }

    /**
     * Send email using SMTP
     */
    public function sendEmail(string $to, string $subject, string $body, bool $isHtml = true): bool
    {
        try {
            $mail = new PHPMailer(true);
            
            // Server settings
            $mail->isSMTP();
            $mail->Host = $this->config::get('mail.smtp_host');
            $mail->SMTPAuth = true;
            $mail->Username = $this->config::get('mail.smtp_user');
            $mail->Password = $this->config::get('mail.smtp_pass');
            
            // Set encryption type from config
            $encryption = $this->config::get('mail.smtp_encryption', 'tls');
            if (strtolower($encryption) === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif (strtolower($encryption) === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = false;
            }
            
            $mail->Port = $this->config::get('mail.smtp_port');
            
            // Allow self-signed certificates
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];

            // Recipients
            $mail->setFrom(
                $this->config::get('mail.from_email'),
                $this->config::get('mail.from_name')
            );
            $mail->addAddress($to);

            // Content
            $mail->isHTML($isHtml);
            $mail->Subject = $subject;
            $mail->Body = $body;

            return $mail->send();
        } catch (Exception $e) {
            // Log error here
            error_log('Email send error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get base URL of the application (honour reverse-proxy TLS/host headers).
     */
    public function getBaseUrl(): string
    {
        $forceHttps = $this->config::get('app.force_https_urls', false);
        if (is_string($forceHttps)) {
            $forceHttps = filter_var($forceHttps, FILTER_VALIDATE_BOOLEAN);
        }

        if ($forceHttps) {
            $protocol = 'https://';
        } else {
            $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
            if (is_string($forwardedProto) && $forwardedProto !== '') {
                $first = trim(strtolower(explode(',', $forwardedProto, 2)[0]));
                $protocol = $first === 'https' ? 'https://' : 'http://';
            } else {
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
            }
        }

        $forwardedHost = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '';
        if (is_string($forwardedHost) && $forwardedHost !== '') {
            $host = trim(explode(',', $forwardedHost, 2)[0]);
        } else {
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        }

        $baseDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $baseDir = str_replace('/public', '', $baseDir);

        return rtrim($protocol . $host . $baseDir, '/') . '/';
    }

    /**
     * Whether the current request is served over HTTPS (direct or via reverse proxy).
     */
    public static function isHttpsRequest(): bool
    {
        if (!empty($_SERVER['REQUEST_SCHEME']) && strtolower((string)$_SERVER['REQUEST_SCHEME']) === 'https') {
            return true;
        }

        $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (is_string($forwardedProto) && $forwardedProto !== '') {
            return strtolower(trim(explode(',', $forwardedProto, 2)[0])) === 'https';
        }

        if (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] === '1')) {
            return true;
        }

        return false;
    }
}


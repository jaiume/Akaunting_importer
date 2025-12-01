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
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $this->config::get('mail.smtp_port');

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
            return false;
        }
    }

    /**
     * Get base URL of the application
     */
    public function getBaseUrl(): string
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        $baseDir = dirname($_SERVER['SCRIPT_NAME']);
        
        // Remove "/public" from the base URL if it exists
        $baseDir = str_replace('/public', '', $baseDir);
        
        // Ensure there's exactly one trailing slash
        return rtrim($protocol . $host . $baseDir, '/') . '/';
    }
}


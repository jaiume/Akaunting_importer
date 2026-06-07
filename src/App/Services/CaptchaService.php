<?php

namespace App\Services;

class CaptchaService
{
    private const SESSION_KEY = 'login_slider_captcha';

    public function generate(): array
    {
        $this->startSession();
        $this->removeExpiredChallenges();

        $width = (int)ConfigService::get('auth.captcha_width', 320);
        $height = (int)ConfigService::get('auth.captcha_height', 90);
        $pieceSize = (int)ConfigService::get('auth.captcha_piece_size', 42);
        $expiresSeconds = (int)ConfigService::get('auth.captcha_expires_seconds', 300);

        $width = max(240, min($width, 480));
        $height = max(70, min($height, 160));
        $pieceSize = max(32, min($pieceSize, 64));
        $maxPosition = $width - $pieceSize - 12;
        $targetX = random_int(60, max(61, $maxPosition));
        $targetY = random_int(14, max(15, $height - $pieceSize - 14));
        $challengeId = bin2hex(random_bytes(16));

        $_SESSION[self::SESSION_KEY][$challengeId] = [
            'x' => $targetX,
            'created_at' => time(),
        ];

        return [
            'id' => $challengeId,
            'image_data' => $this->buildImageData($width, $height, $pieceSize, $targetX, $targetY),
            'max_position' => $maxPosition,
            'piece_size' => $pieceSize,
            'target_y' => $targetY,
            'expires_seconds' => $expiresSeconds,
        ];
    }

    public function validate(?string $challengeId, $position): bool
    {
        $this->startSession();

        $challengeId = is_string($challengeId) ? $challengeId : '';
        if ($challengeId === '' || !isset($_SESSION[self::SESSION_KEY][$challengeId])) {
            return false;
        }

        $challenge = $_SESSION[self::SESSION_KEY][$challengeId];
        unset($_SESSION[self::SESSION_KEY][$challengeId]);

        $expiresSeconds = (int)ConfigService::get('auth.captcha_expires_seconds', 300);
        $tolerance = (int)ConfigService::get('auth.captcha_tolerance_pixels', 8);
        if ($expiresSeconds < 30) {
            $expiresSeconds = 300;
        }
        if ((time() - (int)$challenge['created_at']) > $expiresSeconds) {
            return false;
        }
        if (!is_numeric($position)) {
            return false;
        }

        return abs((int)$position - (int)$challenge['x']) <= max(2, $tolerance);
    }

    private function buildImageData(int $width, int $height, int $pieceSize, int $targetX, int $targetY): string
    {
        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 238, 242, 247);
        $grid = imagecolorallocate($image, 210, 218, 230);
        $target = imagecolorallocatealpha($image, 79, 70, 229, 70);
        $outline = imagecolorallocate($image, 79, 70, 229);
        $noise = imagecolorallocatealpha($image, 99, 102, 241, 80);
        $text = imagecolorallocate($image, 71, 85, 105);

        imagefilledrectangle($image, 0, 0, $width, $height, $background);
        for ($x = 0; $x < $width; $x += 20) {
            imageline($image, $x, 0, $x, $height, $grid);
        }
        for ($y = 0; $y < $height; $y += 20) {
            imageline($image, 0, $y, $width, $y, $grid);
        }
        for ($i = 0; $i < 22; $i++) {
            imagefilledellipse($image, random_int(0, $width), random_int(0, $height), random_int(4, 12), random_int(4, 12), $noise);
        }

        imagefilledrectangle($image, $targetX, $targetY, $targetX + $pieceSize, $targetY + $pieceSize, $target);
        imagerectangle($image, $targetX, $targetY, $targetX + $pieceSize, $targetY + $pieceSize, $outline);
        imagestring($image, 3, 12, $height - 22, 'Slide the block to the highlighted square', $text);

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,' . base64_encode($png ?: '');
    }

    private function removeExpiredChallenges(): void
    {
        $expiresSeconds = (int)ConfigService::get('auth.captcha_expires_seconds', 300);
        $challenges = $_SESSION[self::SESSION_KEY] ?? [];
        foreach ($challenges as $id => $challenge) {
            if ((time() - (int)($challenge['created_at'] ?? 0)) > $expiresSeconds) {
                unset($_SESSION[self::SESSION_KEY][$id]);
            }
        }
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_start();
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Exception\PdfExtractFailedException;

/**
 * HTTP client for the standalone pdftotext microservice (JSON contract).
 */
final class PdfExtractClient
{
    /**
     * @param array{layout?: bool, max_pages?: int|null} $options
     * @throws PdfExtractFailedException
     */
    public function extractFromFile(string $filePath, array $options = []): string
    {
        if (!is_readable($filePath)) {
            throw new PdfExtractFailedException('PDF file is not readable for extraction.');
        }

        $baseUrl = trim((string)ConfigService::get('pdf_extract.base_url', ''));
        $apiKey = trim((string)ConfigService::get('pdf_extract.api_key', ''));

        if ($baseUrl === '' || $apiKey === '') {
            throw new PdfExtractFailedException(
                'PDF extract service is not configured. Set [pdf_extract] base_url and api_key in config.ini.'
            );
        }

        $timeout = (int)ConfigService::get('pdf_extract.timeout', 120);
        if ($timeout < 5) {
            $timeout = 5;
        }

        $verifyTls = ConfigService::get('pdf_extract.verify_tls', true);
        if (is_string($verifyTls)) {
            $verifyTls = filter_var($verifyTls, FILTER_VALIDATE_BOOLEAN);
        }

        $url = rtrim($baseUrl, '/') . '/v1/extract';

        $layout = !empty($options['layout']);
        $maxPages = $options['max_pages'] ?? null;

        $postFields = [
            'file' => new \CURLFile($filePath, 'application/pdf', basename($filePath)),
            'layout' => $layout ? '1' : '0',
        ];
        if ($maxPages !== null && $maxPages > 0) {
            $postFields['max_pages'] = (string)(int)$maxPages;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_CONNECTTIMEOUT => min(30, $timeout),
            CURLOPT_TIMEOUT => $timeout,
        ]);

        if (!$verifyTls) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $caBundle = ConfigService::get('pdf_extract.ca_bundle', '');
        if (is_string($caBundle) && $caBundle !== '' && $verifyTls) {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new PdfExtractFailedException('PDF extract request failed: ' . $err);
        }

        if ($raw === false) {
            throw new PdfExtractFailedException('PDF extract request returned empty response.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new PdfExtractFailedException('PDF extract response was not valid JSON.');
        }

        if (($decoded['success'] ?? false) !== true) {
            $message = $decoded['error']['message'] ?? 'PDF extraction failed.';
            $code = $decoded['error']['code'] ?? '';
            throw new PdfExtractFailedException(
                $code !== '' ? ($message . ' (' . $code . ')') : $message,
                $httpCode
            );
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new PdfExtractFailedException('PDF extract service returned HTTP ' . $httpCode);
        }

        $text = $decoded['data']['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new PdfExtractFailedException('PDF extract service returned no text.');
        }

        return $text;
    }
}

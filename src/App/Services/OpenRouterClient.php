<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Sends a receipt PDF to OpenRouter and returns one structured receipt per page entry.
 * The PDF is passed as a base64 file part so the model can read scans and handwriting.
 */
final class OpenRouterClient
{
    private const VENDOR_PROMPT_LIMIT = 300;

    /**
     * @param list<string> $vendorNames Known Akaunting vendor names. Omitted from the prompt when longer than 300.
     * @return list<array{page:int,date:?string,vendor:?string,vendor_match:?string,amount:?float,currency:?string,notes:?string}>
     */
    public function extractReceipts(string $filePath, array $vendorNames = []): array
    {
        $apiKey = trim((string)ConfigService::get('openrouter.api_key', ''));
        if ($apiKey === '') {
            throw new \RuntimeException('OpenRouter is not configured. Set [openrouter] api_key in config.ini.');
        }

        if (!is_readable($filePath)) {
            throw new \RuntimeException('Receipt PDF is not readable.');
        }

        $bytes = file_get_contents($filePath);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('Receipt PDF is empty.');
        }

        $previousMemory = ini_get('memory_limit');
        ini_set('memory_limit', '512M');

        $timeout = (int)ConfigService::get('openrouter.timeout', 180);
        if ($timeout < 30) {
            $timeout = 30;
        }
        set_time_limit($timeout + 30);

        try {
            $dataUrl = 'data:application/pdf;base64,' . base64_encode($bytes);
            unset($bytes);

            $payload = $this->buildPayload($dataUrl, basename($filePath), $vendorNames);
            unset($dataUrl);

            try {
                $content = $this->postChat($payload, $apiKey, $timeout);
            } catch (\RuntimeException $e) {
                if (!$this->isResponseFormatRejection($e)) {
                    throw $e;
                }
                unset($payload['response_format']);
                $content = $this->postChat($payload, $apiKey, $timeout);
            }

            return $this->parseContent($content);
        } finally {
            if (is_string($previousMemory) && $previousMemory !== '') {
                ini_set('memory_limit', $previousMemory);
            }
        }
    }

    /**
     * Read one rendered page, or a cropped area of a page.
     *
     * @param list<string> $vendorNames
     * @return list<array{page:int,date:?string,vendor:?string,vendor_match:?string,amount:?float,currency:?string,notes:?string}>
     */
    public function extractFromImage(string $dataUrl, int $page, array $vendorNames = []): array
    {
        $apiKey = trim((string)ConfigService::get('openrouter.api_key', ''));
        if ($apiKey === '') {
            throw new \RuntimeException('OpenRouter is not configured. Set [openrouter] api_key in config.ini.');
        }
        if ($page < 1) {
            throw new \RuntimeException('A page number is required.');
        }

        $timeout = (int)ConfigService::get('openrouter.timeout', 180);
        if ($timeout < 30) {
            $timeout = 30;
        }
        set_time_limit($timeout + 30);

        $payload = $this->buildImagePayload($dataUrl, $page, $vendorNames);
        try {
            $content = $this->postChat($payload, $apiKey, $timeout);
        } catch (\RuntimeException $e) {
            if (!$this->isResponseFormatRejection($e)) {
                throw $e;
            }
            unset($payload['response_format']);
            $content = $this->postChat($payload, $apiKey, $timeout);
        }

        return $this->parseContent($content, $page);
    }

    /**
     * @param list<string> $vendorNames
     * @return array<string, mixed>
     */
    private function buildPayload(string $dataUrl, string $filename, array $vendorNames): array
    {
        $model = trim((string)ConfigService::get('openrouter.model', 'google/gemini-3.8-flash'));
        if ($model === '') {
            $model = 'google/gemini-3.8-flash';
        }
        $engine = trim((string)ConfigService::get('openrouter.pdf_engine', 'native'));
        if ($engine === '') {
            $engine = 'native';
        }

        $names = [];
        foreach ($vendorNames as $name) {
            $name = trim((string)$name);
            if ($name !== '') {
                $names[$name] = true;
            }
        }
        $names = array_keys($names);
        $includeVendors = count($names) > 0 && count($names) <= self::VENDOR_PROMPT_LIMIT;

        $prompt = <<<'PROMPT'
Extract every receipt from this PDF. A page may contain no receipts, one receipt, or several. Handwritten and scanned pages count. Read the ink, do not assume a text layer.

Return JSON only, shaped as {"receipts":[...]} . One object per receipt. Do not invent values. Use null when a field cannot be read.

Fields:
- page: 1-based page number where that receipt appears. Several receipts may share a page.
- date: YYYY-MM-DD, or null. When a numeric date is ambiguous, treat it as day-first (DD/MM).
- vendor: the business name as written on the page, or null.
- vendor_match: a name copied exactly from the known vendor list when it is clearly the same business, otherwise null. Never invent a name that is not in the list.
- amount: the amount paid, as a number. Not tax and not the subtotal. Use a negative number only for a refund. Null when unreadable.
- currency: ISO currency code, or null. Do not guess USD from a bare dollar sign.
- notes: a short transcription of the receipt, or null.

Include a receipt with null fields when a page looks like a receipt but cannot be read. Omit pages that are blank or are not receipts.
PROMPT;

        if ($includeVendors) {
            $prompt .= "\n\nKnown vendors:\n- " . implode("\n- ", $names);
        } else {
            $prompt .= "\n\nNo known vendor list is attached. Leave vendor_match null.";
        }

        return $this->withSchema([
            'model' => $model,
            'temperature' => 0,
            'max_tokens' => 16384,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        [
                            'type' => 'file',
                            'file' => [
                                'filename' => $filename,
                                'file_data' => $dataUrl,
                            ],
                        ],
                    ],
                ],
            ],
            'plugins' => [
                [
                    'id' => 'file-parser',
                    'pdf' => ['engine' => $engine],
                ],
            ],
        ]);
    }

    /**
     * @param list<string> $vendorNames
     * @return array<string, mixed>
     */
    private function buildImagePayload(string $dataUrl, int $page, array $vendorNames): array
    {
        $model = trim((string)ConfigService::get('openrouter.model', 'google/gemini-3.8-flash'));
        if ($model === '') {
            $model = 'google/gemini-3.8-flash';
        }

        $prompt = "This image is page {$page} of a receipt PDF, or a selected area of that page. "
            . "It may contain one receipt or several, including handwriting. "
            . "Extract every receipt you can see. Set page to {$page} on every receipt. Do not invent values. Use null when a field cannot be read.\n\n"
            . "Return JSON only, shaped as {\"receipts\":[...]}. One object per receipt.\n"
            . "Fields: date (YYYY-MM-DD or null; ambiguous numeric dates are day-first DD/MM), "
            . "vendor (the business name as written, or null), "
            . "vendor_match (a name copied exactly from the known vendor list when it is clearly the same business, otherwise null), "
            . "amount (the amount paid, not tax or subtotal; negative only for a refund; null if unreadable), "
            . "currency (ISO code, or null; do not guess USD from a bare dollar sign), "
            . "notes (a short transcription, or null).";
        $prompt .= $this->vendorPrompt($vendorNames);

        return $this->withSchema([
            'model' => $model,
            'temperature' => 0,
            'max_tokens' => 4096,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        [
                            'type' => 'image_url',
                            'image_url' => ['url' => $dataUrl],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param list<string> $vendorNames
     */
    private function vendorPrompt(array $vendorNames): string
    {
        $names = [];
        foreach ($vendorNames as $name) {
            $name = trim((string)$name);
            if ($name !== '') {
                $names[$name] = true;
            }
        }
        $names = array_keys($names);
        if ($names !== [] && count($names) <= self::VENDOR_PROMPT_LIMIT) {
            return "\n\nKnown vendors:\n- " . implode("\n- ", $names);
        }
        return "\n\nNo known vendor list is attached. Leave vendor_match null.";
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withSchema(array $payload): array
    {
        $payload['response_format'] = [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'receipts',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['receipts'],
                    'properties' => [
                        'receipts' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['page', 'date', 'vendor', 'vendor_match', 'amount', 'currency', 'notes'],
                                'properties' => [
                                    'page' => ['type' => 'integer'],
                                    'date' => ['type' => ['string', 'null']],
                                    'vendor' => ['type' => ['string', 'null']],
                                    'vendor_match' => ['type' => ['string', 'null']],
                                    'amount' => ['type' => ['number', 'null']],
                                    'currency' => ['type' => ['string', 'null']],
                                    'notes' => ['type' => ['string', 'null']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postChat(array $payload, string $apiKey, int $timeout): string
    {
        $baseUrl = rtrim((string)ConfigService::get('openrouter.base_url', 'https://openrouter.ai/api/v1'), '/');
        $url = $baseUrl . '/chat/completions';

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new \RuntimeException('Could not encode the OpenRouter request.');
        }
        unset($payload);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'X-Title: Akaunting Importer',
            ],
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        unset($body);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException('OpenRouter request failed: ' . $err);
        }
        if (!is_string($raw) || $raw === '') {
            throw new \RuntimeException('OpenRouter returned an empty response.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('OpenRouter response was not valid JSON.');
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = $decoded['error']['message'] ?? ('OpenRouter HTTP ' . $httpCode);
            throw new \RuntimeException($message);
        }

        $choice = $decoded['choices'][0] ?? null;
        if (!is_array($choice)) {
            throw new \RuntimeException('OpenRouter returned no completion.');
        }

        $finish = (string)($choice['finish_reason'] ?? '');
        if ($finish === 'length') {
            throw new \RuntimeException('OpenRouter response was truncated before every page was returned.');
        }

        $content = $choice['message']['content'] ?? '';
        if (is_array($content)) {
            $text = '';
            foreach ($content as $part) {
                if (is_string($part)) {
                    $text .= $part;
                } elseif (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $text .= $part['text'];
                }
            }
            $content = $text;
        }
        if (!is_string($content) || trim($content) === '') {
            throw new \RuntimeException('OpenRouter returned no receipt JSON.');
        }

        return $content;
    }

    private function isResponseFormatRejection(\RuntimeException $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'response_format')
            || str_contains($message, 'json_schema')
            || str_contains($message, 'structured');
    }

    /**
     * @return list<array{page:int,date:?string,vendor:?string,vendor_match:?string,amount:?float,currency:?string,notes:?string}>
     */
    public function parseContent(string $content, ?int $defaultPage = null): array
    {
        $decoded = $this->decodeJsonPayload($content);
        $rows = $decoded['receipts'] ?? $decoded;
        if (!is_array($rows) || $this->isAssoc($rows)) {
            throw new \RuntimeException('OpenRouter receipt JSON did not contain a receipts list.');
        }

        $receipts = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $page = (int)($row['page'] ?? 0);
            if ($page < 1) {
                $page = $defaultPage ?? 0;
            }
            if ($page < 1) {
                continue;
            }
            $receipts[] = [
                'page' => $page,
                'date' => $this->normalizeDate($row['date'] ?? null),
                'vendor' => $this->normalizeText($row['vendor'] ?? null, 500),
                'vendor_match' => $this->normalizeText($row['vendor_match'] ?? null, 255),
                'amount' => $this->normalizeAmount($row['amount'] ?? null),
                'currency' => $this->normalizeCurrency($row['currency'] ?? null),
                'notes' => $this->normalizeText($row['notes'] ?? null, 4000),
            ];
        }

        return $receipts;
    }

    /**
     * @return array<mixed>
     */
    private function decodeJsonPayload(string $content): array
    {
        $trimmed = trim($content);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $trimmed, $m)) {
            $trimmed = trim($m[1]);
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            $start = strpos($trimmed, '{');
            $end = strrpos($trimmed, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($trimmed, $start, $end - $start + 1), true);
            }
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('OpenRouter receipt JSON could not be parsed.');
        }

        return $decoded;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'];
        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($dt instanceof \DateTimeImmutable && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $dt->format('Y-m-d');
            }
        }

        return null;
    }

    private function normalizeAmount(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return round((float)$value, 2);
        }
        if (!is_string($value)) {
            return null;
        }
        $clean = str_replace([',', ' '], '', trim($value));
        if ($clean === '' || !is_numeric($clean)) {
            return null;
        }
        return round((float)$clean, 2);
    }

    private function normalizeCurrency(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtoupper(trim($value));
        if (preg_match('/^[A-Z]{3}$/', $value) !== 1) {
            return null;
        }
        return $value;
    }

    private function normalizeText(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if ($value === '') {
            return null;
        }
        if (strlen($value) > $max) {
            $value = substr($value, 0, $max);
        }
        return $value;
    }

    /**
     * @param array<mixed> $value
     */
    private function isAssoc(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }
}

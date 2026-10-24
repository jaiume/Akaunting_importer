<?php

namespace App\Services;

use App\DAO\AccountDAO;
use App\DAO\InstallationDAO;
use App\DAO\ReceiptBatchDAO;
use App\DAO\ReceiptItemDAO;
use App\DAO\VendorDAO;

/**
 * Imports multipage receipt PDFs, matches vendors, and pushes expenses to Akaunting.
 */
class ReceiptService
{
    private const MAX_BYTES = 20971520;
    private const CACHE_MAX_AGE = 86400;

    private ReceiptBatchDAO $batchDAO;
    private ReceiptItemDAO $itemDAO;
    private AccountDAO $accountDAO;
    private InstallationDAO $installationDAO;
    private InstallationService $installationService;
    private VendorDAO $vendorDAO;
    private OpenRouterClient $openRouter;
    private PdfPageCounter $pageCounter;
    private string $storageDir;

    public function __construct(
        ReceiptBatchDAO $batchDAO,
        ReceiptItemDAO $itemDAO,
        AccountDAO $accountDAO,
        InstallationDAO $installationDAO,
        InstallationService $installationService,
        VendorDAO $vendorDAO,
        OpenRouterClient $openRouter,
        PdfPageCounter $pageCounter,
        string $storageDir
    ) {
        $this->batchDAO = $batchDAO;
        $this->itemDAO = $itemDAO;
        $this->accountDAO = $accountDAO;
        $this->installationDAO = $installationDAO;
        $this->installationService = $installationService;
        $this->vendorDAO = $vendorDAO;
        $this->openRouter = $openRouter;
        $this->pageCounter = $pageCounter;
        $this->storageDir = $storageDir;
    }

    /**
     * Save the PDF and open it for page-by-page reading.
     */
    public function import(int $userId, string $batchName, int $accountId, $uploadedFile): int
    {
        $batchName = trim($batchName);
        if ($batchName === '') {
            throw new \InvalidArgumentException('Batch name is required.');
        }
        if ($accountId < 1 || !$this->accountDAO->findById($accountId)) {
            throw new \InvalidArgumentException('Account not found.');
        }
        $this->assertUpload($uploadedFile);

        $stored = $this->storeUpload($uploadedFile);
        $pageCount = $this->pageCounter->count($this->storageDir . '/' . $stored['filename']);
        if ($pageCount !== null && $pageCount > 150) {
            $pageCount = null;
        }

        return $this->batchDAO->create([
            'batch_name' => $batchName,
            'account_id' => $accountId,
            'stored_filename' => $stored['filename'],
            'original_filename' => $stored['original'],
            'user_id' => $userId,
            'status' => 'completed',
            'page_count' => $pageCount,
        ]);
    }

    /**
     * Accept one page image and return a job id. The model runs after the response is sent.
     */
    public function startImageAnalysis(int $batchId, int $userId, int $page, string $dataUrl): string
    {
        $batch = $this->requireBatch($batchId, $userId);
        if ($page < 1) {
            throw new \InvalidArgumentException('Choose a page first.');
        }
        if (!empty($batch['page_count']) && $page > (int)$batch['page_count']) {
            throw new \InvalidArgumentException('That page is not in this PDF.');
        }

        $dataUrl = $this->normalizeImageDataUrl($dataUrl);
        $jobId = bin2hex(random_bytes(16));
        $this->writeAnalysisJob($batchId, $jobId, [
            'status' => 'running',
            'page' => $page,
            'item_id' => null,
            'count' => 0,
            'message' => '',
            'started_at' => time(),
        ]);

        DeferredWork::afterResponse(function () use ($batch, $batchId, $userId, $page, $dataUrl, $jobId): void {
            try {
                $ids = $this->analyzePreparedImage($batch, $userId, $page, $dataUrl);
                $count = count($ids);
                $this->writeAnalysisJob($batchId, $jobId, [
                    'status' => 'done',
                    'page' => $page,
                    'item_id' => $ids[0] ?? null,
                    'count' => $count,
                    'message' => $count === 1 ? 'Draft receipt created.' : $count . ' draft receipts created.',
                    'started_at' => time(),
                ]);
            } catch (\Throwable $e) {
                error_log('Receipt analyze error: ' . $e->getMessage());
                $this->writeAnalysisJob($batchId, $jobId, [
                    'status' => 'error',
                    'page' => $page,
                    'item_id' => null,
                    'count' => 0,
                    'message' => $e->getMessage(),
                    'started_at' => time(),
                ]);
            }
        });

        return $jobId;
    }

    /**
     * @return array{status:string,page:int,item_id:?int,count:int,message:string}
     */
    public function imageAnalysisStatus(int $batchId, int $userId, string $jobId): array
    {
        $this->requireBatch($batchId, $userId);
        $path = $this->analysisJobPath($batchId, $jobId);
        if (!is_file($path)) {
            throw new \RuntimeException('That read could not be found.');
        }
        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['status'])) {
            throw new \RuntimeException('That read could not be found.');
        }
        if ($data['status'] === 'running' && time() - (int)($data['started_at'] ?? 0) > 300) {
            $data['status'] = 'error';
            $data['message'] = 'The read did not finish. Try that page again.';
        }

        return [
            'status' => (string)$data['status'],
            'page' => (int)($data['page'] ?? 0),
            'item_id' => isset($data['item_id']) ? (int)$data['item_id'] : null,
            'count' => (int)($data['count'] ?? 0),
            'message' => (string)($data['message'] ?? ''),
        ];
    }

    /**
     * @return list<int>
     */
    private function analyzePreparedImage(array $batch, int $userId, int $page, string $dataUrl): array
    {
        $ref = $this->referenceData($batch, $userId);
        $names = array_map(static fn(array $vendor): string => (string)$vendor['name'], $ref['vendors']);
        $receipts = $this->openRouter->extractFromImage($dataUrl, $page, $names);
        if ($receipts === []) {
            $receipts[] = [
                'page' => $page,
                'date' => null,
                'vendor' => null,
                'vendor_match' => null,
                'amount' => null,
                'currency' => null,
                'notes' => 'Nothing could be read from this selection.',
            ];
        }

        return $this->insertReceipts($batch, $receipts, $ref, $page);
    }

    public function retry(int $batchId, int $userId): void
    {
        $batch = $this->requireBatch($batchId, $userId);
        $this->batchDAO->updateStatus($batchId, 'processing', null, null);
        $this->extractIntoBatch((int)$batch['receipt_batch_id'], $userId, true);
    }

    public function listForUser(int $userId, bool $includeArchived = false): array
    {
        return $this->batchDAO->findByUser($userId, $includeArchived);
    }

    public function requireBatch(int $batchId, int $userId): array
    {
        $batch = $this->batchDAO->findByIdAndUser($batchId, $userId);
        if (!$batch) {
            throw new \RuntimeException('Batch not found');
        }
        return $batch;
    }

    public function itemsForBatch(int $batchId): array
    {
        return $this->itemDAO->findByBatch($batchId);
    }

    public function absolutePath(array $batch): string
    {
        $name = basename((string)$batch['stored_filename']);
        if ($name === '' || $name !== $batch['stored_filename']) {
            throw new \RuntimeException('Stored receipt filename is invalid.');
        }
        $path = $this->storageDir . '/' . $name;
        if (!is_file($path)) {
            throw new \RuntimeException('Receipt PDF is missing.');
        }
        return $path;
    }

    /**
     * Vendors, expense categories, and payment methods for the batch entity.
     * Missing installation returns empty lists so extraction can still run.
     */
    public function referenceData(array $batch, int $userId): array
    {
        $empty = [
            'installation' => null,
            'vendors' => [],
            'categories' => [],
            'payment_methods' => [],
        ];

        $installation = $this->installationDAO->findByEntityId((int)$batch['entity_id']);
        if (!$installation || !(int)($installation['is_active'] ?? 0)) {
            return $empty;
        }

        $installationId = (int)$installation['installation_id'];
        $this->refreshCaches($installationId, $userId);

        return [
            'installation' => $installation,
            'vendors' => $this->vendorDAO->getContactsByInstallation($installationId, 'vendor'),
            'categories' => $this->vendorDAO->getCategoriesByInstallation($installationId, 'expense'),
            'payment_methods' => $this->vendorDAO->getPaymentMethodsByInstallation($installationId),
        ];
    }

    public function suggest(array $batch, int $userId, string $vendorName): array
    {
        $ref = $this->referenceData($batch, $userId);
        if (!$ref['installation']) {
            return ['category_id' => null, 'category_name' => null, 'payment_method' => null];
        }
        return $this->suggestionFor((int)$ref['installation']['installation_id'], $vendorName, $ref);
    }

    public function saveItem(int $batchId, int $itemId, int $userId, array $input): array
    {
        $batch = $this->requireBatch($batchId, $userId);
        $item = $this->requireItem($batchId, $itemId);
        if ($item['status'] === 'pushed') {
            throw new \InvalidArgumentException('This receipt has already been pushed.');
        }

        $ref = $this->referenceData($batch, $userId);
        $fields = $this->editableFields($input, $ref);
        $fields['status'] = 'pending';
        $this->itemDAO->updateEditable($itemId, $fields);
        return $this->requireItem($batchId, $itemId);
    }

    public function skipItem(int $batchId, int $itemId, int $userId): void
    {
        $this->requireBatch($batchId, $userId);
        $item = $this->requireItem($batchId, $itemId);
        if ($item['status'] === 'pushed') {
            throw new \InvalidArgumentException('A pushed receipt cannot be skipped.');
        }
        if ($item['status'] === 'skipped') {
            return;
        }
        $this->itemDAO->markSkipped($itemId);
    }

    public function pushItem(int $batchId, int $itemId, int $userId, array $input): array
    {
        if ($input !== []) {
            $this->saveItem($batchId, $itemId, $userId, $input);
        }

        $batch = $this->requireBatch($batchId, $userId);
        $item = $this->requireItem($batchId, $itemId);
        $ref = $this->referenceData($batch, $userId);

        if (!$ref['installation']) {
            throw new \InvalidArgumentException('This entity has no Akaunting installation. Push is unavailable.');
        }
        if (empty($batch['akaunting_account_id'])) {
            throw new \InvalidArgumentException('This account is not linked to an Akaunting account.');
        }
        if ($item['status'] === 'pushed') {
            throw new \InvalidArgumentException('This receipt has already been pushed.');
        }
        if ($item['status'] === 'skipped') {
            throw new \InvalidArgumentException('Skipped pages are not pushed.');
        }

        $item = $this->requireItem($batchId, $itemId);
        $this->assertPushable($item);

        $installation = $ref['installation'];
        $password = $this->installationService->decryptPassword($installation['api_password']);
        $companyId = $installation['company_id'] ?? 1;
        $timeout = (int)ConfigService::get('akaunting.api_timeout', 60);
        $transactionNumber = 'IMP-RCP-' . $itemId;
        $description = $this->descriptionFor($item);
        $currency = strtoupper((string)($batch['currency'] ?: 'TTD'));

        $transactionData = [
            'type' => 'expense',
            'number' => $transactionNumber,
            'account_id' => (int)$batch['akaunting_account_id'],
            'paid_at' => $item['receipt_date'] . ' 00:00:00',
            'amount' => (float)$item['amount'],
            'currency_code' => $currency,
            'currency_rate' => 1.0,
            'description' => $description,
            'reference' => $batch['batch_name'],
            'category_id' => (int)$item['category_id'],
            'payment_method' => $item['payment_method'],
        ];
        if (!empty($item['matched_contact_id'])) {
            $transactionData['contact_id'] = (int)$item['matched_contact_id'];
        } elseif ($description !== '') {
            $transactionData['contact_name'] = $description;
        }

        $url = rtrim($installation['base_url'], '/') . '/api/transactions';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($transactionData),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'User-Agent: AkauntingImporter/1.0',
                'X-Company: ' . $companyId,
            ],
            CURLOPT_USERPWD => $installation['api_email'] . ':' . $password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->itemDAO->setError($itemId, $error);
            throw new \RuntimeException('Akaunting request failed: ' . $error);
        }

        $responseData = is_string($response) ? json_decode($response, true) : null;
        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMsg = is_array($responseData) ? ($responseData['message'] ?? "HTTP $httpCode") : "HTTP $httpCode";
            if (is_array($responseData) && isset($responseData['errors'])) {
                $errorMsg .= ': ' . json_encode($responseData['errors']);
            }
            $this->itemDAO->setError($itemId, $errorMsg);
            throw new \RuntimeException('Akaunting API error: ' . $errorMsg);
        }

        $this->itemDAO->markPushed($itemId, $transactionNumber);

        $historyName = trim((string)($item['matched_contact_name'] ?: $item['vendor_raw'] ?: ''));
        if ($historyName !== '') {
            try {
                $this->vendorDAO->saveTransactionMapping(
                    (int)$installation['installation_id'],
                    $historyName,
                    'expense',
                    !empty($item['matched_contact_id']) ? (int)$item['matched_contact_id'] : null,
                    $historyName,
                    (int)$item['category_id'],
                    $item['category_name'] ?: null,
                    $item['payment_method'],
                    null
                );
            } catch (\Throwable $e) {
                error_log('Failed to save receipt category history: ' . $e->getMessage());
            }
        }

        return [
            'success' => true,
            'transaction_number' => $transactionNumber,
            'akaunting_id' => is_array($responseData) ? ($responseData['data']['id'] ?? null) : null,
        ];
    }

    public function deleteBatch(int $batchId, int $userId): void
    {
        $batch = $this->requireBatch($batchId, $userId);
        $this->deleteStoredFile($batch);
        $this->batchDAO->delete($batchId);
    }

    public function archiveBatch(int $batchId, int $userId): void
    {
        $this->requireBatch($batchId, $userId);
        $this->batchDAO->archive($batchId);
    }

    public function unarchiveBatch(int $batchId, int $userId): void
    {
        $this->requireBatch($batchId, $userId);
        $this->batchDAO->unarchive($batchId);
    }

    private function extractIntoBatch(int $batchId, int $userId, bool $replaceUnpushed): void
    {
        $batch = $this->batchDAO->findById($batchId);
        if (!$batch) {
            return;
        }

        try {
            set_time_limit(0);
            $path = $this->absolutePath($batch);
            $pageCount = $this->pageCounter->count($path);
            if ($pageCount !== null && $pageCount > 150) {
                error_log('Ignoring implausible receipt page count ' . $pageCount . ' for batch ' . $batchId);
                $pageCount = null;
            }
            $ref = $this->referenceData($batch, $userId);
            $names = array_map(static fn(array $vendor): string => (string)$vendor['name'], $ref['vendors']);
            $receipts = $this->openRouter->extractReceipts($path, $names);

            $this->itemDAO->transaction(function () use ($batchId, $batch, $receipts, $ref, $replaceUnpushed, $pageCount) {
                if ($replaceUnpushed) {
                    $this->itemDAO->deleteUnpushed($batchId);
                }
                $this->insertReceipts($batch, $receipts, $ref);
                $this->fillMissingPages($batchId, $pageCount);
            });

            $this->batchDAO->updateStatus($batchId, 'completed', null, $pageCount);
        } catch (\Throwable $e) {
            error_log('Receipt extract failed for batch ' . $batchId . ': ' . $e->getMessage());
            $this->batchDAO->updateStatus($batchId, 'failed', $this->publicError($e), null);
        }
    }

    /**
     * @param list<array<string, mixed>> $receipts
     * @param array<string, mixed> $ref
     * @return list<int>
     */
    private function insertReceipts(array $batch, array $receipts, array $ref, ?int $forcePage = null): array
    {
        $pushed = [];
        foreach ($this->itemDAO->findByBatch((int)$batch['receipt_batch_id']) as $existing) {
            if ($existing['status'] === 'pushed') {
                $pushed[] = $existing;
            }
        }

        $ids = [];
        $installationId = $ref['installation'] ? (int)$ref['installation']['installation_id'] : null;
        foreach ($receipts as $receipt) {
            if ($forcePage !== null) {
                $receipt['page'] = $forcePage;
            }
            if ($forcePage === null && $this->duplicatesPushed($pushed, $receipt)) {
                continue;
            }

            $match = $this->matchVendor($receipt['vendor_match'], $receipt['vendor'], $ref['vendors']);
            $vendorForHistory = $match['name'] ?? $receipt['vendor'];
            $suggestion = $installationId && $vendorForHistory
                ? $this->suggestionFor($installationId, $vendorForHistory, $ref)
                : ['category_id' => null, 'category_name' => null, 'payment_method' => null];

            $ids[] = $this->itemDAO->create([
                'receipt_batch_id' => (int)$batch['receipt_batch_id'],
                'page_number' => $receipt['page'],
                'receipt_date' => $receipt['date'],
                'vendor_raw' => $receipt['vendor'],
                'amount' => $receipt['amount'],
                'detected_currency' => $receipt['currency'],
                'model_notes' => $receipt['notes'],
                'matched_contact_id' => $match['id'] ?? null,
                'matched_contact_name' => $match['name'] ?? null,
                'match_confidence' => $match['confidence'] ?? null,
                'suggested_category_id' => $suggestion['category_id'],
                'suggested_category_name' => $suggestion['category_name'],
                'category_id' => $suggestion['category_id'],
                'category_name' => $suggestion['category_name'],
                'payment_method' => $suggestion['payment_method'],
                'status' => 'pending',
            ]);
        }
        return $ids;
    }

    private function fillMissingPages(int $batchId, ?int $pageCount): void
    {
        if ($pageCount === null || $pageCount < 1) {
            return;
        }
        $present = array_flip($this->itemDAO->pagesPresent($batchId));
        for ($page = 1; $page <= $pageCount; $page++) {
            if (isset($present[$page])) {
                continue;
            }
            $this->itemDAO->create([
                'receipt_batch_id' => $batchId,
                'page_number' => $page,
                'status' => 'pending',
            ]);
        }
    }

    /**
     * @param list<array<string, mixed>> $pushed
     * @param array<string, mixed> $receipt
     */
    private function duplicatesPushed(array $pushed, array $receipt): bool
    {
        foreach ($pushed as $item) {
            if ((int)$item['page_number'] !== (int)$receipt['page']) {
                continue;
            }
            $sameAmount = $item['amount'] !== null && $receipt['amount'] !== null
                && abs((float)$item['amount'] - (float)$receipt['amount']) < 0.001;
            $sameDate = $item['receipt_date'] && $receipt['date'] && $item['receipt_date'] === $receipt['date'];
            $sameVendor = $this->norm((string)($item['vendor_raw'] ?? '')) !== ''
                && $this->norm((string)($item['vendor_raw'] ?? '')) === $this->norm((string)($receipt['vendor'] ?? ''));
            if ($sameAmount || ($sameDate && $sameVendor)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array<string, mixed>> $vendors
     * @return array{id:int,name:string,confidence:string}|null
     */
    private function matchVendor(?string $vendorMatch, ?string $vendorRaw, array $vendors): ?array
    {
        if ($vendors === []) {
            return null;
        }

        $index = [];
        foreach ($vendors as $vendor) {
            $index[$this->norm((string)$vendor['name'])] = $vendor;
        }

        if ($vendorMatch) {
            $key = $this->norm($vendorMatch);
            if ($key !== '' && isset($index[$key])) {
                return $this->matchResult($index[$key], 'high');
            }
        }

        if (!$vendorRaw) {
            return null;
        }
        $rawKey = $this->norm($vendorRaw);
        if ($rawKey === '') {
            return null;
        }
        if (isset($index[$rawKey])) {
            return $this->matchResult($index[$rawKey], 'high');
        }

        $best = null;
        $bestScore = 0.0;
        foreach ($vendors as $vendor) {
            $name = $this->norm((string)$vendor['name']);
            if ($name === '') {
                continue;
            }
            similar_text($rawKey, $name, $percent);
            if ($percent > $bestScore) {
                $bestScore = $percent;
                $best = $vendor;
            }
        }
        if ($best && $bestScore >= 85) {
            return $this->matchResult($best, 'medium');
        }
        if ($best && $bestScore >= 70) {
            return $this->matchResult($best, 'low');
        }
        return null;
    }

    /**
     * @param array<string, mixed> $vendor
     * @return array{id:int,name:string,confidence:string}
     */
    private function matchResult(array $vendor, string $confidence): array
    {
        return [
            'id' => (int)$vendor['akaunting_contact_id'],
            'name' => (string)$vendor['name'],
            'confidence' => $confidence,
        ];
    }

    /**
     * @param array<string, mixed> $ref
     * @return array{category_id:?int,category_name:?string,payment_method:?string}
     */
    private function suggestionFor(int $installationId, string $vendorName, array $ref): array
    {
        $empty = ['category_id' => null, 'category_name' => null, 'payment_method' => null];
        $vendorName = trim($vendorName);
        if ($vendorName === '') {
            return $empty;
        }

        $mapping = $this->vendorDAO->findBestTransactionMapping($installationId, $vendorName);
        if (!$mapping || ($mapping['transaction_type'] ?? null) === 'income') {
            return $empty;
        }

        $categoryId = !empty($mapping['akaunting_category_id']) ? (int)$mapping['akaunting_category_id'] : null;
        $categoryName = $mapping['category_name'] ?: null;
        if ($categoryId && !$this->categoryById($ref['categories'], $categoryId)) {
            $categoryId = null;
            $categoryName = null;
        }

        $payment = $mapping['payment_method'] ?: null;
        if ($payment && !$this->paymentByCode($ref['payment_methods'], $payment)) {
            $payment = null;
        }

        return [
            'category_id' => $categoryId,
            'category_name' => $categoryName,
            'payment_method' => $payment,
        ];
    }

    /**
     * @param array<string, mixed> $ref
     * @return array<string, mixed>
     */
    private function editableFields(array $input, array $ref): array
    {
        $date = trim((string)($input['receipt_date'] ?? ''));
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException('Date must be YYYY-MM-DD.');
        }
        if ($date !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            $errors = \DateTimeImmutable::getLastErrors();
            if (!$parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                throw new \InvalidArgumentException('Date is not a real calendar date.');
            }
        }

        $amountRaw = trim((string)($input['amount'] ?? ''));
        $amount = null;
        if ($amountRaw !== '') {
            if (!is_numeric($amountRaw)) {
                throw new \InvalidArgumentException('Amount must be a number.');
            }
            $amount = round((float)$amountRaw, 2);
        }

        $contactId = (int)($input['contact_id'] ?? 0);
        $vendorName = trim((string)($input['vendor_name'] ?? ''));
        $matchedId = null;
        $matchedName = null;
        $confidence = null;
        if ($contactId > 0) {
            $contact = $this->vendorById($ref['vendors'], $contactId);
            if (!$contact) {
                throw new \InvalidArgumentException('Selected vendor is not in this installation.');
            }
            $matchedId = (int)$contact['akaunting_contact_id'];
            $matchedName = (string)$contact['name'];
            $confidence = 'high';
            if ($vendorName === '') {
                $vendorName = $matchedName;
            }
        }

        $categoryId = (int)($input['category_id'] ?? 0);
        $categoryName = null;
        if ($categoryId > 0) {
            $category = $this->categoryById($ref['categories'], $categoryId);
            if (!$category) {
                throw new \InvalidArgumentException('Selected category is not an expense category.');
            }
            $categoryName = (string)$category['name'];
        } else {
            $categoryId = 0;
        }

        $payment = trim((string)($input['payment_method'] ?? ''));
        if ($payment !== '' && !$this->paymentByCode($ref['payment_methods'], $payment)) {
            throw new \InvalidArgumentException('Selected payment method is not available.');
        }

        return [
            'receipt_date' => $date !== '' ? $date : null,
            'vendor_raw' => $vendorName !== '' ? mb_substr($vendorName, 0, 500) : null,
            'amount' => $amount,
            'matched_contact_id' => $matchedId,
            'matched_contact_name' => $matchedName,
            'match_confidence' => $confidence,
            'category_id' => $categoryId > 0 ? $categoryId : null,
            'category_name' => $categoryName,
            'payment_method' => $payment !== '' ? $payment : null,
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function assertPushable(array $item): void
    {
        if (empty($item['receipt_date'])) {
            throw new \InvalidArgumentException('A date is required before pushing.');
        }
        if ($item['amount'] === null || $item['amount'] === '') {
            throw new \InvalidArgumentException('An amount is required before pushing.');
        }
        if ((float)$item['amount'] <= 0) {
            throw new \InvalidArgumentException('Only a positive amount can be pushed. A refund stays here for review.');
        }
        if (empty($item['category_id'])) {
            throw new \InvalidArgumentException('An expense category is required before pushing.');
        }
        if (empty($item['payment_method'])) {
            throw new \InvalidArgumentException('A payment method is required before pushing.');
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function descriptionFor(array $item): string
    {
        $name = trim((string)($item['matched_contact_name'] ?: $item['vendor_raw'] ?: ''));
        return $name !== '' ? $name : 'Receipt';
    }

    private function refreshCaches(int $installationId, int $userId): void
    {
        $this->refreshOne(
            $this->vendorDAO->getLastCacheTime($installationId, 'vendor'),
            function () use ($installationId, $userId) {
                $contacts = $this->installationService->fetchAkauntingContacts($installationId, $userId, 'vendor');
                $this->vendorDAO->clearContactsCache($installationId, 'vendor');
                $this->vendorDAO->cacheContacts($installationId, $contacts);
            }
        );
        $this->refreshOne(
            $this->vendorDAO->getLastCategoryCacheTime($installationId),
            function () use ($installationId, $userId) {
                $categories = $this->installationService->fetchAkauntingCategories($installationId, $userId);
                $this->vendorDAO->clearCategoriesCache($installationId);
                $this->vendorDAO->cacheCategories($installationId, $categories);
            }
        );
        $this->refreshOne(
            $this->vendorDAO->getLastPaymentMethodCacheTime($installationId),
            function () use ($installationId, $userId) {
                $methods = $this->installationService->fetchAkauntingPaymentMethods($installationId, $userId);
                $this->vendorDAO->clearPaymentMethodsCache($installationId);
                $this->vendorDAO->cachePaymentMethods($installationId, $methods);
            }
        );
    }

    private function refreshOne(?string $lastCached, callable $refresh): void
    {
        $age = $lastCached ? (time() - strtotime($lastCached)) : PHP_INT_MAX;
        if ($age <= self::CACHE_MAX_AGE) {
            return;
        }
        try {
            $refresh();
        } catch (\Throwable $e) {
            error_log('Receipt reference refresh failed: ' . $e->getMessage());
        }
    }

    /**
     * @param list<array<string, mixed>> $vendors
     */
    private function vendorById(array $vendors, int $contactId): ?array
    {
        foreach ($vendors as $vendor) {
            if ((int)$vendor['akaunting_contact_id'] === $contactId) {
                return $vendor;
            }
        }
        return null;
    }

    /**
     * @param list<array<string, mixed>> $categories
     */
    private function categoryById(array $categories, int $categoryId): ?array
    {
        foreach ($categories as $category) {
            if ((int)$category['akaunting_category_id'] === $categoryId && ($category['type'] ?? 'expense') === 'expense') {
                return $category;
            }
        }
        return null;
    }

    /**
     * @param list<array<string, mixed>> $methods
     */
    private function paymentByCode(array $methods, string $code): ?array
    {
        foreach ($methods as $method) {
            if ((string)$method['code'] === $code) {
                return $method;
            }
        }
        return null;
    }

    private function requireItem(int $batchId, int $itemId): array
    {
        $item = $this->itemDAO->findById($itemId);
        if (!$item || (int)$item['receipt_batch_id'] !== $batchId) {
            throw new \RuntimeException('Receipt not found');
        }
        return $item;
    }

    /**
     * @param array{status:string,page:int,item_id:?int,count:int,message:string,started_at:int} $job
     */
    private function writeAnalysisJob(int $batchId, string $jobId, array $job): void
    {
        $path = $this->analysisJobPath($batchId, $jobId);
        $encoded = json_encode($job);
        if ($encoded === false || file_put_contents($path, $encoded, LOCK_EX) === false) {
            throw new \RuntimeException('Could not store the read status.');
        }
    }

    private function analysisJobPath(int $batchId, string $jobId): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
            throw new \InvalidArgumentException('Unknown read.');
        }
        $dir = $this->storageDir . '/jobs';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not store the read status.');
        }
        foreach (glob($dir . '/*.json') ?: [] as $old) {
            if (is_file($old) && filemtime($old) !== false && filemtime($old) < time() - 86400) {
                unlink($old);
            }
        }

        return $dir . '/' . $batchId . '-' . $jobId . '.json';
    }

    private function normalizeImageDataUrl(string $dataUrl): string
    {
        if (!preg_match('#^data:image/(jpeg|jpg|png);base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $matches)) {
            throw new \InvalidArgumentException('The selection must be a JPEG or PNG image.');
        }
        $raw = base64_decode(preg_replace('/\s+/', '', $matches[2]) ?? '', true);
        if ($raw === false || $raw === '') {
            throw new \InvalidArgumentException('The selection image could not be read.');
        }
        if (strlen($raw) > 8 * 1024 * 1024) {
            throw new \InvalidArgumentException('The selection image is too large. Try a smaller area.');
        }
        $type = $matches[1] === 'png' ? 'png' : 'jpeg';
        if ($type === 'jpeg' && !str_starts_with($raw, "\xFF\xD8")) {
            throw new \InvalidArgumentException('The selection image is not a JPEG.');
        }
        if ($type === 'png' && !str_starts_with($raw, "\x89PNG")) {
            throw new \InvalidArgumentException('The selection image is not a PNG.');
        }
        return 'data:image/' . $type . ';base64,' . base64_encode($raw);
    }

    private function assertUpload($uploadedFile): void
    {
        if (!$uploadedFile || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('File upload failed.');
        }
        $size = (int)$uploadedFile->getSize();
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new \InvalidArgumentException('PDF must be between 1 byte and 20MB.');
        }
        $name = (string)$uploadedFile->getClientFilename();
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            throw new \InvalidArgumentException('Only PDF files can be imported as receipts.');
        }
    }

    /**
     * @return array{filename:string,original:string}
     */
    private function storeUpload($uploadedFile): array
    {
        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0750, true) && !is_dir($this->storageDir)) {
            throw new \RuntimeException('Could not create the receipt storage directory.');
        }
        $this->assertStorageOutsideWebRoot();

        $original = basename((string)$uploadedFile->getClientFilename());
        $filename = time() . '_' . bin2hex(random_bytes(8)) . '.pdf';
        $path = $this->storageDir . '/' . $filename;
        $uploadedFile->moveTo($path);

        $header = file_get_contents($path, false, null, 0, 4);
        if ($header !== '%PDF') {
            @unlink($path);
            throw new \InvalidArgumentException('The uploaded file is not a PDF.');
        }

        return ['filename' => $filename, 'original' => $original];
    }

    private function assertStorageOutsideWebRoot(): void
    {
        $storageReal = realpath($this->storageDir);
        $baseReal = realpath(BASE_DIR);
        if ($storageReal === false || $baseReal === false) {
            throw new \RuntimeException('Receipt storage path could not be resolved.');
        }
        if ($storageReal === $baseReal || str_starts_with($storageReal, $baseReal . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Receipt storage must be outside the web root.');
        }
    }

    private function deleteStoredFile(array $batch): void
    {
        try {
            $path = $this->absolutePath($batch);
        } catch (\Throwable $e) {
            return;
        }
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function publicError(\Throwable $e): string
    {
        $message = trim($e->getMessage());
        if ($message === '') {
            return 'Receipt extraction failed.';
        }
        if (strlen($message) > 1000) {
            $message = substr($message, 0, 1000);
        }
        return $message;
    }

    private function norm(string $value): string
    {
        $value = mb_strtoupper(trim($value));
        $value = preg_replace('/[^A-Z0-9 ]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return trim($value);
    }
}

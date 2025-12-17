<?php

namespace App\Services;

use App\DAO\BatchDAO;
use App\DAO\TransactionDAO;
use App\DAO\AccountDAO;
use App\DAO\InstallationDAO;
use App\DAO\MatchJobDAO;
use App\DAO\OrphanTransactionDAO;

class TransactionMatchingService
{
    private BatchDAO $batchDAO;
    private TransactionDAO $transactionDAO;
    private AccountDAO $accountDAO;
    private InstallationDAO $installationDAO;
    private InstallationService $installationService;
    private MatchJobDAO $matchJobDAO;
    private ?OrphanTransactionDAO $orphanDAO;
    private int $matchingWindowDays;

    public function __construct(
        BatchDAO $batchDAO,
        TransactionDAO $transactionDAO,
        AccountDAO $accountDAO,
        InstallationDAO $installationDAO,
        InstallationService $installationService,
        MatchJobDAO $matchJobDAO,
        int $matchingWindowDays = 5,
        ?OrphanTransactionDAO $orphanDAO = null
    ) {
        $this->batchDAO = $batchDAO;
        $this->transactionDAO = $transactionDAO;
        $this->accountDAO = $accountDAO;
        $this->installationDAO = $installationDAO;
        $this->installationService = $installationService;
        $this->matchJobDAO = $matchJobDAO;
        $this->matchingWindowDays = $matchingWindowDays;
        $this->orphanDAO = $orphanDAO;
    }

    /**
     * Check if a batch can be matched (completed status, has linked Akaunting account)
     */
    public function canMatch(int $batchId, int $userId): array
    {
        $batch = $this->batchDAO->findByIdAndUser($batchId, $userId);
        
        if (!$batch) {
            return ['can_match' => false, 'reason' => 'Batch not found'];
        }
        
        if ($batch['status'] !== 'completed') {
            return ['can_match' => false, 'reason' => 'Batch must be completed before matching'];
        }
        
        // Get account info
        $account = $this->accountDAO->findById($batch['account_id']);
        if (!$account) {
            return ['can_match' => false, 'reason' => 'Account not found'];
        }
        
        if (empty($account['akaunting_account_id'])) {
            return ['can_match' => false, 'reason' => 'Account is not linked to an Akaunting account'];
        }
        
        // Get entity's installation
        $installation = $this->installationDAO->findByEntityId($account['entity_id']);
        if (!$installation) {
            return ['can_match' => false, 'reason' => 'No Akaunting installation configured for this entity'];
        }
        
        return [
            'can_match' => true,
            'batch' => $batch,
            'account' => $account,
            'installation' => $installation
        ];
    }

    /**
     * Perform matching for a batch
     */
    public function matchBatch(int $batchId, int $userId): array
    {
        // Check if we can match
        $canMatch = $this->canMatch($batchId, $userId);
        if (!$canMatch['can_match']) {
            throw new \Exception($canMatch['reason']);
        }
        
        $batch = $canMatch['batch'];
        $account = $canMatch['account'];
        $installation = $canMatch['installation'];
        
        // Get imported transactions
        $transactions = $this->transactionDAO->findByBatchId($batchId);
        if (empty($transactions)) {
            return ['matched' => 0, 'total' => 0, 'message' => 'No transactions to match'];
        }
        
        // Find date range of imported transactions
        $dates = array_column($transactions, 'transaction_date');
        $minDate = min($dates);
        $maxDate = max($dates);
        
        // Expand date range by matching window
        $startDate = date('Y-m-d', strtotime($minDate . ' - ' . $this->matchingWindowDays . ' days'));
        $endDate = date('Y-m-d', strtotime($maxDate . ' + ' . $this->matchingWindowDays . ' days'));
        
        // Fetch Akaunting transactions for this date range
        $akauntingTransactions = $this->fetchAkauntingTransactions(
            $installation,
            $account['akaunting_account_id'],
            $startDate,
            $endDate,
            $userId
        );
        
        // Perform matching
        $matchedCount = 0;
        foreach ($transactions as $txn) {
            $match = $this->findBestMatch($txn, $akauntingTransactions);
            
            if ($match) {
                $this->transactionDAO->updateMatch(
                    $txn['transaction_id'],
                    $match['akaunting_id'],
                    $match['akaunting_number'] ?? null,
                    $match['akaunting_date'],
                    $match['akaunting_amount'],
                    $match['akaunting_contact'],
                    $match['akaunting_category'],
                    $match['confidence']
                );
                $matchedCount++;
                
                // Remove matched transaction from pool to avoid double-matching
                $akauntingTransactions = array_filter($akauntingTransactions, function($a) use ($match) {
                    return $a['id'] !== $match['akaunting_id'];
                });
            }
        }
        
        return [
            'matched' => $matchedCount,
            'total' => count($transactions),
            'akaunting_transactions_fetched' => count($akauntingTransactions) + $matchedCount,
            'message' => "Matched $matchedCount of " . count($transactions) . " transactions"
        ];
    }

    /**
     * Fetch transactions from Akaunting API with date range filtering
     * Uses Akaunting's search parameter: search=paid_at>=YYYY-MM-DD paid_at<=YYYY-MM-DD
     */
    private function fetchAkauntingTransactions(
        array $installation,
        int $akauntingAccountId,
        string $startDate,
        string $endDate,
        int $userId
    ): array {
        $password = $this->installationService->decryptPassword($installation['api_password']);
        $companyId = $installation['company_id'] ?? 1;
        
        $allTransactions = [];
        $page = 1;
        $perPage = (int)ConfigService::get('akaunting.api_page_size', 50);
        $maxPages = (int)ConfigService::get('akaunting.api_max_pages', 20);
        $timeout = (int)ConfigService::get('akaunting.api_timeout', 60);
        
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: AkauntingImporter/1.0',
            'X-Company: ' . $companyId,
        ];
        
        // Build search query: account_id must be in search param, not as separate query param
        $searchQuery = urlencode("account_id:{$akauntingAccountId} paid_at>={$startDate} paid_at<={$endDate}");
        
        do {
            // Build URL with date and account filtering via search parameter
            $url = rtrim($installation['base_url'], '/') . '/api/transactions';
            $url .= '?limit=' . $perPage;
            $url .= '&page=' . $page;
            $url .= '&search=' . $searchQuery;
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_USERPWD => $installation['api_email'] . ':' . $password,
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                throw new \Exception("API request failed: $error");
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                $errorData = json_decode($response, true);
                $errorMsg = $errorData['message'] ?? "HTTP $httpCode";
                throw new \Exception("API error: $errorMsg");
            }

            $data = json_decode($response, true);
            $transactions = $data['data'] ?? [];
            $meta = $data['meta'] ?? [];
            
            // Process transactions from this page
            foreach ($transactions as $txn) {
                $paidAt = $txn['paid_at'] ?? null;
                if (!$paidAt) continue;
                
                // Extract date from datetime
                $txnDate = date('Y-m-d', strtotime($paidAt));
                
                // Extract contact (vendor) name
                $contactName = '';
                if (isset($txn['contact']) && is_array($txn['contact'])) {
                    $contactName = $txn['contact']['name'] ?? '';
                }
                
                // Extract category name
                $categoryName = '';
                if (isset($txn['category']) && is_array($txn['category'])) {
                    $categoryName = $txn['category']['name'] ?? '';
                }
                
                $allTransactions[] = [
                    'id' => $txn['id'],
                    'number' => $txn['number'] ?? '',
                    'date' => $txnDate,
                    'amount' => (float)$txn['amount'],
                    'type' => $txn['type'], // 'income' or 'expense'
                    'description' => $txn['description'] ?? '',
                    'reference' => $txn['reference'] ?? '',
                    'currency_code' => $txn['currency_code'] ?? 'TTD',
                    'contact' => $contactName,
                    'category' => $categoryName,
                ];
            }
            
            // Check if there are more pages
            $lastPage = $meta['last_page'] ?? 1;
            $hasMore = $page < $lastPage && $page < $maxPages;
            $page++;
            
            // If we got fewer results than requested, we're done
            if (count($transactions) < $perPage) {
                $hasMore = false;
            }
            
        } while ($hasMore);
        
        return $allTransactions;
    }

    /**
     * Find the best matching Akaunting transaction for an imported transaction
     * Uses multi-pass approach: exact date first, then ±1 day, ±2 days, etc.
     */
    private function findBestMatch(array $importedTxn, array $akauntingTransactions): ?array
    {
        $importedDate = $importedTxn['transaction_date'];
        $importedAmount = (float)$importedTxn['transaction_amount'];
        $importedDesc = strtolower($importedTxn['description'] ?? '');
        $importedRef = strtolower($importedTxn['bank_ref'] ?? '');
        
        // Determine expected Akaunting type based on imported amount sign
        // For credit cards: positive amount = expense (charge), negative = income (payment/refund)
        $expectedType = $importedAmount >= 0 ? 'expense' : 'income';
        $compareAmount = abs($importedAmount);
        
        // Multi-pass matching: start with exact date, then expand window
        for ($dayOffset = 0; $dayOffset <= $this->matchingWindowDays; $dayOffset++) {
            $match = $this->findMatchAtDateOffset(
                $importedDate,
                $compareAmount,
                $expectedType,
                $importedDesc,
                $importedRef,
                $akauntingTransactions,
                $dayOffset
            );
            
            if ($match) {
                return $match;
            }
        }
        
        return null;
    }
    
    /**
     * Find a match at a specific date offset (0 = exact, 1 = ±1 day, etc.)
     */
    private function findMatchAtDateOffset(
        string $importedDate,
        float $compareAmount,
        string $expectedType,
        string $importedDesc,
        string $importedRef,
        array $akauntingTransactions,
        int $dayOffset
    ): ?array {
        $bestMatch = null;
        $bestScore = 0;
        
        foreach ($akauntingTransactions as $akTxn) {
            $akAmount = (float)$akTxn['amount'];
            
            // Check if amounts match (within small tolerance for rounding)
            $amountDiff = abs($akAmount - $compareAmount);
            if ($amountDiff >= 0.02) {
                continue; // Amount must match
            }
            
            // Check if type matches
            // Normalize transfer types: "expense-transfer" -> "expense", "income-transfer" -> "income"
            $akType = $akTxn['type'];
            if (str_ends_with($akType, '-transfer')) {
                $akType = str_replace('-transfer', '', $akType);
            }
            if ($akType !== $expectedType) {
                continue;
            }
            
            // Check date offset
            $daysDiff = abs((strtotime($importedDate) - strtotime($akTxn['date'])) / 86400);
            $daysDiff = (int)round($daysDiff);
            
            // For this pass, only consider transactions at exactly this offset
            if ($daysDiff !== $dayOffset) {
                continue;
            }
            
            // We have a match! Calculate score for tiebreaking
            $score = 100 - ($dayOffset * 10); // Higher score for closer dates
            
            // Determine confidence based on date offset
            if ($dayOffset === 0) {
                $confidence = 'high';
            } elseif ($dayOffset <= 2) {
                $confidence = 'medium';
            } else {
                $confidence = 'low';
            }
            
            // Description/reference matching (bonus for tiebreaking)
            $akDesc = strtolower($akTxn['description'] ?? '');
            $akRef = strtolower($akTxn['reference'] ?? '');
            
            if (!empty($importedDesc) && !empty($akDesc)) {
                similar_text($importedDesc, $akDesc, $descPercent);
                if ($descPercent > 50) {
                    $score += 10;
                    if ($dayOffset === 0 && $descPercent > 70) {
                        $confidence = 'high';
                    }
                }
            }
            
            if (!empty($importedRef) && !empty($akRef) && strpos($akRef, $importedRef) !== false) {
                $score += 15;
                $confidence = 'high';
            }
            
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = [
                    'akaunting_id' => $akTxn['id'],
                    'akaunting_number' => $akTxn['number'] ?? '',
                    'akaunting_date' => $akTxn['date'],
                    'akaunting_amount' => $akTxn['amount'],
                    'akaunting_contact' => $akTxn['contact'] ?? '',
                    'akaunting_category' => $akTxn['category'] ?? '',
                    'confidence' => $confidence,
                    'score' => $score,
                ];
            }
        }
        
        return $bestMatch;
    }

    /**
     * Clear all matches for a batch
     */
    public function clearMatches(int $batchId): bool
    {
        // Also delete any match job
        $this->matchJobDAO->deleteByBatch($batchId);
        
        // Also delete any orphan transactions
        if ($this->orphanDAO) {
            $this->orphanDAO->deleteByBatchId($batchId);
        }
        
        return $this->transactionDAO->clearMatchesByBatch($batchId);
    }

    /**
     * Process one step of the matching job (chunked processing)
     * Returns progress info for AJAX updates
     */
    public function processMatchStep(int $batchId, int $userId): array
    {
        // Check if we can match
        $canMatch = $this->canMatch($batchId, $userId);
        if (!$canMatch['can_match']) {
            return [
                'status' => 'error',
                'message' => $canMatch['reason']
            ];
        }

        $batch = $canMatch['batch'];
        $account = $canMatch['account'];
        $installation = $canMatch['installation'];

        // Get or create job
        $job = $this->matchJobDAO->findOrCreate($batchId, $userId);

        // If job is complete or error, return current status
        if ($job['status'] === 'complete') {
            return [
                'status' => 'complete',
                'matched' => (int)$job['matched_count'],
                'total' => (int)$job['total_transactions'],
                'message' => "Matched {$job['matched_count']} of {$job['total_transactions']} transactions"
            ];
        }

        if ($job['status'] === 'error') {
            return [
                'status' => 'error',
                'message' => $job['error_message'] ?? 'Unknown error'
            ];
        }

        try {
            // If pending, initialize the job
            if ($job['status'] === 'pending') {
                return $this->initializeJob($job, $batch, $account, $installation);
            }

            // If fetching, get next page
            if ($job['status'] === 'fetching') {
                return $this->fetchNextPage($job, $account, $installation);
            }

            // If matching, perform matching
            if ($job['status'] === 'matching') {
                return $this->performMatchingStep($job, $batchId);
            }

        } catch (\Exception $e) {
            $this->matchJobDAO->markError($job['job_id'], $e->getMessage());
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }

        return ['status' => 'error', 'message' => 'Unknown job status'];
    }

    /**
     * Initialize a matching job
     */
    private function initializeJob(array $job, array $batch, array $account, array $installation): array
    {
        // Get imported transactions to determine date range
        $transactions = $this->transactionDAO->findByBatchId($job['batch_id']);
        if (empty($transactions)) {
            $this->matchJobDAO->markComplete($job['job_id'], 0, 0);
            return [
                'status' => 'complete',
                'matched' => 0,
                'total' => 0,
                'message' => 'No transactions to match'
            ];
        }

        // Find date range
        $dates = array_column($transactions, 'transaction_date');
        $minDate = min($dates);
        $maxDate = max($dates);
        $startDate = date('Y-m-d', strtotime($minDate . ' - ' . $this->matchingWindowDays . ' days'));
        $endDate = date('Y-m-d', strtotime($maxDate . ' + ' . $this->matchingWindowDays . ' days'));

        // Get total pages from first API call
        error_log("TransactionMatchingService: initializeJob for batch {$job['batch_id']}, account='{$account['account_name']}', akaunting_account_id={$account['akaunting_account_id']}, akaunting_name='{$account['akaunting_account_name']}'");
        
        $pageInfo = $this->fetchAkauntingPage(
            $installation,
            $account['akaunting_account_id'],
            $startDate,
            $endDate,
            1
        );

        // Store first page results and update job
        $this->matchJobDAO->updateProgress(
            $job['job_id'],
            'fetching',
            1,
            $pageInfo['total_pages'],
            $pageInfo['transactions']
        );

        // If only one page, move to matching phase
        if ($pageInfo['total_pages'] <= 1) {
            $this->matchJobDAO->updateProgress($job['job_id'], 'matching', 1, 1, null);
            return [
                'status' => 'fetching',
                'current_page' => 1,
                'total_pages' => 1,
                'fetched_count' => count($pageInfo['transactions']),
                'message' => 'Fetched all transactions, starting matching...'
            ];
        }

        return [
            'status' => 'fetching',
            'current_page' => 1,
            'total_pages' => $pageInfo['total_pages'],
            'fetched_count' => count($pageInfo['transactions']),
            'message' => "Fetching page 1 of {$pageInfo['total_pages']}..."
        ];
    }

    /**
     * Fetch the next page of Akaunting transactions
     */
    private function fetchNextPage(array $job, array $account, array $installation): array
    {
        $nextPage = (int)$job['current_page'] + 1;
        $totalPages = (int)$job['total_pages'];
        $maxPages = (int)ConfigService::get('akaunting.api_max_pages', 20);

        // Check if we've fetched all pages
        if ($nextPage > $totalPages || $nextPage > $maxPages) {
            $this->matchJobDAO->updateProgress($job['job_id'], 'matching', $job['current_page'], null, null);
            $existingCount = count($this->matchJobDAO->getAkauntingTransactions($job['job_id']));
            return [
                'status' => 'matching',
                'current_page' => (int)$job['current_page'],
                'total_pages' => $totalPages,
                'fetched_count' => $existingCount,
                'message' => "Fetched $existingCount transactions, now matching..."
            ];
        }

        // Get batch to determine date range
        $batch = $this->batchDAO->findById($job['batch_id']);
        $transactions = $this->transactionDAO->findByBatchId($job['batch_id']);
        $dates = array_column($transactions, 'transaction_date');
        $minDate = min($dates);
        $maxDate = max($dates);
        $startDate = date('Y-m-d', strtotime($minDate . ' - ' . $this->matchingWindowDays . ' days'));
        $endDate = date('Y-m-d', strtotime($maxDate . ' + ' . $this->matchingWindowDays . ' days'));

        // Fetch next page
        $pageInfo = $this->fetchAkauntingPage(
            $installation,
            $account['akaunting_account_id'],
            $startDate,
            $endDate,
            $nextPage
        );

        // Append transactions
        $this->matchJobDAO->appendTransactions($job['job_id'], $pageInfo['transactions']);
        $this->matchJobDAO->updateProgress($job['job_id'], 'fetching', $nextPage, null, null);

        $existingCount = count($this->matchJobDAO->getAkauntingTransactions($job['job_id']));

        // Check if this was the last page
        if ($nextPage >= $totalPages || $nextPage >= $maxPages || count($pageInfo['transactions']) < (int)ConfigService::get('akaunting.api_page_size', 50)) {
            $this->matchJobDAO->updateProgress($job['job_id'], 'matching', $nextPage, null, null);
            return [
                'status' => 'matching',
                'current_page' => $nextPage,
                'total_pages' => $totalPages,
                'fetched_count' => $existingCount,
                'message' => "Fetched $existingCount transactions, now matching..."
            ];
        }

        return [
            'status' => 'fetching',
            'current_page' => $nextPage,
            'total_pages' => $totalPages,
            'fetched_count' => $existingCount,
            'message' => "Fetching page $nextPage of $totalPages..."
        ];
    }

    /**
     * Perform matching after all transactions are fetched
     * Uses global multi-pass: exact dates first across ALL transactions,
     * then ±1 day, then ±2 days, etc.
     */
    private function performMatchingStep(array $job, int $batchId): array
    {
        // Get imported transactions
        $importedTransactions = $this->transactionDAO->findByBatchId($batchId);
        
        // Get fetched Akaunting transactions
        $akauntingTransactions = $this->matchJobDAO->getAkauntingTransactions($job['job_id']);
        
        // Get account type to determine expense/income logic
        $batch = $this->batchDAO->findById($batchId);
        $account = $this->accountDAO->findById($batch['account_id']);
        $accountType = $account['account_type'] ?? 'bank';

        // Track which imported transactions are already matched
        $matchedImportIds = [];
        $matchedCount = 0;

        // Multi-pass matching: exact date first, then expand window
        for ($dayOffset = 0; $dayOffset <= $this->matchingWindowDays; $dayOffset++) {
            foreach ($importedTransactions as $txn) {
                // Skip if already matched
                if (in_array($txn['transaction_id'], $matchedImportIds)) {
                    continue;
                }
                
                $match = $this->findMatchAtExactOffset($txn, $akauntingTransactions, $dayOffset, $accountType);
                
                if ($match) {
                    $this->transactionDAO->updateMatch(
                        $txn['transaction_id'],
                        $match['akaunting_id'],
                        $match['akaunting_number'] ?? null,
                        $match['akaunting_date'],
                        $match['akaunting_amount'],
                        $match['akaunting_contact'],
                        $match['akaunting_category'],
                        $match['confidence']
                    );
                    $matchedCount++;
                    $matchedImportIds[] = $txn['transaction_id'];
                    
                    // Remove matched Akaunting transaction from pool
                    $akauntingTransactions = array_filter($akauntingTransactions, function($a) use ($match) {
                        return $a['id'] !== $match['akaunting_id'];
                    });
                    $akauntingTransactions = array_values($akauntingTransactions); // Re-index
                }
            }
        }

        // Mark job complete
        $this->matchJobDAO->markComplete($job['job_id'], $matchedCount, count($importedTransactions));

        // Save orphan transactions (Akaunting transactions with no match)
        // Only include orphans within the actual batch date range (not the expanded matching window)
        $orphanCount = 0;
        if ($this->orphanDAO && !empty($akauntingTransactions) && !empty($importedTransactions)) {
            // Get the actual date range of imported transactions
            $importedDates = array_column($importedTransactions, 'transaction_date');
            $batchMinDate = min($importedDates);
            $batchMaxDate = max($importedDates);
            
            // Filter orphans to only those within the actual batch date range
            $orphansInRange = array_filter($akauntingTransactions, function($akTxn) use ($batchMinDate, $batchMaxDate) {
                $akDate = $akTxn['date'] ?? null;
                if (!$akDate) return false;
                return $akDate >= $batchMinDate && $akDate <= $batchMaxDate;
            });
            
            if (!empty($orphansInRange)) {
                $orphanCount = $this->orphanDAO->saveOrphans($batchId, array_values($orphansInRange));
            }
        }

        return [
            'status' => 'complete',
            'matched' => $matchedCount,
            'total' => count($importedTransactions),
            'orphans' => $orphanCount,
            'message' => "Matched $matchedCount of " . count($importedTransactions) . " transactions" . 
                        ($orphanCount > 0 ? " ($orphanCount orphans found)" : "")
        ];
    }
    
    /**
     * Find a match for a transaction at exactly the specified day offset
     */
    private function findMatchAtExactOffset(array $importedTxn, array $akauntingTransactions, int $dayOffset, string $accountType = 'bank'): ?array
    {
        $importedDate = $importedTxn['transaction_date'];
        $importedAmount = (float)$importedTxn['transaction_amount'];
        $importedDesc = strtolower($importedTxn['description'] ?? '');
        $importedRef = strtolower($importedTxn['bank_ref'] ?? '');
        
        // Determine expected Akaunting type based on account type
        // Bank: negative = expense, positive = income
        // Credit Card: negative = income (payment), positive = expense (charge)
        if ($accountType === 'credit_card') {
            $expectedType = $importedAmount >= 0 ? 'expense' : 'income';
        } else {
            // Bank account
            $expectedType = $importedAmount < 0 ? 'expense' : 'income';
        }
        $compareAmount = abs($importedAmount);
        
        $bestMatch = null;
        $bestScore = 0;
        
        foreach ($akauntingTransactions as $akTxn) {
            $akAmount = (float)$akTxn['amount'];
            
            // Check amount match (within tolerance)
            if (abs($akAmount - $compareAmount) >= 0.02) {
                continue;
            }
            
            // Check type match
            // Normalize transfer types: "expense-transfer" -> "expense", "income-transfer" -> "income"
            $akType = $akTxn['type'];
            if (str_ends_with($akType, '-transfer')) {
                $akType = str_replace('-transfer', '', $akType);
            }
            if ($akType !== $expectedType) {
                continue;
            }
            
            // Check date is exactly at this offset
            $daysDiff = abs((strtotime($importedDate) - strtotime($akTxn['date'])) / 86400);
            $daysDiff = (int)round($daysDiff);
            
            if ($daysDiff !== $dayOffset) {
                continue;
            }
            
            // Calculate score for tiebreaking
            $score = 100;
            
            // Determine confidence
            if ($dayOffset === 0) {
                $confidence = 'high';
            } elseif ($dayOffset <= 2) {
                $confidence = 'medium';
            } else {
                $confidence = 'low';
            }
            
            // Description similarity bonus
            $akDesc = strtolower($akTxn['description'] ?? '');
            if (!empty($importedDesc) && !empty($akDesc)) {
                similar_text($importedDesc, $akDesc, $descPercent);
                if ($descPercent > 50) {
                    $score += (int)$descPercent;
                }
            }
            
            // Reference match bonus
            $akRef = strtolower($akTxn['reference'] ?? '');
            if (!empty($importedRef) && !empty($akRef) && strpos($akRef, $importedRef) !== false) {
                $score += 50;
                $confidence = 'high';
            }
            
            if ($score > $bestScore) {
                $bestScore = $score;
                // Display amount with correct sign based on account type
                // Bank: expense = negative, income = positive
                // Credit Card: expense = positive, income = negative
                if ($accountType === 'credit_card') {
                    $displayAmount = $akTxn['type'] === 'income' 
                        ? -abs($akTxn['amount']) 
                        : abs($akTxn['amount']);
                } else {
                    // Bank account
                    $displayAmount = $akTxn['type'] === 'expense' 
                        ? -abs($akTxn['amount']) 
                        : abs($akTxn['amount']);
                }
                    
                $bestMatch = [
                    'akaunting_id' => $akTxn['id'],
                    'akaunting_number' => $akTxn['number'] ?? '',
                    'akaunting_date' => $akTxn['date'],
                    'akaunting_amount' => $displayAmount,
                    'akaunting_contact' => $akTxn['contact'] ?? '',
                    'akaunting_category' => $akTxn['category'] ?? '',
                    'confidence' => $confidence,
                ];
            }
        }
        
        return $bestMatch;
    }

    /**
     * Fetch a single page of Akaunting transactions
     */
    private function fetchAkauntingPage(
        array $installation,
        int $akauntingAccountId,
        string $startDate,
        string $endDate,
        int $page
    ): array {
        $password = $this->installationService->decryptPassword($installation['api_password']);
        $companyId = $installation['company_id'] ?? 1;
        $perPage = (int)ConfigService::get('akaunting.api_page_size', 50);
        $timeout = (int)ConfigService::get('akaunting.api_timeout', 60);

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: AkauntingImporter/1.0',
            'X-Company: ' . $companyId,
        ];

        // account_id must be in the search parameter, not as a separate query param
        $searchQuery = urlencode("account_id:{$akauntingAccountId} paid_at>={$startDate} paid_at<={$endDate}");
        
        $url = rtrim($installation['base_url'], '/') . '/api/transactions';
        $url .= '?limit=' . $perPage;
        $url .= '&page=' . $page;
        $url .= '&search=' . $searchQuery;

        error_log("TransactionMatchingService: Fetching from URL: $url (akaunting_account_id=$akauntingAccountId)");

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERPWD => $installation['api_email'] . ':' . $password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("API request failed: $error");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $errorData = json_decode($response, true);
            $errorMsg = $errorData['message'] ?? "HTTP $httpCode";
            throw new \Exception("API error: $errorMsg");
        }

        $data = json_decode($response, true);
        $transactions = [];
        
        foreach (($data['data'] ?? []) as $txn) {
            $paidAt = $txn['paid_at'] ?? null;
            if (!$paidAt) continue;

            $contactName = '';
            if (isset($txn['contact']) && is_array($txn['contact'])) {
                $contactName = $txn['contact']['name'] ?? '';
            }

            $categoryName = '';
            if (isset($txn['category']) && is_array($txn['category'])) {
                $categoryName = $txn['category']['name'] ?? '';
            }

            $transactions[] = [
                'id' => $txn['id'],
                'number' => $txn['number'] ?? '',
                'date' => date('Y-m-d', strtotime($paidAt)),
                'amount' => (float)$txn['amount'],
                'type' => $txn['type'],
                'description' => $txn['description'] ?? '',
                'reference' => $txn['reference'] ?? '',
                'currency_code' => $txn['currency_code'] ?? 'TTD',
                'contact' => $contactName,
                'category' => $categoryName,
            ];
        }

        $meta = $data['meta'] ?? [];
        
        return [
            'transactions' => $transactions,
            'current_page' => $page,
            'total_pages' => $meta['last_page'] ?? 1,
            'total_count' => $meta['total'] ?? count($transactions),
        ];
    }

    /**
     * Reset a match job for retry
     */
    public function resetMatchJob(int $batchId, int $userId): bool
    {
        $job = $this->matchJobDAO->findByBatchAndUser($batchId, $userId);
        if ($job) {
            return $this->matchJobDAO->reset($job['job_id']);
        }
        return true;
    }

    /**
     * Get current job status
     */
    public function getJobStatus(int $batchId, int $userId): ?array
    {
        return $this->matchJobDAO->findByBatchAndUser($batchId, $userId);
    }

    /**
     * Push a transaction to Akaunting
     */
    public function pushToAkaunting(
        int $batchId,
        int $transactionId,
        string $date,
        string $reference,
        string $contact,
        ?int $contactId,
        string $type,
        float $amount,
        int $categoryId,
        ?string $categoryName,
        string $paymentMethod,
        $vendorDAO = null
    ): array {
        // Get account and installation info
        $batch = $this->batchDAO->findById($batchId);
        if (!$batch) {
            throw new \Exception('Batch not found');
        }

        $account = $this->accountDAO->findById($batch['account_id']);
        if (!$account || empty($account['akaunting_account_id'])) {
            throw new \Exception('Account not linked to Akaunting');
        }

        $installation = $this->installationDAO->findByEntityId($account['entity_id']);
        if (!$installation) {
            throw new \Exception('No Akaunting installation found');
        }

        // Get transaction to verify it exists and get currency
        $txn = $this->transactionDAO->findById($transactionId);
        if (!$txn || $txn['batch_id'] !== $batchId) {
            throw new \Exception('Transaction not found');
        }

        // Prepare API request
        $password = $this->installationService->decryptPassword($installation['api_password']);
        $companyId = $installation['company_id'] ?? 1;
        $timeout = (int)ConfigService::get('akaunting.api_timeout', 60);

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: AkauntingImporter/1.0',
            'X-Company: ' . $companyId,
        ];

        // Build transaction data for Akaunting API
        // Generate unique transaction number: IMP-TRA-{transaction_id}
        $transactionNumber = 'IMP-TRA-' . $transactionId;
        
        $transactionData = [
            'type' => $type,
            'number' => $transactionNumber,
            'account_id' => $account['akaunting_account_id'],
            'paid_at' => $date . ' 00:00:00',
            'amount' => $amount,
            'currency_code' => $txn['transaction_currency'] ?? $account['currency'] ?? 'TTD',
            'currency_rate' => 1.0, // Default to 1.0 for same currency
            'description' => $txn['description'] ?? '',
            'reference' => $reference,
            'category_id' => $categoryId,
            'payment_method' => $paymentMethod, // Full code like 'offline-payments.bank_transfer.2'
        ];

        // Add contact - prefer contact_id if provided
        if ($contactId) {
            $transactionData['contact_id'] = $contactId;
        } elseif (!empty($contact)) {
            $transactionData['contact_name'] = $contact;
        }

        $url = rtrim($installation['base_url'], '/') . '/api/transactions';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($transactionData),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERPWD => $installation['api_email'] . ':' . $password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("API request failed: $error");
        }

        $responseData = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMsg = $responseData['message'] ?? "HTTP $httpCode";
            if (isset($responseData['errors'])) {
                $errorMsg .= ': ' . json_encode($responseData['errors']);
            }
            throw new \Exception("Akaunting API error: $errorMsg");
        }

        // Success - update the local transaction with the Akaunting ID
        $akauntingId = $responseData['data']['id'] ?? null;
        if ($akauntingId) {
            $this->transactionDAO->updateMatch(
                $transactionId,
                $akauntingId,
                $transactionNumber, // Use the generated transaction number we pushed
                $date,
                $type === 'income' ? -$amount : $amount,
                $contact,
                $categoryName ?? '',
                'high' // confidence - we just created it
            );
            
            // Update status to processed
            $this->transactionDAO->updateStatus($transactionId, 'processed');
            
            // Update push status with transaction number and timestamp
            $this->transactionDAO->updatePushStatus($transactionId, $transactionNumber);
            
            // Save transaction mapping for future auto-suggestion (type + vendor + category + payment method)
            if ($vendorDAO && !empty($txn['description'])) {
                try {
                    $vendorDAO->saveTransactionMapping(
                        $installation['installation_id'],
                        $txn['description'],
                        $type, // transaction type (income/expense)
                        $contactId,
                        $contact ?: null,
                        $categoryId,
                        $categoryName,
                        $paymentMethod,
                        null // no transfer account for income/expense
                    );
                } catch (\Exception $e) {
                    // Don't fail the whole operation if mapping save fails
                    error_log('Failed to save transaction mapping: ' . $e->getMessage());
                }
            }
        }

        return [
            'success' => true,
            'akaunting_id' => $akauntingId,
            'transaction_number' => $transactionNumber,
            'message' => 'Transaction created in Akaunting'
        ];
    }

    /**
     * Push a transfer to Akaunting
     * @param float|null $toAmount Amount in destination currency (for multi-currency transfers)
     * @param float|null $currencyRate Exchange rate from source to destination currency
     * @param \App\DAO\VendorDAO|null $vendorDAO Optional VendorDAO for saving transaction mapping
     */
    public function pushTransferToAkaunting(
        int $batchId,
        int $transactionId,
        string $date,
        string $reference,
        float $amount,
        int $fromAccountId,
        int $toAccountId,
        string $paymentMethod,
        ?float $toAmount = null,
        ?float $currencyRate = null,
        ?\App\DAO\VendorDAO $vendorDAO = null
    ): array {
        // Get batch and installation info
        $batch = $this->batchDAO->findById($batchId);
        if (!$batch) {
            throw new \Exception('Batch not found');
        }

        $account = $this->accountDAO->findById($batch['account_id']);
        if (!$account) {
            throw new \Exception('Account not found');
        }

        $installation = $this->installationDAO->findByEntityId($account['entity_id']);
        if (!$installation) {
            throw new \Exception('No Akaunting installation found');
        }

        // Get transaction to verify it exists and get description
        $txn = $this->transactionDAO->findById($transactionId);
        if (!$txn || $txn['batch_id'] !== $batchId) {
            throw new \Exception('Transaction not found');
        }

        // Prepare API request
        $password = $this->installationService->decryptPassword($installation['api_password']);
        $companyId = $installation['company_id'] ?? 1;
        $timeout = (int)ConfigService::get('akaunting.api_timeout', 60);

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: AkauntingImporter/1.0',
            'X-Company: ' . $companyId,
        ];

        // Build transfer data for Akaunting API
        $transferData = [
            'from_account_id' => $fromAccountId,
            'to_account_id' => $toAccountId,
            'amount' => abs($amount), // Transfers are always positive (source amount)
            'transferred_at' => $date,
            'payment_method' => $paymentMethod,
            'description' => $txn['description'] ?? '',
            'reference' => $reference,
        ];
        
        // Add multi-currency fields if exchange rate is provided
        // Akaunting expects from_account_rate and to_account_rate (NOT to_amount/currency_rate)
        // See: https://github.com/akaunting/akaunting/blob/master/app/Jobs/Banking/CreateTransfer.php
        if ($currencyRate !== null && $currencyRate > 0) {
            $transferData['from_account_rate'] = 1.0;  // Source currency rate (base)
            $transferData['to_account_rate'] = $currencyRate;  // Destination currency rate
        }

        $url = rtrim($installation['base_url'], '/') . '/api/transfers';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($transferData),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERPWD => $installation['api_email'] . ':' . $password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("API request failed: $error");
        }

        $responseData = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMsg = $responseData['message'] ?? "HTTP $httpCode";
            if (isset($responseData['errors'])) {
                $errorMsg .= ': ' . json_encode($responseData['errors']);
            }
            throw new \Exception("Akaunting API error: $errorMsg");
        }

        // Success - update the local transaction with the Akaunting transfer ID
        $akauntingId = $responseData['data']['id'] ?? null;
        $transferNumber = 'IMP-TRF-' . $transactionId; // Transfer number format
        
        if ($akauntingId) {
            $this->transactionDAO->updateMatch(
                $transactionId,
                $akauntingId,
                $transferNumber, // Use the generated transfer number we pushed
                $date,
                $amount,
                'Transfer', // contact name
                'Transfer', // category name
                'high' // confidence - we just created it
            );
            
            // Update status to processed
            $this->transactionDAO->updateStatus($transactionId, 'processed');
            
            // Update push status with transfer number and timestamp
            $this->transactionDAO->updatePushStatus($transactionId, $transferNumber);
            
            // Save transaction mapping for future auto-suggestion (transfer type + destination account)
            if ($vendorDAO && !empty($txn['description'])) {
                try {
                    $vendorDAO->saveTransactionMapping(
                        $installation['installation_id'],
                        $txn['description'],
                        'transfer', // transaction type
                        null, // no contact for transfers
                        null,
                        null, // no category for transfers
                        null,
                        $paymentMethod,
                        $toAccountId // destination account for transfer
                    );
                } catch (\Exception $e) {
                    // Don't fail the whole operation if mapping save fails
                    error_log('Failed to save transfer mapping: ' . $e->getMessage());
                }
            }
        }

        return [
            'success' => true,
            'akaunting_id' => $akauntingId,
            'transaction_number' => $transferNumber,
            'message' => 'Transfer created in Akaunting'
        ];
    }

    /**
     * Push a transaction to a different Akaunting installation (cross-entity replication)
     */
    /**
     * Push transaction to another installation (for cross-entity replication)
     * @param float|null $toAmount Amount in destination currency (for multi-currency)
     * @param float|null $currencyRate Exchange rate from source to destination currency
     */
    public function pushToOtherInstallation(
        int $installationId,
        int $akauntingAccountId,
        int $userId,
        string $date,
        int $sourceTransactionId,
        string $contact,
        ?int $contactId,
        string $type,
        float $amount,
        int $categoryId,
        ?string $categoryName,
        string $paymentMethod,
        string $description,
        ?float $toAmount = null,
        ?float $currencyRate = null
    ): array {
        // Get the installation
        $installation = $this->installationDAO->findByIdAndUser($installationId, $userId);
        if (!$installation) {
            throw new \Exception('Installation not found');
        }

        // Prepare API request
        $password = $this->installationService->decryptPassword($installation['api_password']);
        $companyId = $installation['company_id'] ?? 1;
        $timeout = (int)ConfigService::get('akaunting.api_timeout', 60);

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: AkauntingImporter/1.0',
            'X-Company: ' . $companyId,
        ];

        // Build transaction data for Akaunting API
        // Use same transaction number format as pushed transactions: IMP-TRA-{transaction_id}
        $transactionNumber = 'IMP-TRA-' . $sourceTransactionId;
        
        // Use the destination amount if currency exchange is involved, otherwise use source amount
        $finalAmount = ($toAmount !== null && $currencyRate !== null && $currencyRate > 0) ? $toAmount : $amount;
        $finalRate = ($currencyRate !== null && $currencyRate > 0) ? $currencyRate : 1.0;
        
        $transactionData = [
            'type' => $type,
            'number' => $transactionNumber,
            'account_id' => $akauntingAccountId,
            'paid_at' => $date . ' 00:00:00',
            'amount' => $finalAmount,
            'currency_code' => 'TTD', // Default currency, could be parameterized
            'currency_rate' => $finalRate,
            'description' => $description,
            'category_id' => $categoryId,
            'payment_method' => $paymentMethod,
        ];

        // Add contact
        if ($contactId) {
            $transactionData['contact_id'] = $contactId;
        } elseif (!empty($contact)) {
            $transactionData['contact_name'] = $contact;
        }

        $url = rtrim($installation['base_url'], '/') . '/api/transactions';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($transactionData),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERPWD => $installation['api_email'] . ':' . $password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("API request failed: $error");
        }

        $responseData = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMsg = $responseData['message'] ?? "HTTP $httpCode";
            if (isset($responseData['errors'])) {
                $errorMsg .= ': ' . json_encode($responseData['errors']);
            }
            throw new \Exception("Akaunting API error: $errorMsg");
        }

        $akauntingId = $responseData['data']['id'] ?? null;

        return [
            'success' => true,
            'akaunting_id' => $akauntingId,
            'transaction_number' => $transactionNumber,
            'message' => 'Transaction replicated to ' . $installation['name']
        ];
    }

    /**
     * Check if an account can be reconciled (has linked Akaunting account)
     */
    public function canReconcileAccount(int $accountId, int $userId): array
    {
        $account = $this->accountDAO->findById($accountId);
        
        if (!$account) {
            return ['can_reconcile' => false, 'reason' => 'Account not found'];
        }
        
        if (empty($account['akaunting_account_id'])) {
            return ['can_reconcile' => false, 'reason' => 'Account is not linked to an Akaunting account'];
        }
        
        // Get entity's installation
        $installation = $this->installationDAO->findByEntityId($account['entity_id']);
        if (!$installation) {
            return ['can_reconcile' => false, 'reason' => 'No Akaunting installation configured for this entity'];
        }
        
        return [
            'can_reconcile' => true,
            'account' => $account,
            'installation' => $installation
        ];
    }

    /**
     * Get basic account reconciliation info (without fetching Akaunting data)
     * Used for initial page load
     */
    public function getAccountReconciliationInfo(int $accountId, int $userId): array
    {
        // Check if we can reconcile
        $canReconcile = $this->canReconcileAccount($accountId, $userId);
        if (!$canReconcile['can_reconcile']) {
            throw new \Exception($canReconcile['reason']);
        }
        
        $account = $canReconcile['account'];
        $installation = $canReconcile['installation'];
        
        // Get all transactions for this account
        $importedTransactions = $this->transactionDAO->findByAccountId($accountId);
        
        // Get date range
        $dateRange = ['start' => null, 'end' => null];
        if (!empty($importedTransactions)) {
            $dates = array_column($importedTransactions, 'transaction_date');
            $dateRange['start'] = min($dates);
            $dateRange['end'] = max($dates);
        }
        
        // Calculate basic stats from imported transactions
        $totalImported = count($importedTransactions);
        $matched = 0;
        foreach ($importedTransactions as $txn) {
            if (!empty($txn['matched_akaunting_id'])) {
                $matched++;
            }
        }
        
        return [
            'account' => $account,
            'installation' => $installation,
            'date_range' => $dateRange,
            'imported_transactions' => $importedTransactions,
            'stats' => [
                'total_imported' => $totalImported,
                'matched' => $matched,
                'missing' => $totalImported - $matched,
                'orphans' => 0  // Will be calculated after fetching Akaunting data
            ]
        ];
    }

    /**
     * Fetch one page of Akaunting transactions for reconciliation (chunked)
     */
    public function fetchReconciliationPage(int $accountId, int $userId, int $page, ?string $startDate = null, ?string $endDate = null): array
    {
        // Check if we can reconcile
        $canReconcile = $this->canReconcileAccount($accountId, $userId);
        if (!$canReconcile['can_reconcile']) {
            throw new \Exception($canReconcile['reason']);
        }
        
        $account = $canReconcile['account'];
        $installation = $canReconcile['installation'];
        
        // If no dates provided, get from account transactions
        if (!$startDate || !$endDate) {
            $dateRange = $this->transactionDAO->getAccountDateRange($accountId);
            $startDate = $dateRange['start'];
            $endDate = $dateRange['end'];
        }
        
        if (!$startDate || !$endDate) {
            return [
                'status' => 'complete',
                'transactions' => [],
                'current_page' => 0,
                'total_pages' => 0,
                'message' => 'No transactions to reconcile'
            ];
        }
        
        // Fetch page from Akaunting
        $pageInfo = $this->fetchAkauntingPage(
            $installation,
            $account['akaunting_account_id'],
            $startDate,
            $endDate,
            $page
        );
        
        $isComplete = $page >= $pageInfo['total_pages'];
        
        return [
            'status' => $isComplete ? 'complete' : 'fetching',
            'transactions' => $pageInfo['transactions'],
            'current_page' => $page,
            'total_pages' => $pageInfo['total_pages'],
            'message' => $isComplete 
                ? "Fetched all {$pageInfo['total_pages']} pages" 
                : "Fetching page {$page} of {$pageInfo['total_pages']}..."
        ];
    }

    /**
     * Perform reconciliation comparison given fetched Akaunting transactions
     */
    public function performReconciliation(int $accountId, array $akauntingTransactions): array
    {
        // Get all imported transactions for this account
        $importedTransactions = $this->transactionDAO->findByAccountId($accountId);
        
        // Build a map of matched Akaunting IDs from imported transactions
        $matchedAkauntingIds = [];
        foreach ($importedTransactions as $txn) {
            if (!empty($txn['matched_akaunting_id'])) {
                $matchedAkauntingIds[$txn['matched_akaunting_id']] = true;
            }
        }
        
        // Find orphans - Akaunting transactions not matched to any import
        $orphanTransactions = [];
        foreach ($akauntingTransactions as $akTxn) {
            if (!isset($matchedAkauntingIds[$akTxn['id']])) {
                $orphanTransactions[] = $akTxn;
            }
        }
        
        // Calculate stats
        $totalImported = count($importedTransactions);
        $matched = count($matchedAkauntingIds);
        $missing = $totalImported - $matched;
        $orphans = count($orphanTransactions);
        
        return [
            'imported_transactions' => $importedTransactions,
            'orphan_transactions' => $orphanTransactions,
            'stats' => [
                'total_imported' => $totalImported,
                'matched' => $matched,
                'missing' => $missing,
                'orphans' => $orphans
            ]
        ];
    }
}


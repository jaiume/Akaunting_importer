<?php

namespace App\Services;

use App\DAO\InstallationDAO;

class ReportService
{
    private InstallationDAO $installationDAO;
    private InstallationService $installationService;

    public function __construct(
        InstallationDAO $installationDAO,
        InstallationService $installationService
    ) {
        $this->installationDAO = $installationDAO;
        $this->installationService = $installationService;
    }

    /**
     * Generate income and expense report for a specific month
     * Fetches transactions from Akaunting API with pagination to handle large datasets
     * Groups results by currency to handle multi-currency accounts
     * 
     * @param int $installationId The Akaunting installation ID
     * @param int $userId The user ID for authorization
     * @param int $year The year (e.g., 2024)
     * @param int $month The month (1-12)
     * @return array Report data with income/expenses grouped by category and currency
     */
    public function generateIncomeExpenseReport(int $installationId, int $userId, int $year, int $month): array
    {
        $installation = $this->installationDAO->findByIdAndUser($installationId, $userId);
        
        if (!$installation) {
            throw new \Exception('Installation not found', 404);
        }

        // Calculate date range for the month
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate)); // Last day of month
        $monthName = date('F', strtotime($startDate));

        // Initialize aggregation structures grouped by currency
        // Structure: currency => [categories => [...], total => 0]
        $incomeByCurrency = [];
        $expensesByCurrency = [];

        // Fetch and aggregate transactions page by page
        $fetchResult = $this->fetchAndAggregateTransactions(
            $installation,
            $startDate,
            $endDate,
            $incomeByCurrency,
            $expensesByCurrency
        );

        // Build final income structure grouped by currency
        $income = $this->buildIncomeStructure($incomeByCurrency);
        
        // Build final expenses structure grouped by currency
        $expenses = $this->buildExpensesStructure($expensesByCurrency);

        return [
            'income' => $income,
            'expenses' => $expenses,
            'period' => [
                'year' => $year,
                'month' => $month,
                'month_name' => $monthName,
                'start_date' => $startDate,
                'end_date' => $endDate
            ],
            'installation' => [
                'id' => $installation['installation_id'],
                'name' => $installation['name']
            ],
            'fetch_info' => $fetchResult
        ];
    }

    /**
     * Build income structure from currency-grouped data
     */
    private function buildIncomeStructure(array $incomeByCurrency): array
    {
        $result = [
            'currencies' => [],
            'has_multiple_currencies' => count($incomeByCurrency) > 1
        ];

        foreach ($incomeByCurrency as $currency => $categoryData) {
            $categories = [];
            $total = 0.0;

            foreach ($categoryData as $categoryName => $amount) {
                $categories[] = [
                    'name' => $categoryName ?: '(Uncategorized)',
                    'total' => $amount
                ];
                $total += $amount;
            }

            // Sort categories by total descending
            usort($categories, function($a, $b) {
                return $b['total'] <=> $a['total'];
            });

            $result['currencies'][$currency] = [
                'categories' => $categories,
                'total' => $total
            ];
        }

        // Sort currencies alphabetically
        ksort($result['currencies']);

        return $result;
    }

    /**
     * Build expenses structure from currency-grouped data
     */
    private function buildExpensesStructure(array $expensesByCurrency): array
    {
        $result = [
            'currencies' => [],
            'has_multiple_currencies' => count($expensesByCurrency) > 1
        ];

        foreach ($expensesByCurrency as $currency => $categoryData) {
            $categories = [];
            $total = 0.0;

            foreach ($categoryData as $categoryName => $data) {
                $accounts = [];
                foreach ($data['accounts'] as $accountName => $accountTotal) {
                    $accounts[] = [
                        'name' => $accountName ?: '(No Account)',
                        'total' => $accountTotal
                    ];
                }

                // Sort accounts by total descending
                usort($accounts, function($a, $b) {
                    return $b['total'] <=> $a['total'];
                });

                $categories[] = [
                    'name' => $categoryName ?: '(Uncategorized)',
                    'accounts' => $accounts,
                    'total' => $data['total']
                ];
                $total += $data['total'];
            }

            // Sort categories by total descending
            usort($categories, function($a, $b) {
                return $b['total'] <=> $a['total'];
            });

            $result['currencies'][$currency] = [
                'categories' => $categories,
                'total' => $total
            ];
        }

        // Sort currencies alphabetically
        ksort($result['currencies']);

        return $result;
    }

    /**
     * Fetch transactions from Akaunting API with pagination and aggregate on-the-fly
     * Uses the same batching pattern as TransactionMatchingService
     * Groups by currency for proper multi-currency handling
     */
    private function fetchAndAggregateTransactions(
        array $installation,
        string $startDate,
        string $endDate,
        array &$incomeByCurrency,
        array &$expensesByCurrency
    ): array {
        $password = $this->installationService->decryptPassword($installation['api_password']);
        $companyId = $installation['company_id'] ?? 1;
        
        $page = 1;
        $perPage = (int)ConfigService::get('akaunting.api_page_size', 50);
        $maxPages = (int)ConfigService::get('akaunting.api_max_pages', 20);
        $timeout = (int)ConfigService::get('akaunting.api_timeout', 60);
        
        $totalFetched = 0;
        $totalPages = 1;
        $warnings = [];

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: AkauntingImporter/1.0',
            'X-Company: ' . $companyId,
        ];

        // Build search query for date filtering
        $searchQuery = urlencode("paid_at>={$startDate} paid_at<={$endDate}");

        do {
            $url = rtrim($installation['base_url'], '/') . '/api/transactions';
            $url .= '?limit=' . $perPage;
            $url .= '&page=' . $page;
            $url .= '&search=' . $searchQuery;

            try {
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
                    $warnings[] = "Page {$page}: cURL error - {$error}";
                    break;
                }

                if ($httpCode < 200 || $httpCode >= 300) {
                    $errorData = json_decode($response, true);
                    $errorMsg = $errorData['message'] ?? "HTTP {$httpCode}";
                    $warnings[] = "Page {$page}: API error - {$errorMsg}";
                    break;
                }

                $data = json_decode($response, true);
                $transactions = $data['data'] ?? [];
                $meta = $data['meta'] ?? [];
                
                $totalPages = $meta['last_page'] ?? 1;

                // Aggregate transactions from this page
                foreach ($transactions as $txn) {
                    $type = $txn['type'] ?? '';
                    $amount = (float)($txn['amount'] ?? 0);
                    $currency = $txn['currency_code'] ?? 'USD';
                    
                    // Extract category name
                    $categoryName = '';
                    if (isset($txn['category']) && is_array($txn['category'])) {
                        $categoryName = $txn['category']['name'] ?? '';
                    }
                    
                    // Extract account name
                    $accountName = '';
                    if (isset($txn['account']) && is_array($txn['account'])) {
                        $accountName = $txn['account']['name'] ?? '';
                    }

                    // Exclude transfers - they are neither income nor expenses
                    if ($type === 'income') {
                        // Initialize currency group if needed
                        if (!isset($incomeByCurrency[$currency])) {
                            $incomeByCurrency[$currency] = [];
                        }
                        // Initialize category if needed
                        if (!isset($incomeByCurrency[$currency][$categoryName])) {
                            $incomeByCurrency[$currency][$categoryName] = 0.0;
                        }
                        $incomeByCurrency[$currency][$categoryName] += $amount;
                    } elseif ($type === 'expense') {
                        // Initialize currency group if needed
                        if (!isset($expensesByCurrency[$currency])) {
                            $expensesByCurrency[$currency] = [];
                        }
                        // Initialize category if needed
                        if (!isset($expensesByCurrency[$currency][$categoryName])) {
                            $expensesByCurrency[$currency][$categoryName] = [
                                'accounts' => [],
                                'total' => 0.0
                            ];
                        }
                        // Initialize account if needed
                        if (!isset($expensesByCurrency[$currency][$categoryName]['accounts'][$accountName])) {
                            $expensesByCurrency[$currency][$categoryName]['accounts'][$accountName] = 0.0;
                        }
                        $expensesByCurrency[$currency][$categoryName]['accounts'][$accountName] += $amount;
                        $expensesByCurrency[$currency][$categoryName]['total'] += $amount;
                    }
                    // Skip 'income-transfer' and 'expense-transfer' types
                    
                    $totalFetched++;
                }

                // Check if we should continue
                $hasMore = $page < $totalPages && $page < $maxPages;
                
                // If we got fewer results than requested, we're done
                if (count($transactions) < $perPage) {
                    $hasMore = false;
                }
                
                $page++;

            } catch (\Exception $e) {
                $warnings[] = "Page {$page}: Exception - " . $e->getMessage();
                break;
            }

        } while ($hasMore);

        return [
            'total_transactions' => $totalFetched,
            'pages_fetched' => $page - 1,
            'total_pages' => $totalPages,
            'warnings' => $warnings,
            'truncated' => ($page - 1) < $totalPages && ($page - 1) >= $maxPages
        ];
    }
}

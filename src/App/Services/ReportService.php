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
     * 
     * @param int $installationId The Akaunting installation ID
     * @param int $userId The user ID for authorization
     * @param int $year The year (e.g., 2024)
     * @param int $month The month (1-12)
     * @return array Report data with income/expenses grouped by category
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

        // Initialize aggregation structures
        $income = [
            'categories' => [],
            'total' => 0.0
        ];
        $expenses = [
            'categories' => [],
            'total' => 0.0
        ];
        
        // Track categories with their account breakdowns for expenses
        $expensesByCategory = []; // category_name => ['accounts' => [account_name => total], 'total' => 0]
        $incomeByCategory = []; // category_name => total

        // Fetch and aggregate transactions page by page
        $fetchResult = $this->fetchAndAggregateTransactions(
            $installation,
            $startDate,
            $endDate,
            $incomeByCategory,
            $expensesByCategory
        );

        // Build final income structure
        foreach ($incomeByCategory as $categoryName => $total) {
            $income['categories'][] = [
                'name' => $categoryName ?: '(Uncategorized)',
                'total' => $total
            ];
            $income['total'] += $total;
        }

        // Sort income categories by total descending
        usort($income['categories'], function($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        // Build final expenses structure with account breakdowns
        foreach ($expensesByCategory as $categoryName => $categoryData) {
            $accounts = [];
            foreach ($categoryData['accounts'] as $accountName => $accountTotal) {
                $accounts[] = [
                    'name' => $accountName ?: '(No Account)',
                    'total' => $accountTotal
                ];
            }
            
            // Sort accounts by total descending
            usort($accounts, function($a, $b) {
                return $b['total'] <=> $a['total'];
            });

            $expenses['categories'][] = [
                'name' => $categoryName ?: '(Uncategorized)',
                'accounts' => $accounts,
                'total' => $categoryData['total']
            ];
            $expenses['total'] += $categoryData['total'];
        }

        // Sort expense categories by total descending
        usort($expenses['categories'], function($a, $b) {
            return $b['total'] <=> $a['total'];
        });

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
     * Fetch transactions from Akaunting API with pagination and aggregate on-the-fly
     * Uses the same batching pattern as TransactionMatchingService
     */
    private function fetchAndAggregateTransactions(
        array $installation,
        string $startDate,
        string $endDate,
        array &$incomeByCategory,
        array &$expensesByCategory
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
                        // Aggregate income by category
                        if (!isset($incomeByCategory[$categoryName])) {
                            $incomeByCategory[$categoryName] = 0.0;
                        }
                        $incomeByCategory[$categoryName] += $amount;
                    } elseif ($type === 'expense') {
                        // Aggregate expenses by category and account
                        if (!isset($expensesByCategory[$categoryName])) {
                            $expensesByCategory[$categoryName] = [
                                'accounts' => [],
                                'total' => 0.0
                            ];
                        }
                        if (!isset($expensesByCategory[$categoryName]['accounts'][$accountName])) {
                            $expensesByCategory[$categoryName]['accounts'][$accountName] = 0.0;
                        }
                        $expensesByCategory[$categoryName]['accounts'][$accountName] += $amount;
                        $expensesByCategory[$categoryName]['total'] += $amount;
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


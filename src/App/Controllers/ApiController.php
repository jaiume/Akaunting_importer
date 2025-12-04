<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\AccountService;
use App\Services\AccountLinkService;
use App\Services\InstallationService;

class ApiController extends BaseController
{
    private AccountService $accountService;
    private AccountLinkService $accountLinkService;
    private InstallationService $installationService;

    public function __construct(
        Twig $view, 
        AccountService $accountService,
        AccountLinkService $accountLinkService,
        InstallationService $installationService
    ) {
        parent::__construct($view);
        $this->accountService = $accountService;
        $this->accountLinkService = $accountLinkService;
        $this->installationService = $installationService;
    }

    /**
     * Get accounts for entity (AJAX endpoint)
     */
    public function getAccountsByEntity(Request $request, Response $response): Response
    {
        $entityName = $this->getRouteArg($request, 'entity_name') ?? '';

        try {
            $accounts = $this->accountService->getAccountsByEntity($entityName);
            return $this->json($response, ['success' => true, 'accounts' => $accounts]);
        } catch (\Exception $e) {
            return $this->json($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Fetch Akaunting accounts for an installation (AJAX endpoint)
     */
    public function getAkauntingAccounts(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $installationId = (int)$this->getRouteArg($request, 'installation_id');

        try {
            $accounts = $this->installationService->fetchAkauntingAccounts($installationId, $user['user_id']);
            return $this->json($response, ['success' => true, 'accounts' => $accounts]);
        } catch (\Exception $e) {
            return $this->json($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get account with its Akaunting link and the entity's installation
     */
    public function getAccountLinks(Request $request, Response $response): Response
    {
        $accountId = (int)$this->getRouteArg($request, 'account_id');

        try {
            $account = $this->accountLinkService->getAccountWithLink($accountId);
            if (!$account) {
                return $this->json($response, ['success' => false, 'error' => 'Account not found'], 404);
            }
            
            $installation = $this->accountLinkService->getInstallationForAccount($accountId);
            
            // Build link info if account has an Akaunting link
            $link = null;
            if ($account['akaunting_account_id']) {
                $link = [
                    'akaunting_account_id' => $account['akaunting_account_id'],
                    'akaunting_account_name' => $account['akaunting_account_name'],
                ];
            }
            
            return $this->json($response, [
                'success' => true, 
                'account' => $account,
                'link' => $link,
                'installation' => $installation
            ]);
        } catch (\Exception $e) {
            return $this->json($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Save an Akaunting link for an account
     */
    public function saveAccountLink(Request $request, Response $response): Response
    {
        $accountId = (int)$this->getRouteArg($request, 'account_id');
        $data = json_decode($request->getBody()->getContents(), true) ?? [];

        try {
            $akauntingAccountId = (int)($data['akaunting_account_id'] ?? 0);
            $akauntingAccountName = $data['akaunting_account_name'] ?? '';

            if (!$akauntingAccountId || !$akauntingAccountName) {
                return $this->json($response, ['success' => false, 'error' => 'Missing required fields'], 400);
            }

            $success = $this->accountLinkService->saveLink(
                $accountId,
                $akauntingAccountId,
                $akauntingAccountName
            );

            return $this->json($response, ['success' => $success]);
        } catch (\Exception $e) {
            return $this->json($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove Akaunting link from an account
     */
    public function deleteAccountLink(Request $request, Response $response): Response
    {
        $accountId = (int)$this->getRouteArg($request, 'account_id');

        try {
            $success = $this->accountLinkService->removeLink($accountId);
            return $this->json($response, ['success' => $success]);
        } catch (\Exception $e) {
            return $this->json($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get entities with Akaunting installations (for cross-entity replication)
     * Optionally excludes a specific entity
     */
    public function getEntitiesWithInstallations(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $queryParams = $this->getQueryParams($request);
        $excludeEntityId = isset($queryParams['exclude_entity']) ? (int)$queryParams['exclude_entity'] : null;

        try {
            $entities = $this->installationService->getEntitiesWithInstallations($user['user_id'], $excludeEntityId);
            return $this->json($response, ['success' => true, 'entities' => $entities]);
        } catch (\Exception $e) {
            return $this->json($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get form data for a specific installation (vendors, categories, payment methods, accounts)
     */
    public function getInstallationFormData(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $installationId = (int)$this->getRouteArg($request, 'installation_id');
        $queryParams = $this->getQueryParams($request);
        
        // Source mapping info for pre-selection
        $sourceVendorId = isset($queryParams['source_vendor_id']) ? (int)$queryParams['source_vendor_id'] : null;
        $sourceCategoryId = isset($queryParams['source_category_id']) ? (int)$queryParams['source_category_id'] : null;
        $sourceInstallationId = isset($queryParams['source_installation_id']) ? (int)$queryParams['source_installation_id'] : null;

        try {
            $formData = $this->installationService->getFormDataForInstallation(
                $installationId, 
                $user['user_id'],
                $sourceInstallationId,
                $sourceVendorId,
                $sourceCategoryId
            );
            return $this->json($response, array_merge(['success' => true], $formData));
        } catch (\Exception $e) {
            return $this->json($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Analyze uploaded file to detect processor type and extract metadata
     * Returns suggested processor, batch name, and detected info
     */
    public function analyzeFile(Request $request, Response $response): Response
    {
        $uploadedFiles = $request->getUploadedFiles();
        $uploadedFile = $uploadedFiles['file'] ?? null;

        if (!$uploadedFile || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, [
                'success' => false, 
                'error' => 'No file uploaded or upload error'
            ], 400);
        }

        $fileName = $uploadedFile->getClientFilename();
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (!in_array($extension, ['csv', 'pdf'])) {
            return $this->json($response, [
                'success' => false,
                'error' => 'Invalid file type. Please upload a CSV or PDF file.'
            ], 400);
        }

        try {
            $result = $this->detectFileType($uploadedFile, $extension, $fileName);
            return $this->json($response, array_merge(['success' => true], $result));
        } catch (\Exception $e) {
            return $this->json($response, [
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Detect file type and extract metadata for batch name suggestion
     */
    private function detectFileType($uploadedFile, string $extension, string $fileName): array
    {
        $processor = null;
        $suggestedName = pathinfo($fileName, PATHINFO_FILENAME) . ' Import';
        $dateRange = null;
        $accountNumber = null;
        $fileType = strtoupper($extension);
        $confidence = 'low';

        // Read file content
        $stream = $uploadedFile->getStream();
        $stream->rewind();
        
        if ($extension === 'csv') {
            // Read first 2KB of CSV to detect type
            $content = $stream->read(2048);
            $result = $this->analyzeCSVContent($content, $fileName);
        } else {
            // For PDF, read more content and use pdftotext if available
            $content = $stream->getContents();
            $result = $this->analyzePDFContent($content, $fileName);
        }

        return array_merge([
            'fileType' => $fileType,
            'fileName' => $fileName,
        ], $result);
    }

    /**
     * Analyze CSV content to detect processor type
     */
    private function analyzeCSVContent(string $content, string $fileName): array
    {
        $processor = null;
        $suggestedName = pathinfo($fileName, PATHINFO_FILENAME) . ' Import';
        $dateRange = null;
        $accountNumber = null;
        $confidence = 'low';

        // Detect RBL Credit Card CSV
        // Pattern: "Credit Card Number,Nickname," header and "Transactions," marker
        if (preg_match('/Credit Card Number,Nickname,/i', $content) || 
            preg_match('/^Transactions,?$/mi', $content) ||
            preg_match('/Date,Card Number,Description,/i', $content)) {
            $processor = 'rbl_credit_card';
            $confidence = 'high';
            
            // Try to extract date range from filename or content
            if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})\s*-\s*(\d{1,2})\/(\d{1,2})\/(\d{4})/i', $content, $matches)) {
                $startMonth = (int)$matches[2];
                $endMonth = (int)$matches[5];
                $year = $matches[6];
                $dateRange = $this->getMonthName($endMonth) . ' ' . $year;
            }
            
            $suggestedName = 'RBL CC ' . ($dateRange ?: date('M Y'));
        }
        // Detect RBL Bank CSV
        // Pattern: "Account Number,Nickname," header and "History," marker
        elseif (preg_match('/Account Number,Nickname,/i', $content) || 
                preg_match('/^History,?$/mi', $content) ||
                preg_match('/Transaction Date,Description,Debit/i', $content)) {
            $processor = 'rbl_bank';
            $confidence = 'high';
            
            // Try to extract date range
            if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})\s*-\s*(\d{1,2})\/(\d{1,2})\/(\d{4})/i', $content, $matches)) {
                $startMonth = (int)$matches[2];
                $endMonth = (int)$matches[5];
                $year = $matches[6];
                $dateRange = $this->getMonthName($endMonth) . ' ' . $year;
            }
            
            $suggestedName = 'RBL Bank ' . ($dateRange ?: date('M Y'));
        }

        return [
            'processor' => $processor,
            'suggestedName' => $suggestedName,
            'dateRange' => $dateRange,
            'accountNumber' => $accountNumber,
            'confidence' => $confidence,
        ];
    }

    /**
     * Analyze PDF content to detect processor type
     */
    private function analyzePDFContent(string $content, string $fileName): array
    {
        $processor = null;
        $suggestedName = pathinfo($fileName, PATHINFO_FILENAME) . ' Import';
        $dateRange = null;
        $accountNumber = null;
        $confidence = 'low';

        // Try to extract text from PDF using pdftotext command if available
        $textContent = '';
        
        // Create temp file for PDF
        $tempFile = tempnam(sys_get_temp_dir(), 'pdf_analyze_');
        file_put_contents($tempFile, $content);
        
        // Try pdftotext
        $output = [];
        $returnCode = 0;
        exec("pdftotext -l 2 " . escapeshellarg($tempFile) . " - 2>/dev/null", $output, $returnCode);
        
        if ($returnCode === 0 && !empty($output)) {
            $textContent = implode("\n", $output);
        } else {
            // Fallback: search raw PDF content for patterns
            $textContent = $content;
        }
        
        // Clean up temp file
        @unlink($tempFile);

        // Detect RBL Credit Card PDF
        // Patterns: Credit card numbers (4 groups of 4 digits), "Statement Period", credit card terminology
        if (preg_match('/\d{4}\s*\*{4,}\s*\*{4,}\s*\d{4}/i', $textContent) ||
            preg_match('/credit card/i', $textContent) ||
            preg_match('/card member/i', $textContent) ||
            preg_match('/minimum payment|total amount due/i', $textContent)) {
            $processor = 'rbl_credit_card';
            $confidence = 'high';
            
            // Try to extract statement period
            if (preg_match('/Statement Period[:\s]*(\w+\s+\d{1,2},?\s*\d{4})\s*(?:to|-)\s*(\w+\s+\d{1,2},?\s*\d{4})/i', $textContent, $matches)) {
                $dateRange = $matches[2]; // End date
                // Parse to get month/year
                if (preg_match('/(\w+)\s+\d{1,2},?\s*(\d{4})/', $matches[2], $dateMatch)) {
                    $dateRange = $dateMatch[1] . ' ' . $dateMatch[2];
                }
            } elseif (preg_match('/(\w+)\s+(\d{4})\s*Statement/i', $textContent, $matches)) {
                $dateRange = $matches[1] . ' ' . $matches[2];
            }
            
            $suggestedName = 'RBL CC ' . ($dateRange ?: date('M Y'));
        }
        // Detect RBL Bank PDF
        // Patterns: "CHQ-" account numbers, "TRANSACTION INFORMATION", bank account terminology
        elseif (preg_match('/CHQ-\d+/i', $textContent) ||
                preg_match('/TRANSACTION INFORMATION/i', $textContent) ||
                preg_match('/Account #:\s*CHQ/i', $textContent) ||
                preg_match('/periodic statement|account statement/i', $textContent)) {
            $processor = 'rbl_bank';
            $confidence = 'high';
            
            // Try to extract statement period
            if (preg_match('/Period[:\s]*(\w+\s+\d{1,2},?\s*\d{4})\s*(?:to|-)\s*(\w+\s+\d{1,2},?\s*\d{4})/i', $textContent, $matches)) {
                $dateRange = $matches[2];
                if (preg_match('/(\w+)\s+\d{1,2},?\s*(\d{4})/', $matches[2], $dateMatch)) {
                    $dateRange = $dateMatch[1] . ' ' . $dateMatch[2];
                }
            } elseif (preg_match('/(\w+)\s+(\d{4})/i', $fileName, $matches)) {
                // Try filename
                $dateRange = $matches[1] . ' ' . $matches[2];
            }
            
            // Extract account number
            if (preg_match('/CHQ-(\d+)/i', $textContent, $matches)) {
                $accountNumber = 'CHQ-' . $matches[1];
            }
            
            $suggestedName = 'RBL Bank ' . ($dateRange ?: date('M Y'));
        }

        return [
            'processor' => $processor,
            'suggestedName' => $suggestedName,
            'dateRange' => $dateRange,
            'accountNumber' => $accountNumber,
            'confidence' => $confidence,
        ];
    }

    /**
     * Get month name from number
     */
    private function getMonthName(int $month): string
    {
        $months = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
            5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug',
            9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'
        ];
        return $months[$month] ?? '';
    }
}

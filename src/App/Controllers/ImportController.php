<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\ImportService;
use App\Services\EntityService;
use App\Services\TransactionMatchingService;
use App\Services\InstallationService;
use App\DAO\TransactionDAO;
use App\DAO\VendorDAO;

class ImportController extends BaseController
{
    private ImportService $importService;
    private EntityService $entityService;
    private TransactionMatchingService $matchingService;
    private TransactionDAO $transactionDAO;
    private VendorDAO $vendorDAO;
    private InstallationService $installationService;

    public function __construct(
        Twig $view,
        ImportService $importService,
        EntityService $entityService,
        TransactionMatchingService $matchingService,
        TransactionDAO $transactionDAO,
        VendorDAO $vendorDAO,
        InstallationService $installationService
    ) {
        parent::__construct($view);
        $this->importService = $importService;
        $this->entityService = $entityService;
        $this->matchingService = $matchingService;
        $this->transactionDAO = $transactionDAO;
        $this->vendorDAO = $vendorDAO;
        $this->installationService = $installationService;
    }

    /**
     * Show import form
     */
    public function showForm(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $queryParams = $this->getQueryParams($request);
        $entities = $this->entityService->getAllEntities();

        return $this->render($response, 'import/form.html.twig', [
            'user' => $user,
            'entities' => $entities,
            'accounts' => [],
            'error' => $queryParams['error'] ?? null,
            'success' => $queryParams['success'] ?? null,
            'processor' => $queryParams['processor'] ?? null,
        ]);
    }

    /**
     * Handle import submission
     */
    public function import(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $data = $this->getPostData($request);
        $processor = $data['processor'] ?? null;

        // Validate processor
        if (!$processor || !in_array($processor, ['rbl_credit_card', 'rbl_bank'])) {
            return $this->redirect($response, '/import?processor=' . urlencode($processor ?? '') . '&error=invalid_processor');
        }

        // Validate required fields
        $batchName = $data['batch_name'] ?? '';
        $accountId = (int)($data['account_id'] ?? 0);
        $importDatetime = $data['batch_import_datetime'] ?? '';

        if (empty($batchName) || empty($accountId) || empty($importDatetime)) {
            return $this->redirect($response, '/import?processor=' . urlencode($processor) . '&error=missing_fields');
        }

        // Get uploaded file
        $uploadedFiles = $request->getUploadedFiles();
        $uploadedFile = $uploadedFiles['import_file'] ?? null;

        if (!$uploadedFile) {
            return $this->redirect($response, '/import?processor=' . urlencode($processor) . '&error=no_file');
        }

        try {
            // Validate file
            $this->importService->validateUploadedFile($uploadedFile);

            // Save file
            $fileName = $uploadedFile->getClientFilename();
            $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
            $uniqueFileName = $this->importService->saveUploadedFile($uploadedFile);

            // Determine processor and import type
            $importProcessor = $this->importService->determineProcessor($processor, $fileExtension);
            $importType = $this->importService->determineImportType($fileExtension);

            // Create batch
            $batchId = $this->importService->createBatch([
                'batch_name' => $batchName,
                'account_id' => $accountId,
                'batch_import_type' => $importType,
                'import_processor' => $importProcessor,
                'batch_import_filename' => $uniqueFileName,
                'batch_import_datetime' => date('Y-m-d H:i:s', strtotime($importDatetime)),
                'user_id' => $user['user_id'],
            ]);

            return $this->redirect($response, '/import/batch/' . $batchId . '?success=uploaded');
        } catch (\Exception $e) {
            error_log('Import error: ' . $e->getMessage());
            return $this->redirect($response, '/import?processor=' . urlencode($processor) . '&error=server_error');
        }
    }

    /**
     * Show batch details
     */
    public function showBatch(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $batchId = (int)$this->getRouteArg($request, 'batch_id');
        $queryParams = $this->getQueryParams($request);

        $batch = $this->importService->getBatchByIdAndUser($batchId, $user['user_id']);

        if (!$batch) {
            return $this->redirect($response, '/dashboard?error=batch_not_found');
        }

        $transactions = $this->importService->getTransactionsByBatch($batchId);
        
        // Check if matching is available
        $matchingInfo = $this->matchingService->canMatch($batchId, $user['user_id']);
        
        // Get match statistics
        $matchStats = $this->transactionDAO->getMatchStats($batchId);
        
        // Get account type for determining income/expense logic
        $accountType = $matchingInfo['account']['account_type'] ?? 'bank';

        return $this->render($response, 'import/batch.html.twig', [
            'user' => $user,
            'batch' => $batch,
            'transactions' => $transactions,
            'can_match' => $matchingInfo['can_match'],
            'match_reason' => $matchingInfo['reason'] ?? null,
            'match_stats' => $matchStats,
            'account_type' => $accountType,
            'success' => $queryParams['success'] ?? null,
            'error' => $queryParams['error'] ?? null,
        ]);
    }

    /**
     * Process batch
     */
    public function processBatch(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $batchId = (int)$this->getRouteArg($request, 'batch_id');

        if (!$this->importService->batchBelongsToUser($batchId, $user['user_id'])) {
            return $this->redirect($response, '/dashboard?error=batch_not_found');
        }

        try {
            $this->importService->processBatch($batchId);
            return $this->redirect($response, '/import/batch/' . $batchId . '?success=processed');
        } catch (\Exception $e) {
            error_log('Batch processing error: ' . $e->getMessage());
            return $this->redirect($response, '/import/batch/' . $batchId . '?error=processing_failed');
        }
    }

    /**
     * Match batch transactions with Akaunting
     */
    public function matchBatch(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $batchId = (int)$this->getRouteArg($request, 'batch_id');

        if (!$this->importService->batchBelongsToUser($batchId, $user['user_id'])) {
            return $this->redirect($response, '/dashboard?error=batch_not_found');
        }

        try {
            $result = $this->matchingService->matchBatch($batchId, $user['user_id']);
            $successMsg = urlencode($result['message']);
            return $this->redirect($response, '/import/batch/' . $batchId . '?success=' . $successMsg);
        } catch (\Exception $e) {
            error_log('Batch matching error: ' . $e->getMessage());
            $errorMsg = urlencode('Matching failed: ' . $e->getMessage());
            return $this->redirect($response, '/import/batch/' . $batchId . '?error=' . $errorMsg);
        }
    }

    /**
     * Clear matches for a batch
     */
    public function clearMatches(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $batchId = (int)$this->getRouteArg($request, 'batch_id');

        if (!$this->importService->batchBelongsToUser($batchId, $user['user_id'])) {
            return $this->redirect($response, '/dashboard?error=batch_not_found');
        }

        try {
            $this->matchingService->clearMatches($batchId);
            return $this->redirect($response, '/import/batch/' . $batchId . '?success=Matches+cleared');
        } catch (\Exception $e) {
            error_log('Clear matches error: ' . $e->getMessage());
            return $this->redirect($response, '/import/batch/' . $batchId . '?error=Failed+to+clear+matches');
        }
    }

    /**
     * AJAX endpoint for chunked matching progress
     */
    public function matchProgress(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $batchId = (int)$this->getRouteArg($request, 'batch_id');

        if (!$this->importService->batchBelongsToUser($batchId, $user['user_id'])) {
            return $this->json($response, [
                'status' => 'error',
                'message' => 'Batch not found'
            ], 404);
        }

        try {
            $result = $this->matchingService->processMatchStep($batchId, $user['user_id']);
            return $this->json($response, $result);
        } catch (\Exception $e) {
            error_log('Match progress error: ' . $e->getMessage());
            return $this->json($response, [
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * AJAX endpoint to reset/restart matching
     */
    public function matchReset(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $batchId = (int)$this->getRouteArg($request, 'batch_id');

        if (!$this->importService->batchBelongsToUser($batchId, $user['user_id'])) {
            return $this->json($response, [
                'status' => 'error',
                'message' => 'Batch not found'
            ], 404);
        }

        try {
            // Clear existing matches and reset job
            $this->matchingService->clearMatches($batchId);
            $this->matchingService->resetMatchJob($batchId, $user['user_id']);
            return $this->json($response, ['status' => 'reset', 'message' => 'Ready to start matching']);
        } catch (\Exception $e) {
            error_log('Match reset error: ' . $e->getMessage());
            return $this->json($response, [
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Re-import a batch (delete existing transactions and re-process)
     */
    public function reimportBatch(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $batchId = (int)$this->getRouteArg($request, 'batch_id');

        if (!$this->importService->batchBelongsToUser($batchId, $user['user_id'])) {
            return $this->redirect($response, '/dashboard?error=batch_not_found');
        }

        try {
            // Clear matches first
            $this->matchingService->clearMatches($batchId);
            
            // Delete existing transactions and reset batch status
            $this->importService->resetBatchForReimport($batchId);
            
            // Re-process the batch
            $result = $this->importService->processBatch($batchId);
            
            $successMsg = urlencode("Re-imported successfully! {$result['transaction_count']} transactions extracted.");
            return $this->redirect($response, '/import/batch/' . $batchId . '?success=' . $successMsg);
        } catch (\Exception $e) {
            error_log('Reimport error: ' . $e->getMessage());
            $errorMsg = urlencode('Re-import failed: ' . $e->getMessage());
            return $this->redirect($response, '/import/batch/' . $batchId . '?error=' . $errorMsg);
        }
    }

    /**
     * Push a transaction to Akaunting
     */
    public function pushTransaction(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $batchId = (int)$this->getRouteArg($request, 'batch_id');

        if (!$this->importService->batchBelongsToUser($batchId, $user['user_id'])) {
            return $this->json($response, [
                'success' => false,
                'message' => 'Batch not found'
            ], 404);
        }

        try {
            $body = json_decode($request->getBody()->getContents(), true);
            
            $result = $this->matchingService->pushToAkaunting(
                $batchId,
                (int)$body['transaction_id'],
                $body['date'],
                $body['reference'] ?? '',
                $body['contact'] ?? '',
                !empty($body['contact_id']) ? (int)$body['contact_id'] : null,
                $body['type'],
                (float)$body['amount'],
                (int)($body['category_id'] ?? 1),
                $body['category_name'] ?? null,
                $body['payment_method'] ?? 'bank_transfer',
                $this->vendorDAO
            );
            
            return $this->json($response, $result);
        } catch (\Exception $e) {
            error_log('Push transaction error: ' . $e->getMessage());
            return $this->json($response, [
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get vendors, categories, and payment methods for a batch (from cache or Akaunting)
     */
    public function getVendors(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $batchId = (int)$this->getRouteArg($request, 'batch_id');
        $queryParams = $this->getQueryParams($request);
        $refresh = isset($queryParams['refresh']);
        $description = $queryParams['description'] ?? null;

        if (!$this->importService->batchBelongsToUser($batchId, $user['user_id'])) {
            return $this->json($response, ['success' => false, 'message' => 'Batch not found'], 404);
        }

        try {
            // Get the batch's installation
            $matchingInfo = $this->matchingService->canMatch($batchId, $user['user_id']);
            if (!$matchingInfo['can_match']) {
                return $this->json($response, ['success' => false, 'message' => $matchingInfo['reason']], 400);
            }

            $installation = $matchingInfo['installation'];
            $installationId = $installation['installation_id'];
            $cacheMaxAge = 86400; // 24 hours - vendors/categories don't change often

            // Check if we need to refresh vendor cache
            $lastVendorCached = $this->vendorDAO->getLastCacheTime($installationId, 'vendor');
            $vendorCacheAge = $lastVendorCached ? (time() - strtotime($lastVendorCached)) : PHP_INT_MAX;

            if ($refresh || $vendorCacheAge > $cacheMaxAge) {
                // Fetch vendors from Akaunting and cache
                $contacts = $this->installationService->fetchAkauntingContacts(
                    $installationId, 
                    $user['user_id'], 
                    'vendor'
                );
                $this->vendorDAO->cacheContacts($installationId, $contacts);
            }

            // Check if we need to refresh customer cache
            $lastCustomerCached = $this->vendorDAO->getLastCacheTime($installationId, 'customer');
            $customerCacheAge = $lastCustomerCached ? (time() - strtotime($lastCustomerCached)) : PHP_INT_MAX;

            if ($refresh || $customerCacheAge > $cacheMaxAge) {
                // Fetch customers from Akaunting and cache
                $customers = $this->installationService->fetchAkauntingContacts(
                    $installationId, 
                    $user['user_id'], 
                    'customer'
                );
                $this->vendorDAO->cacheContacts($installationId, $customers);
            }

            // Check if we need to refresh category cache
            $lastCategoryCached = $this->vendorDAO->getLastCategoryCacheTime($installationId);
            $categoryCacheAge = $lastCategoryCached ? (time() - strtotime($lastCategoryCached)) : PHP_INT_MAX;

            if ($refresh || $categoryCacheAge > $cacheMaxAge) {
                // Fetch categories from Akaunting and cache
                $categories = $this->installationService->fetchAkauntingCategories(
                    $installationId, 
                    $user['user_id']
                );
                $this->vendorDAO->cacheCategories($installationId, $categories);
            }

            // Check if we need to refresh payment method cache
            $lastPaymentCached = $this->vendorDAO->getLastPaymentMethodCacheTime($installationId);
            $paymentCacheAge = $lastPaymentCached ? (time() - strtotime($lastPaymentCached)) : PHP_INT_MAX;

            if ($refresh || $paymentCacheAge > $cacheMaxAge) {
                // Fetch payment methods from Akaunting and cache
                $paymentMethods = $this->installationService->fetchAkauntingPaymentMethods(
                    $installationId, 
                    $user['user_id']
                );
                $this->vendorDAO->cachePaymentMethods($installationId, $paymentMethods);
            }

            // Get cached data
            $vendors = $this->vendorDAO->getContactsByInstallation($installationId, 'vendor');
            $customers = $this->vendorDAO->getContactsByInstallation($installationId, 'customer');
            $categories = $this->vendorDAO->getCategoriesByInstallation($installationId);
            $paymentMethods = $this->vendorDAO->getPaymentMethodsByInstallation($installationId);

            // Check for suggested mapping based on description (vendor + category + payment method)
            $suggested = null;
            if ($description) {
                $mapping = $this->vendorDAO->findBestTransactionMapping($installationId, $description);
                if ($mapping) {
                    $suggested = [
                        'vendor_id' => $mapping['akaunting_contact_id'],
                        'vendor_name' => $mapping['contact_name'],
                        'category_id' => $mapping['akaunting_category_id'],
                        'category_name' => $mapping['category_name'],
                        'payment_method' => $mapping['payment_method'],
                        'usage_count' => $mapping['usage_count']
                    ];
                }
            }

            return $this->json($response, [
                'success' => true,
                'vendors' => $vendors,
                'customers' => $customers,
                'categories' => $categories,
                'payment_methods' => $paymentMethods,
                'suggested' => $suggested,
                'cached_at' => $this->vendorDAO->getLastCacheTime($installationId, 'vendor')
            ]);
        } catch (\Exception $e) {
            error_log('Get vendors error: ' . $e->getMessage());
            return $this->json($response, [
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}


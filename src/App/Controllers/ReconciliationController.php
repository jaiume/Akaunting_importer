<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\TransactionMatchingService;
use App\Services\EntityService;
use App\DAO\AccountDAO;

class ReconciliationController extends BaseController
{
    private TransactionMatchingService $matchingService;
    private EntityService $entityService;
    private AccountDAO $accountDAO;

    public function __construct(
        Twig $view,
        TransactionMatchingService $matchingService,
        EntityService $entityService,
        AccountDAO $accountDAO
    ) {
        parent::__construct($view);
        $this->matchingService = $matchingService;
        $this->entityService = $entityService;
        $this->accountDAO = $accountDAO;
    }

    /**
     * Show account reconciliation page (loads immediately with basic info)
     */
    public function showAccountReconciliation(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $accountId = (int)$this->getRouteArg($request, 'account_id');
        $queryParams = $this->getQueryParams($request);

        // Get account info
        $account = $this->accountDAO->findById($accountId);
        if (!$account) {
            return $this->redirect($response, '/dashboard?error=Account+not+found');
        }

        // Get entity info
        $entity = $this->entityService->getEntityById($account['entity_id']);

        // Check if we can reconcile
        $canReconcile = $this->matchingService->canReconcileAccount($accountId, $user['user_id']);
        
        if (!$canReconcile['can_reconcile']) {
            return $this->render($response, 'reconciliation/account.html.twig', [
                'user' => $user,
                'account' => $account,
                'entity' => $entity,
                'can_reconcile' => false,
                'reconcile_reason' => $canReconcile['reason'],
                'date_range' => ['start' => null, 'end' => null],
                'stats' => [
                    'total_imported' => 0,
                    'matched' => 0,
                    'missing' => 0,
                    'orphans' => 0
                ],
                'success' => $queryParams['success'] ?? null,
                'error' => $queryParams['error'] ?? null,
            ]);
        }

        try {
            // Get basic reconciliation info (without fetching Akaunting data)
            $info = $this->matchingService->getAccountReconciliationInfo($accountId, $user['user_id']);

            return $this->render($response, 'reconciliation/account.html.twig', [
                'user' => $user,
                'account' => $account,
                'entity' => $entity,
                'installation' => $info['installation'],
                'can_reconcile' => true,
                'date_range' => $info['date_range'],
                'stats' => $info['stats'],
                'success' => $queryParams['success'] ?? null,
                'error' => $queryParams['error'] ?? null,
            ]);
        } catch (\Exception $e) {
            error_log('Reconciliation error: ' . $e->getMessage());
            return $this->render($response, 'reconciliation/account.html.twig', [
                'user' => $user,
                'account' => $account,
                'entity' => $entity,
                'can_reconcile' => false,
                'reconcile_reason' => $e->getMessage(),
                'date_range' => ['start' => null, 'end' => null],
                'stats' => [
                    'total_imported' => 0,
                    'matched' => 0,
                    'missing' => 0,
                    'orphans' => 0
                ],
                'error' => 'Failed to load reconciliation info: ' . $e->getMessage(),
                'success' => null,
            ]);
        }
    }

    /**
     * AJAX endpoint for fetching Akaunting transactions (chunked)
     */
    public function fetchProgress(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $accountId = (int)$this->getRouteArg($request, 'account_id');

        try {
            $body = json_decode($request->getBody()->getContents(), true);
            $page = (int)($body['page'] ?? 1);
            $startDate = $body['start_date'] ?? null;
            $endDate = $body['end_date'] ?? null;

            $result = $this->matchingService->fetchReconciliationPage(
                $accountId,
                $user['user_id'],
                $page,
                $startDate,
                $endDate
            );

            return $this->json($response, $result);
        } catch (\Exception $e) {
            error_log('Reconciliation fetch error: ' . $e->getMessage());
            return $this->json($response, [
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * AJAX endpoint for performing reconciliation after fetching
     */
    public function reconcile(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $accountId = (int)$this->getRouteArg($request, 'account_id');

        try {
            $body = json_decode($request->getBody()->getContents(), true);
            $akauntingTransactions = $body['akaunting_transactions'] ?? [];

            $result = $this->matchingService->performReconciliation($accountId, $akauntingTransactions);

            // Merge imported transactions and orphans into a combined list sorted by date
            $combinedTransactions = [];
            
            // Add imported transactions
            foreach ($result['imported_transactions'] as $txn) {
                $txn['_type'] = 'imported';
                $txn['_sort_date'] = $txn['transaction_date'];
                $combinedTransactions[] = $txn;
            }
            
            // Add orphans
            foreach ($result['orphan_transactions'] as $orphan) {
                $orphan['_type'] = 'orphan';
                $orphan['_sort_date'] = $orphan['date'];
                $combinedTransactions[] = $orphan;
            }
            
            // Sort by date
            usort($combinedTransactions, function($a, $b) {
                $dateCompare = strcmp($a['_sort_date'], $b['_sort_date']);
                if ($dateCompare !== 0) {
                    return $dateCompare;
                }
                // Same date - put imported transactions first
                if ($a['_type'] !== $b['_type']) {
                    return $a['_type'] === 'imported' ? -1 : 1;
                }
                return 0;
            });

            return $this->json($response, [
                'status' => 'complete',
                'combined_transactions' => $combinedTransactions,
                'stats' => $result['stats']
            ]);
        } catch (\Exception $e) {
            error_log('Reconciliation error: ' . $e->getMessage());
            return $this->json($response, [
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}

<?php

namespace App\Controllers;

use App\Services\EntityService;
use App\Services\ReceiptService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;
use Slim\Views\Twig;

class ReceiptController extends BaseController
{
    private ReceiptService $receipts;
    private EntityService $entityService;

    public function __construct(Twig $view, ReceiptService $receipts, EntityService $entityService)
    {
        parent::__construct($view);
        $this->receipts = $receipts;
        $this->entityService = $entityService;
    }

    public function index(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $query = $this->getQueryParams($request);
        $showArchived = isset($query['show_archived']) && $query['show_archived'] === '1';
        $batches = $this->receipts->listForUser((int)$user['user_id'], $showArchived);

        $grouped = [];
        foreach ($batches as $batch) {
            $entityId = $batch['entity_id'];
            $accountId = $batch['account_id'];
            if (!isset($grouped[$entityId])) {
                $grouped[$entityId] = [
                    'entity_name' => $batch['entity_name'],
                    'accounts' => [],
                ];
            }
            if (!isset($grouped[$entityId]['accounts'][$accountId])) {
                $grouped[$entityId]['accounts'][$accountId] = [
                    'account_name' => $batch['account_name'],
                    'batches' => [],
                ];
            }
            $grouped[$entityId]['accounts'][$accountId]['batches'][] = $batch;
        }

        return $this->render($response, 'receipts/index.html.twig', [
            'user' => $user,
            'grouped_batches' => $grouped,
            'show_archived' => $showArchived,
            'success' => $query['success'] ?? null,
            'error' => $query['error'] ?? null,
        ]);
    }

    public function showForm(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $query = $this->getQueryParams($request);

        return $this->render($response, 'receipts/form.html.twig', [
            'user' => $user,
            'entities' => $this->entityService->getAllEntities(),
            'error' => $query['error'] ?? null,
        ]);
    }

    public function import(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $data = $this->getPostData($request);
        set_time_limit(0);

        try {
            $uploaded = $request->getUploadedFiles()['import_file'] ?? null;
            $batchId = $this->receipts->import(
                (int)$user['user_id'],
                (string)($data['batch_name'] ?? ''),
                (int)($data['account_id'] ?? 0),
                $uploaded
            );
            return $this->redirect($response, '/receipts/batch/' . $batchId);
        } catch (\InvalidArgumentException $e) {
            return $this->redirect($response, '/receipts/import?error=' . urlencode($e->getMessage()));
        } catch (\Throwable $e) {
            error_log('Receipt import error: ' . $e->getMessage());
            return $this->redirect($response, '/receipts/import?error=' . urlencode('Could not start the receipt import.'));
        }
    }

    public function showBatch(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $batchId = (int)$this->getRouteArg($request, 'batch_id');
        $query = $this->getQueryParams($request);

        try {
            $batch = $this->receipts->requireBatch($batchId, (int)$user['user_id']);
        } catch (\RuntimeException $e) {
            return $this->redirect($response, '/receipts?error=' . urlencode('Batch not found'));
        }

        $items = $this->receipts->itemsForBatch($batchId);
        $ref = $this->receipts->referenceData($batch, (int)$user['user_id']);
        $selected = $this->selectedItem($items, isset($query['item']) ? (int)$query['item'] : 0);

        return $this->render($response, 'receipts/batch.html.twig', [
            'user' => $user,
            'batch' => $batch,
            'items' => $items,
            'selected' => $selected,
            'vendors' => $ref['vendors'],
            'categories' => $ref['categories'],
            'payment_methods' => $ref['payment_methods'],
            'has_installation' => $ref['installation'] !== null,
            'account_linked' => !empty($batch['akaunting_account_id']),
            'success' => $query['success'] ?? null,
            'error' => $query['error'] ?? null,
        ]);
    }

    public function file(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $batchId = (int)$this->getRouteArg($request, 'batch_id');

        try {
            $batch = $this->receipts->requireBatch($batchId, (int)$user['user_id']);
            $path = $this->receipts->absolutePath($batch);
        } catch (\Throwable $e) {
            $response->getBody()->write('Receipt PDF not found');
            return $response->withStatus(404);
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $response->getBody()->write('Receipt PDF not found');
            return $response->withStatus(404);
        }

        $downloadName = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)($batch['original_filename'] ?: 'receipts.pdf')) ?: 'receipts.pdf';

        return $response
            ->withBody(new Stream($handle))
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Length', (string)filesize($path))
            ->withHeader('Content-Disposition', 'inline; filename="' . $downloadName . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, max-age=0');
    }

    public function analyze(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $batchId = (int)$this->getRouteArg($request, 'batch_id');
        $data = $request->getParsedBody();
        if (!is_array($data)) {
            $data = [];
        }

        try {
            $jobId = $this->receipts->startImageAnalysis(
                $batchId,
                (int)$user['user_id'],
                (int)($data['page'] ?? 0),
                (string)($data['image'] ?? '')
            );
            return $this->json($response, [
                'success' => true,
                'pending' => true,
                'job_id' => $jobId,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            error_log('Receipt analyze error: ' . $e->getMessage());
            return $this->json($response, ['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function analyzeStatus(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $batchId = (int)$this->getRouteArg($request, 'batch_id');
        $jobId = (string)$this->getRouteArg($request, 'job_id');

        try {
            $status = $this->receipts->imageAnalysisStatus($batchId, (int)$user['user_id'], $jobId);
            return $this->json($response, array_merge(['success' => true], $status));
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage()], 404);
        }
    }

    public function suggestion(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $batchId = (int)$this->getRouteArg($request, 'batch_id');
        $vendor = trim((string)($this->getQueryParams($request)['vendor'] ?? ''));

        try {
            $batch = $this->receipts->requireBatch($batchId, (int)$user['user_id']);
        } catch (\RuntimeException $e) {
            return $this->json($response, ['success' => false, 'message' => 'Batch not found'], 404);
        }

        $suggestion = $this->receipts->suggest($batch, (int)$user['user_id'], $vendor);
        return $this->json($response, ['success' => true, 'suggested' => $suggestion]);
    }

    public function saveItem(Request $request, Response $response): Response
    {
        return $this->itemAction($request, $response, 'save');
    }

    public function pushItem(Request $request, Response $response): Response
    {
        return $this->itemAction($request, $response, 'push');
    }

    public function skipItem(Request $request, Response $response): Response
    {
        return $this->itemAction($request, $response, 'skip');
    }

    public function retry(Request $request, Response $response): Response
    {
        $batchId = (int)$this->getRouteArg($request, 'batch_id');
        return $this->redirect(
            $response,
            '/receipts/batch/' . $batchId . '?error=' . urlencode('Read one page, or a selected area of a page, from the viewer.')
        );
    }

    public function archive(Request $request, Response $response): Response
    {
        return $this->batchAction($request, $response, 'archive');
    }

    public function unarchive(Request $request, Response $response): Response
    {
        return $this->batchAction($request, $response, 'unarchive');
    }

    public function delete(Request $request, Response $response): Response
    {
        return $this->batchAction($request, $response, 'delete');
    }

    private function wantsJson(Request $request): bool
    {
        return str_contains($request->getHeaderLine('Accept'), 'application/json');
    }

    private function itemAction(Request $request, Response $response, string $action): Response
    {
        $user = $this->getUser($request);
        $batchId = (int)$this->getRouteArg($request, 'batch_id');
        $itemId = (int)$this->getRouteArg($request, 'item_id');
        $back = '/receipts/batch/' . $batchId . '?item=' . $itemId;

        try {
            if ($action === 'save') {
                $item = $this->receipts->saveItem($batchId, $itemId, (int)$user['user_id'], $this->getPostData($request));
                if ($this->wantsJson($request)) {
                    return $this->json($response, [
                        'success' => true,
                        'message' => 'Draft updated.',
                        'item' => [
                            'receipt_item_id' => (int)$item['receipt_item_id'],
                            'receipt_date' => $item['receipt_date'],
                            'vendor' => $item['matched_contact_name'] ?: ($item['vendor_raw'] ?: null),
                            'amount' => $item['amount'],
                            'category' => $item['category_name'],
                            'status' => $item['status'],
                        ],
                    ]);
                }
                $message = 'Draft updated.';
            } elseif ($action === 'skip') {
                $this->receipts->skipItem($batchId, $itemId, (int)$user['user_id']);
                $message = 'Page skipped.';
            } else {
                $result = $this->receipts->pushItem($batchId, $itemId, (int)$user['user_id'], $this->getPostData($request));
                $message = 'Pushed ' . $result['transaction_number'] . '.';
            }
            return $this->redirect($response, $back . '&success=' . urlencode($message));
        } catch (\InvalidArgumentException $e) {
            return $this->redirect($response, $back . '&error=' . urlencode($e->getMessage()));
        } catch (\RuntimeException $e) {
            $target = str_contains($e->getMessage(), 'not found') ? '/receipts?error=' . urlencode($e->getMessage()) : $back . '&error=' . urlencode($e->getMessage());
            return $this->redirect($response, $target);
        }
    }

    private function batchAction(Request $request, Response $response, string $action): Response
    {
        $user = $this->getUser($request);
        $batchId = (int)$this->getRouteArg($request, 'batch_id');

        try {
            if ($action === 'delete') {
                $this->receipts->deleteBatch($batchId, (int)$user['user_id']);
                return $this->redirect($response, '/receipts?success=' . urlencode('Batch deleted.'));
            }
            if ($action === 'archive') {
                $this->receipts->archiveBatch($batchId, (int)$user['user_id']);
                return $this->redirect($response, '/receipts?success=' . urlencode('Batch archived.'));
            }
            $this->receipts->unarchiveBatch($batchId, (int)$user['user_id']);
            return $this->redirect($response, '/receipts/batch/' . $batchId . '?success=' . urlencode('Batch unarchived.'));
        } catch (\RuntimeException $e) {
            return $this->redirect($response, '/receipts?error=' . urlencode('Batch not found'));
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function selectedItem(array $items, int $requestedId): ?array
    {
        foreach ($items as $item) {
            if ((int)$item['receipt_item_id'] === $requestedId) {
                return $item;
            }
        }
        foreach ($items as $item) {
            if ($item['status'] === 'pending') {
                return $item;
            }
        }
        return $items[0] ?? null;
    }
}

<?php

namespace App\Services;

use App\DAO\BatchDAO;
use App\DAO\TransactionDAO;
use App\DAO\MatchJobDAO;
use App\Processors\ProcessorFactory;

class ImportService
{
    private BatchDAO $batchDAO;
    private TransactionDAO $transactionDAO;
    private MatchJobDAO $matchJobDAO;
    private ProcessorFactory $processorFactory;
    private string $uploadDir;

    public function __construct(
        BatchDAO $batchDAO, 
        TransactionDAO $transactionDAO,
        MatchJobDAO $matchJobDAO,
        ProcessorFactory $processorFactory,
        string $uploadDir
    ) {
        $this->batchDAO = $batchDAO;
        $this->transactionDAO = $transactionDAO;
        $this->matchJobDAO = $matchJobDAO;
        $this->processorFactory = $processorFactory;
        $this->uploadDir = $uploadDir;
    }

    /**
     * Get batch by ID and user
     */
    public function getBatchByIdAndUser(int $batchId, int $userId): ?array
    {
        return $this->batchDAO->findByIdAndUser($batchId, $userId);
    }

    /**
     * Get transactions for batch
     */
    public function getTransactionsByBatch(int $batchId): array
    {
        return $this->transactionDAO->findByBatchId($batchId);
    }

    /**
     * Create import batch
     */
    public function createBatch(array $data): int
    {
        return $this->batchDAO->create($data);
    }

    /**
     * Determine processor name from processor type and file extension
     */
    public function determineProcessor(string $processorType, string $fileExtension): string
    {
        $validProcessors = ['rbl_credit_card', 'rbl_bank'];
        $validExtensions = ['csv', 'pdf'];
        
        if (!in_array($processorType, $validProcessors)) {
            throw new \Exception('Invalid processor type', 400);
        }
        
        $ext = strtolower($fileExtension);
        if (!in_array($ext, $validExtensions)) {
            throw new \Exception('Invalid file type. Expected PDF or CSV', 400);
        }
        
        return $processorType . '_' . $ext;
    }

    /**
     * Determine import type from file extension
     */
    public function determineImportType(string $fileExtension): string
    {
        return strtoupper($fileExtension);
    }

    /**
     * Validate uploaded file
     * @throws \Exception on validation failure
     */
    public function validateUploadedFile($uploadedFile, int $maxSize = 10485760): void
    {
        if (!$uploadedFile || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
            throw new \Exception('File upload error', 400);
        }

        if ($uploadedFile->getSize() > $maxSize) {
            throw new \Exception('File too large (max 10MB)', 400);
        }

        $fileName = $uploadedFile->getClientFilename();
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        if (!in_array($ext, ['pdf', 'csv'])) {
            throw new \Exception('Invalid file type. Expected PDF or CSV', 400);
        }
    }

    /**
     * Save uploaded file and return filename
     */
    public function saveUploadedFile($uploadedFile): string
    {
        $fileName = $uploadedFile->getClientFilename();
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        $uniqueFileName = time() . '_' . uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
        
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
        
        $filePath = $this->uploadDir . '/' . $uniqueFileName;
        $uploadedFile->moveTo($filePath);
        
        return $uniqueFileName;
    }

    /**
     * Process batch
     */
    public function processBatch(int $batchId): array
    {
        return $this->processorFactory->processBatch($batchId);
    }

    /**
     * Check if batch belongs to user
     */
    public function batchBelongsToUser(int $batchId, int $userId): bool
    {
        return $this->batchDAO->belongsToUser($batchId, $userId);
    }

    /**
     * Get upload directory
     */
    public function getUploadDir(): string
    {
        return $this->uploadDir;
    }

    /**
     * Reset batch for re-import (delete existing transactions and reset status)
     */
    public function resetBatchForReimport(int $batchId): void
    {
        // Delete all existing transactions for this batch
        $this->transactionDAO->deleteByBatchId($batchId);
        
        // Reset batch status to pending (this also clears error message)
        $this->batchDAO->updateStatus($batchId, 'pending', null);
        
        // Reset transaction count to 0
        $this->batchDAO->updateTransactionCount($batchId);
    }

    /**
     * Get batches by user with optional archive filter
     */
    public function getBatchesByUser(int $userId, ?int $limit = null, bool $includeArchived = false): array
    {
        return $this->batchDAO->findByUser($userId, $limit, $includeArchived);
    }

    /**
     * Delete a batch and its associated data
     */
    public function deleteBatch(int $batchId): bool
    {
        // Get batch info for file deletion
        $batch = $this->batchDAO->findById($batchId);
        if (!$batch) {
            return false;
        }

        // Delete match jobs for this batch
        $this->matchJobDAO->deleteByBatch($batchId);

        // Delete the uploaded file
        $filePath = $this->uploadDir . '/' . $batch['batch_import_filename'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // Delete the batch (cascades to transactions due to FK constraint)
        return $this->batchDAO->delete($batchId);
    }

    /**
     * Archive a batch
     */
    public function archiveBatch(int $batchId): bool
    {
        return $this->batchDAO->archive($batchId);
    }

    /**
     * Unarchive a batch
     */
    public function unarchiveBatch(int $batchId): bool
    {
        return $this->batchDAO->unarchive($batchId);
    }
}




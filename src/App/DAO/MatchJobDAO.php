<?php

namespace App\DAO;

use PDO;

class MatchJobDAO
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Find or create a job for a batch
     */
    public function findOrCreate(int $batchId, int $userId): array
    {
        // Try to find existing job
        $stmt = $this->db->prepare("
            SELECT * FROM match_jobs 
            WHERE batch_id = :batch_id AND user_id = :user_id
        ");
        $stmt->execute(['batch_id' => $batchId, 'user_id' => $userId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($job) {
            return $job;
        }

        // Create new job
        $stmt = $this->db->prepare("
            INSERT INTO match_jobs (batch_id, user_id, status, current_page, akaunting_transactions)
            VALUES (:batch_id, :user_id, 'pending', 0, '[]')
        ");
        $stmt->execute(['batch_id' => $batchId, 'user_id' => $userId]);

        return $this->findById((int)$this->db->lastInsertId());
    }

    /**
     * Find job by ID
     */
    public function findById(int $jobId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM match_jobs WHERE job_id = :job_id");
        $stmt->execute(['job_id' => $jobId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Find job by batch and user
     */
    public function findByBatchAndUser(int $batchId, int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM match_jobs 
            WHERE batch_id = :batch_id AND user_id = :user_id
        ");
        $stmt->execute(['batch_id' => $batchId, 'user_id' => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Update job status and progress
     */
    public function updateProgress(
        int $jobId,
        string $status,
        int $currentPage,
        ?int $totalPages = null,
        ?array $akauntingTransactions = null
    ): bool {
        $sql = "UPDATE match_jobs SET status = :status, current_page = :current_page";
        $params = [
            'job_id' => $jobId,
            'status' => $status,
            'current_page' => $currentPage,
        ];

        if ($totalPages !== null) {
            $sql .= ", total_pages = :total_pages";
            $params['total_pages'] = $totalPages;
        }

        if ($akauntingTransactions !== null) {
            $sql .= ", akaunting_transactions = :akaunting_transactions";
            $params['akaunting_transactions'] = json_encode($akauntingTransactions);
        }

        $sql .= " WHERE job_id = :job_id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Mark job as complete
     */
    public function markComplete(int $jobId, int $matchedCount, int $totalTransactions): bool
    {
        $stmt = $this->db->prepare("
            UPDATE match_jobs 
            SET status = 'complete', 
                matched_count = :matched_count, 
                total_transactions = :total_transactions
            WHERE job_id = :job_id
        ");
        return $stmt->execute([
            'job_id' => $jobId,
            'matched_count' => $matchedCount,
            'total_transactions' => $totalTransactions,
        ]);
    }

    /**
     * Mark job as error
     */
    public function markError(int $jobId, string $errorMessage): bool
    {
        $stmt = $this->db->prepare("
            UPDATE match_jobs 
            SET status = 'error', error_message = :error_message
            WHERE job_id = :job_id
        ");
        return $stmt->execute([
            'job_id' => $jobId,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Reset job for retry
     */
    public function reset(int $jobId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE match_jobs 
            SET status = 'pending', 
                current_page = 0, 
                total_pages = NULL,
                akaunting_transactions = '[]',
                matched_count = 0,
                error_message = NULL
            WHERE job_id = :job_id
        ");
        return $stmt->execute(['job_id' => $jobId]);
    }

    /**
     * Delete job
     */
    public function delete(int $jobId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM match_jobs WHERE job_id = :job_id");
        return $stmt->execute(['job_id' => $jobId]);
    }

    /**
     * Delete job by batch
     */
    public function deleteByBatch(int $batchId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM match_jobs WHERE batch_id = :batch_id");
        return $stmt->execute(['batch_id' => $batchId]);
    }

    /**
     * Get stored Akaunting transactions as array
     */
    public function getAkauntingTransactions(int $jobId): array
    {
        $job = $this->findById($jobId);
        if (!$job || empty($job['akaunting_transactions'])) {
            return [];
        }
        return json_decode($job['akaunting_transactions'], true) ?: [];
    }

    /**
     * Append transactions to job
     */
    public function appendTransactions(int $jobId, array $newTransactions): bool
    {
        $existing = $this->getAkauntingTransactions($jobId);
        $merged = array_merge($existing, $newTransactions);
        
        $stmt = $this->db->prepare("
            UPDATE match_jobs 
            SET akaunting_transactions = :transactions
            WHERE job_id = :job_id
        ");
        return $stmt->execute([
            'job_id' => $jobId,
            'transactions' => json_encode($merged),
        ]);
    }
}









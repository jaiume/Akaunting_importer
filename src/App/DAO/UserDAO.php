<?php

namespace App\DAO;

use PDO;

class UserDAO
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findApproved(): array
    {
        $stmt = $this->db->query("
            SELECT u.user_id, u.email, u.is_approved, u.approved_at, u.created_at, approver.email AS approved_by_email
            FROM users u
            LEFT JOIN users approver ON approver.user_id = u.approved_by
            WHERE u.is_approved = 1
            ORDER BY u.email
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findRecentUnapproved(int $limit = 25): array
    {
        $stmt = $this->db->prepare("
            SELECT user_id, email, created_at
            FROM users
            WHERE is_approved = 0
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue('limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countUnapproved(): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM users WHERE is_approved = 0")->fetchColumn();
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute(['email' => strtolower(trim($email))]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }

    public function findApprovedById(int $userId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE user_id = :user_id AND is_approved = 1");
        $stmt->execute(['user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }

    public function approveOrCreate(string $email, int $approvedByUserId): int
    {
        $email = strtolower(trim($email));
        $existing = $this->findByEmail($email);

        if ($existing) {
            $stmt = $this->db->prepare("
                UPDATE users
                SET is_approved = 1,
                    approved_by = :approved_by,
                    approved_at = NOW()
                WHERE user_id = :user_id
            ");
            $stmt->execute([
                'approved_by' => $approvedByUserId,
                'user_id' => $existing['user_id'],
            ]);

            return (int)$existing['user_id'];
        }

        $stmt = $this->db->prepare("
            INSERT INTO users (email, is_approved, approved_by, approved_at)
            VALUES (:email, 1, :approved_by, NOW())
        ");
        $stmt->execute([
            'email' => $email,
            'approved_by' => $approvedByUserId,
        ]);

        return (int)$this->db->lastInsertId();
    }
}

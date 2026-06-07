<?php

namespace App\Services;

use App\DAO\UserDAO;

class UserService
{
    private UserDAO $userDAO;

    public function __construct(UserDAO $userDAO)
    {
        $this->userDAO = $userDAO;
    }

    public function getUsersOverview(): array
    {
        return [
            'approved_users' => $this->userDAO->findApproved(),
            'unapproved_count' => $this->userDAO->countUnapproved(),
            'recent_unapproved_users' => $this->userDAO->findRecentUnapproved(25),
        ];
    }

    public function addApprovedUser(string $email, int $approvedByUserId): int
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \Exception('Valid email is required', 400);
        }

        $approver = $this->userDAO->findApprovedById($approvedByUserId);
        if (!$approver) {
            throw new \Exception('Approver must be an approved user', 403);
        }

        return $this->userDAO->approveOrCreate($email, $approvedByUserId);
    }
}

<?php

namespace App\Services;

use PDO;
use DateTime;

class AuthenticationService
{
    private $db;
    private $config;
    
    public function __construct(PDO $db, ConfigService $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Get user ID if the email exists in the system
     * Returns null if user doesn't exist
     */
    private function getUserByEmail(string $email): ?int
    {
        try {
            $stmt = $this->db->prepare("
                SELECT user_id 
                FROM users 
                WHERE email = :email
            ");
            $stmt->execute(['email' => $email]);
            
            if ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
                return $user['user_id'];
            }

            return null;
        } catch (\Exception $e) {
            // Log error here
            return null;
        }
    }

    /**
     * Generate and store authentication token
     */
    public function generateToken(string $email): ?string
    {
        try {
            // Check if user exists
            $userId = $this->getUserByEmail($email);
            if (!$userId) {
                throw new \Exception('User not found');
            }

            // Generate random token
            $token = bin2hex(random_bytes($this->config::get('auth.token_length', 32) / 2));
            
            // Calculate expiry time
            $expirySeconds = $this->config::get('auth.token_expiry', 604800);
            $expiry = (new DateTime())->modify("+{$expirySeconds} seconds");
            
            // Store in database
            $stmt = $this->db->prepare("
                INSERT INTO auth_tokens (user_id, token, expiry)
                VALUES (:user_id, :token, :expiry)
            ");
            
            $stmt->execute([
                'user_id' => $userId,
                'token' => password_hash($token, PASSWORD_DEFAULT),
                'expiry' => $expiry->format('Y-m-d H:i:s')
            ]);

            return $token;
        } catch (\Exception $e) {
            // Log error here
            return null;
        }
    }

    /**
     * Verify token and return user data if valid
     */
    public function verifyToken(string $token): ?array
    {
        try {
            // Clean up expired tokens first
            $this->cleanupExpiredTokens();
            
            // Find valid token
            $stmt = $this->db->prepare("
                SELECT t.token, t.expiry, t.user_id, u.email, u.name, u.is_admin, u.unifi_site_name
                FROM auth_tokens t
                JOIN users u ON t.user_id = u.user_id
                WHERE t.expiry > NOW()
                ORDER BY t.created_at DESC
            ");
            $stmt->execute();
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (password_verify($token, $row['token'])) {
                    return [
                        'user_id' => $row['user_id'],
                        'email' => $row['email'],
                        'name' => $row['name'],
                        'is_admin' => $row['is_admin'],
                        'unifi_site_name' => $row['unifi_site_name']
                    ];
                }
            }
            
            return null;
        } catch (\Exception $e) {
            // Log error here
            return null;
        }
    }

    /**
     * Extend token expiry
     */
    public function extendTokenExpiry(string $token): bool
    {
        try {
            // Find the token by verifying it first (since it's hashed)
            $stmt = $this->db->prepare("
                SELECT token_id, token
                FROM auth_tokens 
                WHERE expiry > NOW()
                ORDER BY created_at DESC
            ");
            $stmt->execute();
            
            $tokenId = null;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (password_verify($token, $row['token'])) {
                    $tokenId = $row['token_id'];
                    break;
                }
            }
            
            if (!$tokenId) {
                return false;
            }
            
            // Update expiry by token_id
            $expirySeconds = $this->config::get('auth.token_expiry', 604800);
            $newExpiry = (new DateTime())->modify("+{$expirySeconds} seconds");

            $updateStmt = $this->db->prepare("
                UPDATE auth_tokens 
                SET expiry = :expiry 
                WHERE token_id = :token_id
            ");

            return $updateStmt->execute([
                'expiry' => $newExpiry->format('Y-m-d H:i:s'),
                'token_id' => $tokenId
            ]);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Delete used or expired tokens
     */
    private function cleanupExpiredTokens(): void
    {
        $stmt = $this->db->prepare("
            DELETE FROM auth_tokens 
            WHERE expiry <= NOW()
        ");
        $stmt->execute();
    }

    /**
     * Delete specific token
     */
    public function deleteToken(string $token): void
    {
        try {
            // Find the token by verifying it first (since it's hashed)
            $stmt = $this->db->prepare("
                SELECT token_id, token
                FROM auth_tokens 
                WHERE expiry > NOW()
            ");
            $stmt->execute();
            
            $tokenId = null;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (password_verify($token, $row['token'])) {
                    $tokenId = $row['token_id'];
                    break;
                }
            }
            
            if ($tokenId) {
                $deleteStmt = $this->db->prepare("
                    DELETE FROM auth_tokens 
                    WHERE token_id = :token_id
                ");
                $deleteStmt->execute(['token_id' => $tokenId]);
            }
        } catch (\Exception $e) {
            // Log error here
        }
    }
}


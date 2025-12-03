<?php

namespace App\Services;

use PDO;
use DateTime;

class AuthenticationService
{
    private $db;
    private $config;
    private $utilityService;
    
    public function __construct(PDO $db, ConfigService $config, UtilityService $utilityService)
    {
        $this->db = $db;
        $this->config = $config;
        $this->utilityService = $utilityService;
    }

    /**
     * Get or create user by email
     * Returns user_id if found or created, null on error
     */
    public function getOrCreateUserByEmail(string $email): ?int
    {
        try {
            // Check if user exists
            $stmt = $this->db->prepare("
                SELECT user_id 
                FROM users 
                WHERE email = :email
            ");
            $stmt->execute(['email' => $email]);
            
            if ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
                return $user['user_id'];
            }

            // Create new user
            $stmt = $this->db->prepare("
                INSERT INTO users (email)
                VALUES (:email)
            ");
            $stmt->execute(['email' => $email]);
            
            return $this->db->lastInsertId();
        } catch (\Exception $e) {
            error_log('AuthenticationService::getOrCreateUserByEmail error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate login token and send email
     * Returns true on success, false on failure
     */
    public function sendLoginToken(string $email): bool
    {
        try {
            // Get or create user
            $userId = $this->getOrCreateUserByEmail($email);
            if (!$userId) {
                return false;
            }

            // Generate random token
            $token = bin2hex(random_bytes(32));
            
            // Calculate expiry time (15 minutes for login tokens)
            $expiry = (new DateTime())->modify("+15 minutes");
            
            // Store login token in database
            $stmt = $this->db->prepare("
                INSERT INTO login_tokens (user_id, token, expiry)
                VALUES (:user_id, :token, :expiry)
            ");
            
            $stmt->execute([
                'user_id' => $userId,
                'token' => $token,
                'expiry' => $expiry->format('Y-m-d H:i:s')
            ]);

            // Send email with login link
            $baseUrl = $this->utilityService->getBaseUrl();
            $loginUrl = $baseUrl . 'login/token/' . $token;
            
            $subject = 'Your Login Link - Akaunting Importer';
            $body = "
                <h2>Login to Akaunting Importer</h2>
                <p>Click the link below to log in to your account:</p>
                <p><a href=\"{$loginUrl}\" style=\"display: inline-block; padding: 10px 20px; background-color: #4f46e5; color: white; text-decoration: none; border-radius: 5px;\">Log In</a></p>
                <p>Or copy this link: {$loginUrl}</p>
                <p><small>This link will expire in 15 minutes and can only be used once.</small></p>
            ";
            
            return $this->utilityService->sendEmail($email, $subject, $body);
        } catch (\Exception $e) {
            error_log('AuthenticationService::sendLoginToken error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify login token and create session token
     * Returns session token on success, null on failure
     */
    public function verifyLoginTokenAndCreateSession(string $loginToken): ?string
    {
        try {
            // Clean up expired login tokens
            $this->cleanupExpiredLoginTokens();
            
            // Find valid, unused login token
            $stmt = $this->db->prepare("
                SELECT login_token_id, user_id, token
                FROM login_tokens 
                WHERE token = :token 
                AND expiry > NOW() 
                AND used = 0
            ");
            $stmt->execute(['token' => $loginToken]);
            
            $loginTokenData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$loginTokenData) {
                return null;
            }
            
            // Mark login token as used
            $updateStmt = $this->db->prepare("
                UPDATE login_tokens 
                SET used = 1, used_at = NOW()
                WHERE login_token_id = :login_token_id
            ");
            $updateStmt->execute(['login_token_id' => $loginTokenData['login_token_id']]);
            
            // Create session token (auth token) with last_accessed timestamp
            $sessionToken = bin2hex(random_bytes(32));
            
            $stmt = $this->db->prepare("
                INSERT INTO auth_tokens (user_id, token, last_accessed)
                VALUES (:user_id, :token, NOW())
            ");
            
            $stmt->execute([
                'user_id' => $loginTokenData['user_id'],
                'token' => password_hash($sessionToken, PASSWORD_DEFAULT),
            ]);

            return $sessionToken;
        } catch (\Exception $e) {
            error_log('AuthenticationService::verifyLoginTokenAndCreateSession error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Verify session token and return user data if valid
     * Token is valid if last_accessed is within the expiry window
     * Returns null if token is expired (requiring re-authentication)
     */
    public function verifyToken(string $token): ?array
    {
        try {
            // Clean up expired tokens first
            $this->cleanupExpiredTokens();
            
            // Get expiry seconds from config (default 7 days)
            $expirySeconds = $this->config::get('auth.token_expiry', 604800);
            
            // Find valid token (last_accessed within expiry window)
            $stmt = $this->db->prepare("
                SELECT t.token_id, t.token, t.last_accessed, t.user_id, u.email
                FROM auth_tokens t
                JOIN users u ON t.user_id = u.user_id
                WHERE t.last_accessed > DATE_SUB(NOW(), INTERVAL :expiry_seconds SECOND)
                ORDER BY t.last_accessed DESC
            ");
            $stmt->execute(['expiry_seconds' => $expirySeconds]);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (password_verify($token, $row['token'])) {
                    return [
                        'user_id' => $row['user_id'],
                        'email' => $row['email'],
                        'token_id' => $row['token_id']
                    ];
                }
            }
            
            return null;
        } catch (\Exception $e) {
            error_log('AuthenticationService::verifyToken error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Update last_accessed timestamp to reset rolling expiry
     * Called on every successful token verification
     */
    public function updateLastAccessed(string $token): bool
    {
        try {
            $expirySeconds = $this->config::get('auth.token_expiry', 604800);
            
            // Find the token by verifying it first (since it's hashed)
            $stmt = $this->db->prepare("
                SELECT token_id, token
                FROM auth_tokens 
                WHERE last_accessed > DATE_SUB(NOW(), INTERVAL :expiry_seconds SECOND)
                ORDER BY last_accessed DESC
            ");
            $stmt->execute(['expiry_seconds' => $expirySeconds]);
            
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
            
            // Update last_accessed to NOW - this resets the rolling expiry
            $updateStmt = $this->db->prepare("
                UPDATE auth_tokens 
                SET last_accessed = NOW() 
                WHERE token_id = :token_id
            ");

            return $updateStmt->execute(['token_id' => $tokenId]);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Alias for backward compatibility
     */
    public function extendTokenExpiry(string $token): bool
    {
        return $this->updateLastAccessed($token);
    }

    /**
     * Delete tokens that haven't been accessed within the expiry window
     */
    private function cleanupExpiredTokens(): void
    {
        try {
            $expirySeconds = $this->config::get('auth.token_expiry', 604800);
            
            $stmt = $this->db->prepare("
                DELETE FROM auth_tokens 
                WHERE last_accessed <= DATE_SUB(NOW(), INTERVAL :expiry_seconds SECOND)
            ");
            $stmt->execute(['expiry_seconds' => $expirySeconds]);
        } catch (\Exception $e) {
            // Silently fail
        }
    }
    
    /**
     * Clean up expired login tokens
     */
    private function cleanupExpiredLoginTokens(): void
    {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM login_tokens 
                WHERE expiry <= NOW() OR used = 1
            ");
            $stmt->execute();
        } catch (\Exception $e) {
            // Silently fail
        }
    }

    /**
     * Delete specific token (logout)
     */
    public function deleteToken(string $token): void
    {
        try {
            $expirySeconds = $this->config::get('auth.token_expiry', 604800);
            
            // Find the token by verifying it first (since it's hashed)
            $stmt = $this->db->prepare("
                SELECT token_id, token
                FROM auth_tokens 
                WHERE last_accessed > DATE_SUB(NOW(), INTERVAL :expiry_seconds SECOND)
            ");
            $stmt->execute(['expiry_seconds' => $expirySeconds]);
            
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

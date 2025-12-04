<?php

namespace App\Services;

use App\DAO\InstallationDAO;

class InstallationService
{
    private InstallationDAO $installationDAO;

    public function __construct(InstallationDAO $installationDAO)
    {
        $this->installationDAO = $installationDAO;
    }

    /**
     * Get all installations for user
     */
    public function getInstallationsByUser(int $userId): array
    {
        return $this->installationDAO->findByUser($userId);
    }

    /**
     * Get installation by ID and user
     */
    public function getInstallationByIdAndUser(int $installationId, int $userId): ?array
    {
        return $this->installationDAO->findByIdAndUser($installationId, $userId);
    }

    /**
     * Create new installation
     * @throws \Exception if validation fails
     */
    public function createInstallation(array $data): int
    {
        $this->validateInstallationData($data);
        
        // Encrypt the API password before storing
        $data['api_password'] = $this->encryptPassword($data['api_password']);
        
        return $this->installationDAO->create($data);
    }

    /**
     * Update installation
     * @throws \Exception if validation fails
     */
    public function updateInstallation(int $installationId, int $userId, array $data): bool
    {
        if (!$this->installationDAO->belongsToUser($installationId, $userId)) {
            throw new \Exception('Installation not found', 404);
        }

        $this->validateInstallationData($data, true);
        
        // If password is provided, encrypt it; otherwise keep existing
        if (!empty($data['api_password'])) {
            $data['api_password'] = $this->encryptPassword($data['api_password']);
        } else {
            // Get existing password
            $existing = $this->installationDAO->findById($installationId);
            $data['api_password'] = $existing['api_password'];
        }

        return $this->installationDAO->update($installationId, $data);
    }

    /**
     * Delete installation
     * @throws \Exception if not found
     */
    public function deleteInstallation(int $installationId, int $userId): bool
    {
        if (!$this->installationDAO->belongsToUser($installationId, $userId)) {
            throw new \Exception('Installation not found', 404);
        }

        return $this->installationDAO->delete($installationId);
    }

    /**
     * Test connection to Akaunting installation using the /ping endpoint
     */
    public function testConnection(int $installationId, int $userId): array
    {
        $installation = $this->installationDAO->findByIdAndUser($installationId, $userId);
        
        if (!$installation) {
            throw new \Exception('Installation not found', 404);
        }

        try {
            $password = $this->decryptPassword($installation['api_password']);
            
            // Use the /ping endpoint to test if login credentials are valid
            $response = $this->makeApiRequest(
                $installation['base_url'], 
                $installation['api_email'], 
                $password, 
                '/api/ping'
            );
            
            if ($response['success']) {
                $this->installationDAO->updateLastSync($installationId);
                return [
                    'success' => true, 
                    'message' => 'Connection successful! API credentials are valid.'
                ];
            }
            
            return ['success' => false, 'message' => $response['error'] ?? 'Connection failed'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Validate installation data
     */
    private function validateInstallationData(array $data, bool $isUpdate = false): void
    {
        if (empty($data['name'])) {
            throw new \Exception('Name is required', 400);
        }

        if (empty($data['base_url'])) {
            throw new \Exception('Base URL is required', 400);
        }

        if (!filter_var($data['base_url'], FILTER_VALIDATE_URL)) {
            throw new \Exception('Invalid Base URL format', 400);
        }

        if (empty($data['api_email'])) {
            throw new \Exception('API Email is required', 400);
        }

        if (!$isUpdate && empty($data['api_password'])) {
            throw new \Exception('API Password is required', 400);
        }
    }

    /**
     * Encrypt password for storage
     */
    private function encryptPassword(string $password): string
    {
        // Use base64 encoding for simplicity (in production, use proper encryption)
        return base64_encode($password);
    }

    /**
     * Decrypt password for use
     */
    public function decryptPassword(string $encrypted): string
    {
        return base64_decode($encrypted);
    }

    /**
     * Fetch accounts from Akaunting installation
     * @return array List of accounts from Akaunting API
     */
    public function fetchAkauntingAccounts(int $installationId, int $userId): array
    {
        $installation = $this->installationDAO->findByIdAndUser($installationId, $userId);
        
        if (!$installation) {
            throw new \Exception('Installation not found', 404);
        }

        try {
            $password = $this->decryptPassword($installation['api_password']);
            
            // Fetch accounts from Akaunting API
            // Endpoint: /api/accounts (requires X-Company header)
            $companyId = $installation['company_id'] ?? 1; // Default to company 1
            $response = $this->makeApiRequest(
                $installation['base_url'], 
                $installation['api_email'], 
                $password, 
                '/api/accounts?limit=100',
                $companyId
            );
            
            if (!$response['success']) {
                throw new \Exception($response['error'] ?? 'Failed to fetch accounts');
            }

            // Parse the response - Akaunting returns accounts in a 'data' array
            $accounts = [];
            $data = $response['data']['data'] ?? $response['data'] ?? [];
            
            foreach ($data as $account) {
                $accounts[] = [
                    'id' => $account['id'] ?? null,
                    'name' => $account['name'] ?? 'Unknown',
                    'number' => $account['number'] ?? null,
                    'currency_code' => $account['currency_code'] ?? null,
                    'type' => $account['type'] ?? null,
                    'opening_balance' => $account['opening_balance'] ?? 0,
                    'current_balance' => $account['current_balance'] ?? 0,
                    'enabled' => $account['enabled'] ?? true,
                ];
            }
            
            return $accounts;
        } catch (\Exception $e) {
            throw new \Exception('Failed to fetch Akaunting accounts: ' . $e->getMessage());
        }
    }

    /**
     * Make API request to Akaunting
     * @param int|null $companyId The Akaunting company ID (required for most endpoints)
     */
    private function makeApiRequest(string $baseUrl, string $email, string $password, string $endpoint, ?int $companyId = null): array
    {
        $url = rtrim($baseUrl, '/') . $endpoint;
        
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: AkauntingImporter/1.0',
        ];
        
        // Add X-Company header if company ID is provided
        if ($companyId !== null) {
            $headers[] = 'X-Company: ' . $companyId;
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERPWD => $email . ':' . $password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => "cURL error: $error"];
        }

        $data = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'data' => $data];
        }

        // Try to extract error message from response
        $errorMessage = "HTTP $httpCode";
        if ($data) {
            if (isset($data['message'])) {
                $errorMessage .= ": " . $data['message'];
            } elseif (isset($data['error'])) {
                $errorMessage .= ": " . $data['error'];
            } elseif (isset($data['errors'])) {
                $errors = is_array($data['errors']) ? implode(', ', array_map(function($e) {
                    return is_array($e) ? implode(', ', $e) : $e;
                }, $data['errors'])) : $data['errors'];
                $errorMessage .= ": " . $errors;
            }
        } else {
            // Response wasn't JSON
            $errorMessage .= ": " . substr($response, 0, 200);
        }

        return ['success' => false, 'error' => $errorMessage];
    }

    /**
     * Fetch contacts/vendors from Akaunting installation
     * @return array List of contacts from Akaunting API
     */
    public function fetchAkauntingContacts(int $installationId, int $userId, string $type = 'vendor'): array
    {
        $installation = $this->installationDAO->findByIdAndUser($installationId, $userId);
        
        if (!$installation) {
            throw new \Exception('Installation not found', 404);
        }

        try {
            $password = $this->decryptPassword($installation['api_password']);
            $companyId = $installation['company_id'] ?? 1;
            
            // Fetch contacts from Akaunting API with pagination
            $allContacts = [];
            $page = 1;
            $perPage = 100;
            
            do {
                // Use search parameter for filtering - Akaunting requires this format
                $searchQuery = urlencode("type:{$type}");
                $endpoint = "/api/contacts?search={$searchQuery}&limit={$perPage}&page={$page}";
                $response = $this->makeApiRequest(
                    $installation['base_url'], 
                    $installation['api_email'], 
                    $password, 
                    $endpoint,
                    $companyId
                );
                
                if (!$response['success']) {
                    throw new \Exception($response['error'] ?? 'Failed to fetch contacts');
                }

                $data = $response['data']['data'] ?? $response['data'] ?? [];
                $meta = $response['data']['meta'] ?? [];
                
                foreach ($data as $contact) {
                    $allContacts[] = [
                        'id' => $contact['id'] ?? null,
                        'name' => $contact['name'] ?? 'Unknown',
                        'email' => $contact['email'] ?? null,
                        'type' => $contact['type'] ?? $type,
                        'enabled' => $contact['enabled'] ?? true,
                    ];
                }
                
                $lastPage = $meta['last_page'] ?? 1;
                $page++;
                
            } while ($page <= $lastPage && $page <= 20); // Max 20 pages (2000 contacts)
            
            return $allContacts;
        } catch (\Exception $e) {
            throw new \Exception('Failed to fetch Akaunting contacts: ' . $e->getMessage());
        }
    }

    /**
     * Get installation by ID (for internal use)
     */
    public function getInstallationById(int $installationId): ?array
    {
        return $this->installationDAO->findById($installationId);
    }

    /**
     * Fetch categories from Akaunting installation
     * @return array List of categories from Akaunting API
     */
    public function fetchAkauntingCategories(int $installationId, int $userId, ?string $type = null): array
    {
        $installation = $this->installationDAO->findByIdAndUser($installationId, $userId);
        
        if (!$installation) {
            throw new \Exception('Installation not found', 404);
        }

        try {
            $password = $this->decryptPassword($installation['api_password']);
            $companyId = $installation['company_id'] ?? 1;
            
            // Fetch categories from Akaunting API with pagination
            $allCategories = [];
            $page = 1;
            $perPage = 100;
            
            do {
                // Build endpoint - optionally filter by type
                $endpoint = "/api/categories?limit={$perPage}&page={$page}";
                if ($type) {
                    $searchQuery = urlencode("type:{$type}");
                    $endpoint .= "&search={$searchQuery}";
                }
                
                $response = $this->makeApiRequest(
                    $installation['base_url'], 
                    $installation['api_email'], 
                    $password, 
                    $endpoint,
                    $companyId
                );
                
                if (!$response['success']) {
                    throw new \Exception($response['error'] ?? 'Failed to fetch categories');
                }

                $data = $response['data']['data'] ?? $response['data'] ?? [];
                $meta = $response['data']['meta'] ?? [];
                
                foreach ($data as $category) {
                    $allCategories[] = [
                        'id' => $category['id'] ?? null,
                        'name' => $category['name'] ?? 'Unknown',
                        'type' => $category['type'] ?? null,
                        'color' => $category['color'] ?? null,
                        'enabled' => $category['enabled'] ?? true,
                    ];
                }
                
                $lastPage = $meta['last_page'] ?? 1;
                $page++;
                
            } while ($page <= $lastPage && $page <= 10); // Max 10 pages (1000 categories)
            
            return $allCategories;
        } catch (\Exception $e) {
            throw new \Exception('Failed to fetch Akaunting categories: ' . $e->getMessage());
        }
    }

    /**
     * Fetch payment methods from Akaunting installation
     * Payment methods are stored in settings as offline-payments.methods
     * @return array List of payment methods
     */
    public function fetchAkauntingPaymentMethods(int $installationId, int $userId): array
    {
        $installation = $this->installationDAO->findByIdAndUser($installationId, $userId);
        
        if (!$installation) {
            throw new \Exception('Installation not found', 404);
        }

        try {
            $password = $this->decryptPassword($installation['api_password']);
            $companyId = $installation['company_id'] ?? 1;
            
            // Payment methods are stored in settings
            $response = $this->makeApiRequest(
                $installation['base_url'], 
                $installation['api_email'], 
                $password, 
                '/api/settings',
                $companyId
            );
            
            if (!$response['success']) {
                throw new \Exception($response['error'] ?? 'Failed to fetch settings');
            }

            $settings = $response['data']['data'] ?? $response['data'] ?? [];
            $paymentMethods = [];
            
            // Find offline payment methods in settings
            // Format: [{"code":"offline-payments.cash.1","name":"Cash",...},...]
            foreach ($settings as $setting) {
                if (isset($setting['key']) && $setting['key'] === 'offline-payments.methods') {
                    $methods = json_decode($setting['value'] ?? '[]', true);
                    if (is_array($methods)) {
                        foreach ($methods as $method) {
                            // Each method has 'code' (full code like 'offline-payments.bank_transfer.2') and 'name'
                            if (isset($method['code']) && isset($method['name'])) {
                                $paymentMethods[] = [
                                    'code' => $method['code'], // Full code for API
                                    'name' => $method['name'], // Display name
                                ];
                            }
                        }
                    }
                    break;
                }
            }
            
            // Add default methods if none found (using offline-payments format)
            if (empty($paymentMethods)) {
                $paymentMethods = [
                    ['code' => 'offline-payments.cash.1', 'name' => 'Cash'],
                    ['code' => 'offline-payments.bank_transfer.2', 'name' => 'Bank Transfer'],
                ];
            }
            
            return $paymentMethods;
        } catch (\Exception $e) {
            // Return defaults on error
            return [
                ['code' => 'offline-payments.cash.1', 'name' => 'Cash'],
                ['code' => 'offline-payments.bank_transfer.2', 'name' => 'Bank Transfer'],
            ];
        }
    }

    /**
     * Get entities with Akaunting installations (for cross-entity replication)
     */
    public function getEntitiesWithInstallations(int $userId, ?int $excludeEntityId = null): array
    {
        $installations = $this->installationDAO->findByUser($userId);
        
        $entities = [];
        foreach ($installations as $installation) {
            // Skip if entity_id is null or matches exclude
            if (!$installation['entity_id']) {
                continue;
            }
            if ($excludeEntityId && $installation['entity_id'] == $excludeEntityId) {
                continue;
            }
            
            $entities[] = [
                'entity_id' => $installation['entity_id'],
                'entity_name' => $installation['entity_name'],
                'installation_id' => $installation['installation_id'],
                'installation_name' => $installation['name'],
            ];
        }
        
        return $entities;
    }

    /**
     * Get form data for a specific installation (vendors, categories, payment methods, accounts)
     * Used for cross-entity replication
     */
    public function getFormDataForInstallation(
        int $installationId, 
        int $userId,
        ?int $sourceInstallationId = null,
        ?int $sourceVendorId = null,
        ?int $sourceCategoryId = null
    ): array {
        $installation = $this->installationDAO->findByIdAndUser($installationId, $userId);
        
        if (!$installation) {
            throw new \Exception('Installation not found');
        }

        // Fetch all data from Akaunting
        $vendors = $this->fetchAkauntingContacts($installationId, $userId, 'vendor');
        $customers = $this->fetchAkauntingContacts($installationId, $userId, 'customer');
        $categories = $this->fetchAkauntingCategories($installationId, $userId);
        $paymentMethods = $this->fetchAkauntingPaymentMethods($installationId, $userId);
        $accounts = $this->fetchAkauntingAccounts($installationId, $userId);

        // Look up suggested mapping if source info provided
        $suggested = null;
        if ($sourceInstallationId && ($sourceVendorId || $sourceCategoryId)) {
            $suggested = $this->getCrossEntityMapping(
                $sourceInstallationId,
                $sourceVendorId,
                $sourceCategoryId,
                $installationId
            );
        }

        return [
            'vendors' => $vendors,
            'customers' => $customers,
            'categories' => $categories,
            'payment_methods' => $paymentMethods,
            'accounts' => $accounts,
            'suggested' => $suggested,
        ];
    }

    /**
     * Get cross-entity mapping suggestion
     */
    public function getCrossEntityMapping(
        int $sourceInstallationId,
        ?int $sourceVendorId,
        ?int $sourceCategoryId,
        int $targetInstallationId
    ): ?array {
        return $this->installationDAO->findCrossEntityMapping(
            $sourceInstallationId,
            $sourceVendorId,
            $sourceCategoryId,
            $targetInstallationId
        );
    }

    /**
     * Save cross-entity mapping
     */
    public function saveCrossEntityMapping(
        int $sourceInstallationId,
        ?int $sourceVendorId,
        ?int $sourceCategoryId,
        int $targetInstallationId,
        ?int $targetVendorId,
        ?int $targetCategoryId,
        ?int $targetAccountId,
        ?string $targetPaymentMethod
    ): void {
        $this->installationDAO->saveCrossEntityMapping(
            $sourceInstallationId,
            $sourceVendorId,
            $sourceCategoryId,
            $targetInstallationId,
            $targetVendorId,
            $targetCategoryId,
            $targetAccountId,
            $targetPaymentMethod
        );
    }
}


<?php

/**
 * WEBMINDER API CLIENT EXAMPLE
 * 
 * This example demonstrates how to interact with the Webminder API
 * from the central panel or any authorized client.
 */

namespace App\Webminder\Examples;

use Illuminate\Support\Facades\Http;

class WebminderApiClient
{
    protected string $baseUrl;
    protected string $clientId;
    protected string $clientSecret;

    public function __construct(string $baseUrl, string $clientId, string $clientSecret)
    {
        $this->baseUrl = $baseUrl;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

    /**
     * Example: Ping endpoint (no auth)
     */
    public function ping(): array
    {
        $response = Http::get("{$this->baseUrl}/api/webminder/ping");
        return $response->json();
    }

    /**
     * Example: Get site status
     */
    public function getStatus(): array
    {
        return $this->authenticatedRequest('POST', 'status', []);
    }

    /**
     * Example: Activate maintenance mode
     */
    public function activateMaintenance(): array
    {
        return $this->authenticatedRequest('POST', 'maintenance-mode', [
            'active' => true,
        ]);
    }

    /**
     * Example: Deactivate maintenance mode
     */
    public function deactivateMaintenance(): array
    {
        return $this->authenticatedRequest('POST', 'maintenance-mode', [
            'active' => false,
        ]);
    }

    /**
     * Example: Get database health
     */
    public function getDbStatus(): array
    {
        return $this->authenticatedRequest('POST', 'db-status', []);
    }

    /**
     * Example: Clean database
     */
    public function cleanDatabase(array $options = []): array
    {
        $defaults = [
            'clean_revisions' => true,
            'clean_spam' => true,
            'clean_orphaned_meta' => true,
            'clean_trash' => false,
            'optimize_tables' => true,
        ];

        $payload = array_merge($defaults, $options);
        return $this->authenticatedRequest('POST', 'db-clean', $payload);
    }

    /**
     * Example: Get available updates
     */
    public function getUpdates(): array
    {
        return $this->authenticatedRequest('POST', 'updates', []);
    }

    /**
     * Example: Get installed packages
     */
    public function getPlugins(): array
    {
        return $this->authenticatedRequest('POST', 'plugins', []);
    }

    /**
     * Example: Get full site health report
     */
    public function getSiteHealth(): array
    {
        return $this->authenticatedRequest('POST', 'site-health', []);
    }

    /**
     * Example: Disconnect from panel
     */
    public function disconnect(): array
    {
        return $this->authenticatedRequest('POST', 'disconnect', []);
    }

    /**
     * Make authenticated request with HMAC-SHA256 signature
     */
    protected function authenticatedRequest(string $method, string $endpoint, array $payload): array
    {
        $timestamp = time();
        $bodyJson = json_encode($payload);

        // Generate HMAC-SHA256 signature
        $signature = 'sha256=' . hash_hmac('sha256', $bodyJson . $timestamp, $this->clientSecret);

        $url = "{$this->baseUrl}/api/webminder/{$endpoint}";

        $response = Http::withHeaders([
            'X-Webminder-Signature' => $signature,
            'X-Webminder-Timestamp' => (string)$timestamp,
            'Content-Type' => 'application/json',
        ])->send($method, $url, [
            'body' => $bodyJson,
        ]);

        return $response->json();
    }
}

/**
 * USAGE EXAMPLES
 * 
 * $client = new WebminderApiClient(
 *     'https://mysite.com',
 *     config('webminder.client_id'),
 *     config('webminder.client_secret')
 * );
 * 
 * // Check if site is alive
 * $ping = $client->ping();
 * // Response: ['success' => true, 'timestamp' => 1721648000]
 * 
 * // Get comprehensive status
 * $status = $client->getStatus();
 * // Response includes database, filesystem, cache, app metrics
 * 
 * // Start maintenance
 * $client->activateMaintenance();
 * 
 * // Clean database
 * $clean = $client->cleanDatabase();
 * // Response: ['success' => true, 'cleaned' => [...], 'deleted_revisions' => 25, ...]
 * 
 * // End maintenance
 * $client->deactivateMaintenance();
 * 
 * // Get updates available
 * $updates = $client->getUpdates();
 * 
 * // Full health report
 * $health = $client->getSiteHealth();
 * 
 * // Disconnect from panel
 * $client->disconnect();
 */

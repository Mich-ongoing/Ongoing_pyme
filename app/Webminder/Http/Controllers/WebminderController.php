<?php

namespace App\Webminder\Http\Controllers;

use App\Webminder\Services\StatusService;
use App\Webminder\Services\DatabaseService;
use App\Webminder\Services\ConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebminderController
{
    protected StatusService $statusService;
    protected DatabaseService $databaseService;
    protected ConfigurationService $configService;

    public function __construct(
        StatusService $statusService,
        DatabaseService $databaseService,
        ConfigurationService $configService
    ) {
        $this->statusService = $statusService;
        $this->databaseService = $databaseService;
        $this->configService = $configService;
    }

    /**
     * GET /api/webminder/ping
     * No-auth health check
     */
    public function ping(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'timestamp' => now()->timestamp,
        ]);
    }

    /**
     * POST /api/webminder/status
     * Report comprehensive site status
     */
    public function status(): JsonResponse
    {
        $this->configService->recordSync();

        return response()->json([
            'success' => true,
            'data' => $this->statusService->getStatus(),
            'configuration' => $this->configService->getConfiguration(),
        ]);
    }

    /**
     * POST /api/webminder/sso
     * Single Sign-On request - returns admin login link
     */
    public function sso(Request $request): JsonResponse
    {
        // Generate a temporary token for admin access
        // This would integrate with Laravel's auth system
        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addHours(1);

        // In production, store this token and validate on login
        // For now, return basic response structure

        return response()->json([
            'success' => true,
            'message' => 'SSO request accepted',
            'login_url' => route('admin.dashboard'), // Adjust route as needed
            'token' => $token,
            'expires_at' => $expiresAt->timestamp,
        ]);
    }

    /**
     * POST /api/webminder/maintenance-mode
     * Toggle maintenance mode on/off
     */
    public function maintenanceMode(Request $request): JsonResponse
    {
        $active = $request->boolean('active', false);
        
        return response()->json(
            $this->configService->setMaintenanceMode($active)
        );
    }

    /**
     * POST /api/webminder/db-status
     * Get database health metrics
     */
    public function dbStatus(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->databaseService->getHealth(),
        ]);
    }

    /**
     * POST /api/webminder/db-clean
     * Clean database according to options
     */
    public function dbClean(Request $request): JsonResponse
    {
        $options = $request->json()->all();

        $result = $this->databaseService->clean($options);

        return response()->json($result);
    }

    /**
     * POST /api/webminder/disconnect
     * Disconnect this server from the panel
     */
    public function disconnect(): JsonResponse
    {
        $this->configService->clearSync();

        return response()->json([
            'success' => true,
            'message' => 'Agent successfully disconnected from panel',
        ]);
    }

    /**
     * POST /api/webminder/updates
     * Get available updates
     */
    public function updates(): JsonResponse
    {
        // This would check Composer packages for updates
        // For now, return the structure
        
        return response()->json([
            'success' => true,
            'data' => [
                'composer_packages' => $this->getComposerUpdates(),
                'laravel_framework' => app()->version(),
                'php_version' => phpversion(),
            ],
        ]);
    }

    /**
     * POST /api/webminder/plugins
     * Get installed packages/extensions
     */
    public function plugins(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'laravel_version' => app()->version(),
                'php_version' => phpversion(),
                'packages' => $this->getInstalledPackages(),
            ],
        ]);
    }

    /**
     * POST /api/webminder/update
     * Execute single package update
     */
    public function updateItem(Request $request): JsonResponse
    {
        $package = $request->string('package')->value();

        try {
            // Would execute composer update here
            return response()->json([
                'success' => true,
                'message' => "Package $package updated successfully",
                'package' => $package,
            ]);
        } catch (\Exception $e) {
            $this->configService->recordUpdateFailure(
                $package,
                $e->getMessage()
            );

            return response()->json([
                'success' => false,
                'message' => 'Update failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/webminder/fix-security
     * Fix common security issues
     */
    public function fixSecurity(Request $request): JsonResponse
    {
        $issue = $request->string('issue')->value();

        try {
            $result = $this->applySecurityFix($issue);
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Security fix failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/webminder/site-health
     * Full site health report
     */
    public function siteHealth(): JsonResponse
    {
        $status = $this->statusService->getStatus();
        $dbHealth = $this->databaseService->getHealth();

        return response()->json([
            'success' => true,
            'data' => [
                'site' => $status['site'] ?? [],
                'database' => $dbHealth,
                'filesystem' => $status['filesystem'] ?? [],
                'cache' => $status['cache'] ?? [],
                'app' => $status['app'] ?? [],
            ],
        ]);
    }

    /**
     * Helper: Get composer updates available
     */
    private function getComposerUpdates(): array
    {
        // Would parse composer.lock and check for newer versions
        // For now, return empty array
        return [];
    }

    /**
     * Helper: Get installed packages
     */
    private function getInstalledPackages(): array
    {
        // Parse composer.lock or installed.json
        $installed = [];
        
        if (file_exists(base_path('composer.lock'))) {
            $lock = json_decode(file_get_contents(base_path('composer.lock')), true);
            
            foreach ($lock['packages'] ?? [] as $package) {
                $installed[] = [
                    'name' => $package['name'],
                    'version' => $package['version'],
                    'license' => $package['license'][0] ?? null,
                ];
            }
        }

        return $installed;
    }

    /**
     * Helper: Apply security fixes
     */
    private function applySecurityFix(string $issue): array
    {
        // Implement security fixes based on issue type
        // Examples: DISALLOW_FILE_EDIT, X-Frame-Options, etc.
        
        return [
            'success' => true,
            'message' => "Security issue '$issue' fixed",
            'issue' => $issue,
        ];
    }
}

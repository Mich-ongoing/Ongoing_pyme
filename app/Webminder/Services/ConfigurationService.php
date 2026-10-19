<?php

namespace App\Webminder\Services;

use Illuminate\Support\Facades\Cache;

class ConfigurationService
{
    const MAINTENANCE_MODE_KEY = 'webminder:maintenance_mode';
    const UPDATE_FAILURES_KEY = 'webminder:update_failures';
    const LAST_SYNC_KEY = 'webminder:last_sync';

    /**
     * Check if maintenance mode is active
     */
    public function isMaintenanceModeActive(): bool
    {
        return Cache::get(self::MAINTENANCE_MODE_KEY, false) === true;
    }

    /**
     * Set maintenance mode state
     */
    public function setMaintenanceMode(bool $active): array
    {
        Cache::put(self::MAINTENANCE_MODE_KEY, $active, now()->addYears(10));

        return [
            'success' => true,
            'message' => $active ? 'Maintenance mode activated' : 'Maintenance mode deactivated',
            'active' => $active,
        ];
    }

    /**
     * Record update failure for tracking
     */
    public function recordUpdateFailure(string $itemSlug, string $message, bool $requiresLicense = false): void
    {
        $failures = $this->getUpdateFailures();
        
        $failures[$itemSlug] = [
            'requires_license' => $requiresLicense,
            'message' => $message,
            'at' => now()->timestamp,
        ];

        Cache::put(self::UPDATE_FAILURES_KEY, $failures, now()->addYears(10));
    }

    /**
     * Get all recorded update failures
     */
    public function getUpdateFailures(): array
    {
        return Cache::get(self::UPDATE_FAILURES_KEY, []);
    }

    /**
     * Clear update failures (called when update succeeds)
     */
    public function clearUpdateFailures(string $itemSlug): void
    {
        $failures = $this->getUpdateFailures();
        unset($failures[$itemSlug]);
        
        if (empty($failures)) {
            Cache::forget(self::UPDATE_FAILURES_KEY);
        } else {
            Cache::put(self::UPDATE_FAILURES_KEY, $failures, now()->addYears(10));
        }
    }

    /**
     * Record last sync timestamp
     */
    public function recordSync(): void
    {
        Cache::put(self::LAST_SYNC_KEY, now()->timestamp, now()->addYears(10));
    }

    /**
     * Clear sync record (used on disconnect)
     */
    public function clearSync(): void
    {
        Cache::forget(self::LAST_SYNC_KEY);
    }

    /**
     * Get all webminder configuration for status reporting
     */
    public function getConfiguration(): array
    {
        return [
            'maintenance_mode' => $this->isMaintenanceModeActive(),
            'update_failures' => $this->getUpdateFailures(),
            'last_sync' => Cache::get(self::LAST_SYNC_KEY),
            'client_id' => config('webminder.client_id'),
            'client_configured' => !empty(config('webminder.client_id')),
        ];
    }
}

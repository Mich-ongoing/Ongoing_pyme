<?php

namespace App\Webminder\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StatusService
{
    /**
     * Get comprehensive site health status
     */
    public function getStatus(): array
    {
        return [
            'site' => [
                'name' => config('app.name'),
                'url' => config('app.url'),
                'version' => app()->version(),
                'php_version' => phpversion(),
                'environment' => config('app.env'),
                'debug_mode' => config('app.debug'),
            ],
            'database' => $this->getDatabaseStatus(),
            'filesystem' => $this->getFilesystemStatus(),
            'cache' => $this->getCacheStatus(),
            'app' => $this->getAppStatus(),
            'timestamp' => now()->timestamp,
            'timestamp_iso' => now()->toIso8601String(),
        ];
    }

    private function getDatabaseStatus(): array
    {
        try {
            $testConnection = DB::connection()->getPdo();
            return [
                'connected' => true,
                'driver' => config('database.default'),
                'size_mb' => $this->getDatabaseSize(),
                'tables_count' => $this->getTablesCount(),
            ];
        } catch (\Exception $e) {
            return [
                'connected' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function getFilesystemStatus(): array
    {
        return [
            'storage_path' => storage_path(),
            'storage_writable' => is_writable(storage_path()),
            'bootstrap_cache_writable' => is_writable(bootstrap_path('cache')),
            'public_writable' => is_writable(public_path()),
        ];
    }

    private function getCacheStatus(): array
    {
        $driver = config('cache.default');
        try {
            Cache::put('webminder_test', microtime(true), 60);
            $cached = Cache::has('webminder_test');
            Cache::forget('webminder_test');
            
            return [
                'driver' => $driver,
                'working' => $cached,
            ];
        } catch (\Exception $e) {
            return [
                'driver' => $driver,
                'working' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function getAppStatus(): array
    {
        return [
            'uptime' => $this->getServerUptime(),
            'memory_usage' => [
                'current_mb' => round(memory_get_usage() / 1024 / 1024, 2),
                'peak_mb' => round(memory_get_peak_usage() / 1024 / 1024, 2),
                'limit_mb' => round(ini_get('memory_limit'), 2),
            ],
            'load_average' => $this->getLoadAverage(),
        ];
    }

    private function getDatabaseSize(): float
    {
        try {
            $result = DB::selectOne("
                SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb 
                FROM information_schema.TABLES 
                WHERE table_schema = DATABASE()
            ");
            return $result->size_mb ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function getTablesCount(): int
    {
        try {
            return DB::selectOne("
                SELECT COUNT(*) as count 
                FROM information_schema.TABLES 
                WHERE table_schema = DATABASE()
            ")->count ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function getServerUptime(): ?string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return null;
        }

        try {
            $uptime = shell_exec('uptime -p');
            return trim($uptime);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getLoadAverage(): ?array
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return null;
        }

        $load = sys_getloadavg();
        return [
            '1_min' => $load[0],
            '5_min' => $load[1],
            '15_min' => $load[2],
        ];
    }
}

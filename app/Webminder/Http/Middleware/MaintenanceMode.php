<?php

namespace App\Webminder\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Webminder\Services\ConfigurationService;

class MaintenanceMode
{
    protected ConfigurationService $configService;

    public function __construct(ConfigurationService $configService)
    {
        $this->configService = $configService;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Allow if user is authenticated as admin
        if (auth()->check() && auth()->user()->isAdmin ?? false) {
            return $next($request);
        }

        // Allow API requests (webminder panel communicates while in maintenance)
        if ($request->is('api/*')) {
            return $next($request);
        }

        // Check if maintenance mode is active
        if ($this->configService->isMaintenanceModeActive()) {
            return response()->view('webminder.maintenance', [], 503)
                ->header('Retry-After', 3600)
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                ->header('Pragma', 'no-cache')
                ->header('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT');
        }

        return $next($request);
    }
}

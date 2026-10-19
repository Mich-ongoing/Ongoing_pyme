<?php

namespace App\Webminder\Panel\Providers;

use Illuminate\Support\ServiceProvider;
use App\Webminder\Panel\Services\AgenteComunicacionService;

class WebminderPanelServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(AgenteComunicacionService::class, function ($app) {
            return new AgenteComunicacionService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Load routes
        $routesPath = base_path('routes/webminder_panel.php');
        if (file_exists($routesPath)) {
            $this->loadRoutesFrom($routesPath);
        }

        // Load views
        $viewsPath = resource_path('views/webminder-panel');
        if (is_dir($viewsPath)) {
            $this->loadViewsFrom($viewsPath, 'webminder-panel');
        }
    }
}

<?php

namespace App\Webminder\Providers;

use Illuminate\Support\ServiceProvider;
use App\Webminder\Services\StatusService;
use App\Webminder\Services\DatabaseService;
use App\Webminder\Services\ConfigurationService;

class WebminderServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(StatusService::class, function ($app) {
            return new StatusService();
        });

        $this->app->singleton(DatabaseService::class, function ($app) {
            return new DatabaseService();
        });

        $this->app->singleton(ConfigurationService::class, function ($app) {
            return new ConfigurationService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__.'/../../config/webminder.php' => config_path('webminder.php'),
        ], 'webminder-config');

        // Load routes (using base_path for absolute path)
        $routesPath = base_path('routes/webminder.php');
        if (file_exists($routesPath)) {
            $this->loadRoutesFrom($routesPath);
        }

        // Load views
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'webminder');
    }
}

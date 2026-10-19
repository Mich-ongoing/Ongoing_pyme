<?php

use Illuminate\Support\Facades\Route;
use App\Webminder\Http\Controllers\WebminderController;
use App\Webminder\Http\Middleware\VerifyWebminderSignature;

Route::prefix('api/webminder')->group(function () {
    
    // No-auth endpoint
    Route::get('ping', [WebminderController::class, 'ping']);

    // Protected endpoints (require valid signature)
    Route::middleware([VerifyWebminderSignature::class])->group(function () {
        Route::post('status', [WebminderController::class, 'status']);
        Route::post('sso', [WebminderController::class, 'sso']);
        Route::post('updates', [WebminderController::class, 'updates']);
        Route::post('plugins', [WebminderController::class, 'plugins']);
        Route::post('update', [WebminderController::class, 'updateItem']);
        Route::post('disconnect', [WebminderController::class, 'disconnect']);
        Route::post('maintenance-mode', [WebminderController::class, 'maintenanceMode']);
        Route::post('fix-security', [WebminderController::class, 'fixSecurity']);
        Route::post('db-status', [WebminderController::class, 'dbStatus']);
        Route::post('db-clean', [WebminderController::class, 'dbClean']);
        Route::post('site-health', [WebminderController::class, 'siteHealth']);
    });
});

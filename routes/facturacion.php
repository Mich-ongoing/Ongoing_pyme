<?php

use App\Facturacion\FacturacionController;
use Illuminate\Support\Facades\Route;

// Facturación mensual: la ven los admins y los usuarios con rol «facturacion»
Route::prefix('facturacion')->name('facturacion.')->middleware(['auth', 'can:facturacion'])->group(function () {
    Route::get('/', [FacturacionController::class, 'index'])->name('index');
    Route::post('/crear', [FacturacionController::class, 'crear'])->middleware('throttle:30,1')->name('crear');
    Route::post('/contacto', [FacturacionController::class, 'contacto'])->middleware('throttle:30,1')->name('contacto');
});

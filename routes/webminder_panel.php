<?php

use Illuminate\Support\Facades\Route;
use App\Webminder\Panel\Http\Controllers\DashboardController;
use App\Webminder\Panel\Http\Controllers\ClientesController;
use App\Webminder\Panel\Http\Controllers\SitiosController;
use App\Webminder\Panel\Http\Controllers\OperacionesController;
use App\Webminder\Panel\Http\Controllers\AuditoriaController;
use App\Webminder\Panel\Http\Controllers\IngestaController;
use App\Webminder\Panel\Http\Controllers\ActualizacionesController;

// Autoactualización del worker: manifiesto y zip (artisan webminder:worker-zip). Públicos: no llevan secretos.
Route::get('webminder/worker/{archivo}', function (string $archivo) {
    abort_unless(in_array($archivo, ['manifest.json', 'webminder-worker.zip'], true), 404);
    $ruta = storage_path('app/worker/' . $archivo);
    abort_unless(is_file($ruta), 404);

    return response()->file($ruta, ['Cache-Control' => 'no-cache']);
})->name('webminder.worker');

// Ingesta del log de actividad de los workers: sin sesión ni CSRF, autenticada por firma HMAC.
Route::post('api/webminder/ingest', IngestaController::class)->middleware('throttle:120,1')->name('webminder.ingest');

Route::prefix('webminder-panel')->name('webminder-panel.')->middleware(['web', 'auth'])->group(function () {
    
    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/resumen', [DashboardController::class, 'resumen'])->name('resumen');

    // Clientes
    Route::resource('clientes', ClientesController::class);
    Route::post('/clientes/{cliente}/desactivar', [ClientesController::class, 'desactivar'])->name('clientes.desactivar');
    Route::post('/clientes/{cliente}/activar', [ClientesController::class, 'activar'])->name('clientes.activar');
    Route::get('/clientes/{cliente}/estadisticas', [ClientesController::class, 'estadisticas'])->name('clientes.estadisticas');

    // Sitios Web
    Route::resource('sitios', SitiosController::class);
    Route::get('/sitios/{sitio}/verificar', [SitiosController::class, 'verificar'])->name('sitios.verificar');
    Route::get('/sitios/{sitio}/estado', [SitiosController::class, 'estado'])->name('sitios.estado');
    Route::get('/sitios/{sitio}/salud-bd', [SitiosController::class, 'saludBD'])->name('sitios.salud-bd');
    Route::get('/sitios/{sitio}/paquetes', [SitiosController::class, 'paquetes'])->name('sitios.paquetes');
    Route::post('/sitios/{sitio}/regenerar-credenciales', [SitiosController::class, 'regenerarCredenciales'])->name('sitios.regenerar-credenciales');
    Route::get('/sitios/{sitio}/credenciales', [SitiosController::class, 'credenciales'])->name('sitios.credenciales');
    Route::post('/sitios/{sitio}/desconectar', [SitiosController::class, 'desconectar'])->name('sitios.desconectar');

    // Actualizaciones en lote (cola)
    Route::get('/sitios/{sitio}/actualizaciones', [ActualizacionesController::class, 'index'])->name('sitios.actualizaciones');
    Route::post('/sitios/{sitio}/actualizaciones', [ActualizacionesController::class, 'store'])->name('sitios.actualizaciones.store');
    Route::get('/ejecuciones/{ejecucion}', [ActualizacionesController::class, 'show'])->name('ejecuciones.show');

    // Operaciones remotas
    Route::post('/operaciones/{sitio}/mantenimiento-on', [OperacionesController::class, 'activarMantenimiento'])->name('operaciones.mantenimiento-on');
    Route::post('/operaciones/{sitio}/mantenimiento-off', [OperacionesController::class, 'desactivarMantenimiento'])->name('operaciones.mantenimiento-off');
    Route::post('/operaciones/{sitio}/limpiar-bd', [OperacionesController::class, 'limpiarBD'])->name('operaciones.limpiar-bd');
    Route::get('/operaciones/{sitio}/actualizaciones', [OperacionesController::class, 'actualizaciones'])->name('operaciones.actualizaciones');
    Route::post('/operaciones/{sitio}/actualizar', [OperacionesController::class, 'actualizar'])->name('operaciones.actualizar');
    Route::post('/operaciones/{sitio}/corregir-seguridad', [OperacionesController::class, 'corregirSeguridad'])->name('operaciones.corregir-seguridad');
    Route::get('/operaciones/{sitio}/reporte-salud', [OperacionesController::class, 'reporteSalud'])->name('operaciones.reporte-salud');
    Route::post('/operaciones/{sitio}/sso', [OperacionesController::class, 'sso'])->name('operaciones.sso');
    Route::post('/operaciones/{sitio}/revision-seguridad', [OperacionesController::class, 'revisionSeguridad'])->name('operaciones.revision-seguridad');

    // Auditoría
    Route::get('/auditoria', [AuditoriaController::class, 'index'])->name('auditoria.index');
    Route::get('/auditoria/estadisticas', [AuditoriaController::class, 'estadisticas'])->name('auditoria.estadisticas');
    Route::get('/auditoria/{evento}', [AuditoriaController::class, 'show'])->name('auditoria.show');
    Route::post('/auditoria/limpiar', [AuditoriaController::class, 'limpiar'])->name('auditoria.limpiar');
});

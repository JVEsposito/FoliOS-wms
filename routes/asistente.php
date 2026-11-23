<?php

use App\Http\Controllers\Api\AsistenteController;
use Illuminate\Support\Facades\Route;

// Asistente de Oficina (docs/ASISTENTE_OFICINA.md). Solo lectura; cada consulta exige el
// permiso de su oficina de origen.
Route::middleware(['auth:sanctum', 'throttle:30,1'])->prefix('asistente')->group(function (): void {
    Route::post('/preguntas', [AsistenteController::class, 'preguntar']);
    Route::get('/consultas', [AsistenteController::class, 'consultas']);
    Route::post('/consultas/{clave}', [AsistenteController::class, 'ejecutar'])->where('clave', '[a-z0-9\-]+');
    Route::post('/recomendaciones/{clave}', [AsistenteController::class, 'recomendar'])->where('clave', '[a-z0-9\-]+');
    Route::get('/configuracion', [AsistenteController::class, 'configuracion']);
    Route::put('/configuracion', [AsistenteController::class, 'actualizarConfiguracion']);
    Route::get('/preguntas', [AsistenteController::class, 'preguntas']);
    Route::get('/compuerta', [AsistenteController::class, 'compuerta']);
    Route::get('/consumo', [AsistenteController::class, 'consumo']);
    Route::get('/usuarios', [AsistenteController::class, 'usuarios']);
});

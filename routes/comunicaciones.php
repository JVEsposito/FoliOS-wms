<?php

use App\Http\Controllers\Api\ComunicacionesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'can:usar-comunicaciones-internas'])->prefix('comunicaciones')->group(function (): void {
    Route::get('/resumen', [ComunicacionesController::class, 'resumen']);
    Route::get('/usuarios', [ComunicacionesController::class, 'usuarios']);
    Route::get('/chat/conversaciones', [ComunicacionesController::class, 'conversaciones']);
    Route::post('/chat/privados', [ComunicacionesController::class, 'privado'])->middleware('throttle:30,1');
    Route::get('/chat/conversaciones/{conversacion}/mensajes', [ComunicacionesController::class, 'mensajes'])->whereNumber('conversacion');
    Route::post('/chat/conversaciones/{conversacion}/mensajes', [ComunicacionesController::class, 'enviarMensaje'])->whereNumber('conversacion')->middleware('throttle:60,1');
    Route::post('/chat/conversaciones/{conversacion}/leer', [ComunicacionesController::class, 'leerChat'])->whereNumber('conversacion');
    Route::get('/stickers', [ComunicacionesController::class, 'stickers']);
    Route::post('/stickers', [ComunicacionesController::class, 'subirSticker'])->middleware('throttle:5,1');
    Route::delete('/stickers/{sticker}', [ComunicacionesController::class, 'ocultarSticker'])->whereNumber('sticker');
    Route::get('/stickers/{sticker}/archivo', [ComunicacionesController::class, 'archivoSticker'])->whereNumber('sticker');
    Route::get('/correos', [ComunicacionesController::class, 'correos']);
    Route::post('/correos', [ComunicacionesController::class, 'enviarCorreo'])->middleware('throttle:15,1');
    Route::get('/correos/{mensaje}', [ComunicacionesController::class, 'verCorreo'])->whereNumber('mensaje');
    Route::post('/correos/{mensaje}/leer', [ComunicacionesController::class, 'leerCorreo'])->whereNumber('mensaje');
    Route::post('/correos/{mensaje}/archivar', [ComunicacionesController::class, 'archivarCorreo'])->whereNumber('mensaje');
});

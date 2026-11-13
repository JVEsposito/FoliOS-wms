<?php

use App\Http\Controllers\Api\ComunicacionesController;
use Illuminate\Support\Facades\Route;

// Cada límite lleva su propio prefijo: sin él, todos los «throttle:N,1» de FoliOS comparten
// el contador del usuario y chatear bloquearía, por ejemplo, subir un sticker o un ajuste de stock.
Route::middleware(['auth:sanctum', 'can:usar-comunicaciones-internas'])->prefix('comunicaciones')->group(function (): void {
    Route::get('/resumen', [ComunicacionesController::class, 'resumen']);
    Route::get('/usuarios', [ComunicacionesController::class, 'usuarios']);
    Route::get('/referencias', [ComunicacionesController::class, 'referencias'])->middleware('throttle:120,1,com-referencias:');
    Route::get('/referencias/{tipo}/{id}', [ComunicacionesController::class, 'fichaReferencia'])
        ->where(['tipo' => '[a-z_]{2,30}', 'id' => '[A-Za-z0-9-]{1,36}'])->middleware('throttle:120,1,com-fichas:');
    Route::get('/chat/conversaciones', [ComunicacionesController::class, 'conversaciones']);
    Route::post('/chat/privados', [ComunicacionesController::class, 'privado'])->middleware('throttle:30,1,com-privados:');
    Route::get('/chat/conversaciones/{conversacion}/mensajes', [ComunicacionesController::class, 'mensajes'])->whereNumber('conversacion');
    Route::post('/chat/conversaciones/{conversacion}/mensajes', [ComunicacionesController::class, 'enviarMensaje'])->whereNumber('conversacion')->middleware('throttle:60,1,com-chat:');
    Route::post('/chat/conversaciones/{conversacion}/leer', [ComunicacionesController::class, 'leerChat'])->whereNumber('conversacion');
    Route::get('/stickers', [ComunicacionesController::class, 'stickers']);
    Route::post('/stickers', [ComunicacionesController::class, 'subirSticker'])->middleware('throttle:5,1,com-stickers:');
    Route::delete('/stickers/{sticker}', [ComunicacionesController::class, 'ocultarSticker'])->whereNumber('sticker');
    Route::get('/stickers/{sticker}/archivo', [ComunicacionesController::class, 'archivoSticker'])->whereNumber('sticker');
    Route::get('/correos', [ComunicacionesController::class, 'correos']);
    Route::post('/correos', [ComunicacionesController::class, 'enviarCorreo'])->middleware('throttle:15,1,com-correos:');
    Route::get('/correos/{mensaje}', [ComunicacionesController::class, 'verCorreo'])->whereNumber('mensaje');
    Route::post('/correos/{mensaje}/leer', [ComunicacionesController::class, 'leerCorreo'])->whereNumber('mensaje');
    Route::post('/correos/{mensaje}/archivar', [ComunicacionesController::class, 'archivarCorreo'])->whereNumber('mensaje');
});

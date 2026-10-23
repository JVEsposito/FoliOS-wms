<?php

use App\Services\Integraciones\ServicioOperacionesIntegracion;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Verificaciones\ServicioVerificacionesUbicacion;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Laravel\Telescope\TelescopeServiceProvider;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (app()->environment('local') &&
    class_exists(TelescopeServiceProvider::class)) {
    Schedule::command('telescope:prune --hours=48')->dailyAt('02:00');
}

Schedule::command('folios:auditar-integridad --origen=programada')
    ->everyFifteenMinutes()
    ->withoutOverlapping(20);

Schedule::command('tareas:expirar-reservas --limite=250')
    ->everyMinute()
    ->withoutOverlapping(2);

Schedule::command('planificador:recalcular-arbitraje')
    ->everyMinute()
    ->withoutOverlapping(2);

Schedule::command('planificador:recuperar-proyecciones')
    ->everyMinute()
    ->withoutOverlapping(2);

Schedule::call(function (): void {
    if (! config('verificaciones.habilitada')) {
        return;
    }
    $temporada = app(ServicioTemporadaActiva::class)->buscar();
    if ($temporada) {
        app(ServicioVerificacionesUbicacion::class)->vencer($temporada);
    }
})->everyMinute()->name('verificaciones-ubicacion-vencer')->withoutOverlapping(2);

Schedule::command('sag:preparar-traslados')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('salud:latido-scheduler')->everyMinute();

// Stock del sistema de origen para «Pendientes por validar»: solo lectura, sin crear folios.
Schedule::command('integraciones:leer-stock-origen')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

Artisan::command('integraciones:limpiar-operaciones', function () {
    app(ServicioOperacionesIntegracion::class)->limpiar();
})->purpose('Elimina resultados temporales y vence consultas de integración interrumpidas.');
Schedule::command('integraciones:limpiar-operaciones')->everyMinute()->withoutOverlapping(2);

Schedule::command('materiales:procesar-vencimientos')
    ->dailyAt('00:15')
    ->timezone('America/Santiago')
    ->withoutOverlapping();

Schedule::command('materiales:recalcular-reposicion')->dailyAt('06:00')->timezone('America/Santiago')->withoutOverlapping();

// Finanzas: lee la operación (solo lectura) y registra los hechos valorizables en su base.
Schedule::command('finanzas:capturar-hechos --programada')
    ->everyTenMinutes()
    ->withoutOverlapping(30);

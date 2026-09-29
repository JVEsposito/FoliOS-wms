<?php

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
    if (! config('verificaciones.habilitada')) return;
    $temporada = app(\App\Services\Temporadas\ServicioTemporadaActiva::class)->buscar();
    if ($temporada) app(\App\Services\Verificaciones\ServicioVerificacionesUbicacion::class)->vencer($temporada);
})->everyMinute()->name('verificaciones-ubicacion-vencer')->withoutOverlapping(2);

Schedule::command('salud:latido-scheduler')->everyMinute();

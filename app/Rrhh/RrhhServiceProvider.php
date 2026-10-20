<?php

namespace App\Rrhh;

use App\Gestion\ModulosGestion;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class RrhhServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(ModulosGestion::class, fn (ModulosGestion $modulos) => $modulos->registrar(EsquemaRrhh::class));
    }

    public function boot(): void
    {
        RateLimiter::for('rrhh-marcaje', fn (Request $r) => [Limit::perMinute(60)->by('puesto:'.$r->user()?->id.'|'.$r->ip()), Limit::perMinute(10)->by('codigo:'.hash('sha256', strtoupper((string) $r->input('codigo'))))]);
        RateLimiter::for('rrhh-gestion', fn (Request $r) => Limit::perMinute(30)->by((string) $r->user()?->id));
        RateLimiter::for('rrhh-importaciones', fn (Request $r) => Limit::perMinute(10)->by((string) $r->user()?->id));
    }
}

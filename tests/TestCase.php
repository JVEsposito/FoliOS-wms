<?php

namespace Tests;

use App\Models\Temporada;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Las migraciones crean una temporada GENERAL activa. Las pruebas que
     * preparan otra temporada deben cerrar esa vigencia antes del insert.
     */
    protected function desactivarTemporadasDePrueba(): void
    {
        DB::table('temporadas')->where('activa', true)->update(['activa' => false]);
        app(ServicioTemporadaActiva::class)->olvidar();
    }

    /** @param array<string, mixed> $atributos */
    protected function crearTemporadaActivaPrueba(array $atributos): Temporada
    {
        $this->desactivarTemporadasDePrueba();

        return Temporada::create([...$atributos, 'activa' => true]);
    }

    public function withToken($token, $type = 'Bearer')
    {
        if (isset($this->app)) {
            $this->app->make('auth')->forgetGuards();
        }

        return parent::withToken($token, $type);
    }

    /**
     * Fechas y prefijo documental para una temporada productiva creada en una
     * prueba. Cada llamada usa otro año, de modo que nunca se cruzan entre sí.
     *
     * @return array{fecha_inicio: string, fecha_fin: string, prefijo_documental: string}
     */
    protected function vigenciaProductiva(): array
    {
        static $secuencia = 0;
        $secuencia++;
        $anio = 2100 + $secuencia;

        return [
            'fecha_inicio' => "{$anio}-01-01",
            'fecha_fin' => "{$anio}-12-31",
            'prefijo_documental' => 'Z'.str_pad((string) $secuencia, 4, '0', STR_PAD_LEFT),
        ];
    }
}

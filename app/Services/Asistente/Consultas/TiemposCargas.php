<?php

namespace App\Services\Asistente\Consultas;

use App\Models\Carga;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;

/** Duración de las cargas cerradas en el periodo, desde su publicación hasta el cierre. */
class TiemposCargas extends ConsultaBase
{
    public function clave(): string
    {
        return 'tiempos-cargas';
    }

    public function titulo(): string
    {
        return 'Tiempos de cargas';
    }

    public function descripcion(): string
    {
        return 'Duración de las cargas cerradas, desde su publicación hasta el cierre.';
    }

    public function dominio(): string
    {
        return 'frigorifico';
    }

    public function palabrasClave(): array
    {
        return ['carga', 'cargas', 'despacho', 'despachos', 'anden', 'carguio', 'embarque', 'tiempo', 'tiempos', 'demora'];
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeConsultarCargas($usuario);
    }

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $temporada = $this->temporada($parametros);
        [$desde, $hasta] = $parametros->rango();
        $cargas = $temporada === null ? collect() : Carga::query()
            ->where('temporada_id', $temporada->id)
            ->whereNotNull('publicada_at')
            ->where('cerrada_at', '>=', $desde)->where('cerrada_at', '<', $hasta)
            ->get(['id', 'codigo', 'publicada_at', 'cerrada_at']);

        if ($cargas->isEmpty()) {
            return ResultadoConsulta::sinDatos($this->titulo(), "No hay cargas cerradas el {$this->periodo($parametros)}.", '/oficina/frigorifico/despacho/cargas');
        }

        $filas = $cargas->map(fn (Carga $carga): array => [
            'codigo' => $carga->codigo,
            'minutos' => self::minutos($carga->publicada_at->diffInMinutes($carga->cerrada_at, true)),
            'cerrada' => $this->hora($carga->cerrada_at),
        ])->sortByDesc('minutos')->values();

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $this->titulo(),
            sprintf('El %s se cerraron %d cargas con %d min de promedio desde su publicación.', $this->periodo($parametros), $filas->count(), self::minutos($filas->avg('minutos'))),
            [
                ResultadoConsulta::cifra('Cargas cerradas', $filas->count()),
                ResultadoConsulta::cifra('Duración promedio', self::minutos($filas->avg('minutos')), 'min'),
                ResultadoConsulta::cifra('Duración máxima', (int) $filas->max('minutos'), 'min'),
            ],
            [
                ['clave' => 'codigo', 'titulo' => 'Carga', 'tipo' => 'codigo'],
                ['clave' => 'minutos', 'titulo' => 'Minutos', 'tipo' => 'numero'],
                ['clave' => 'cerrada', 'titulo' => 'Cierre'],
            ],
            $filas->all(),
            '/oficina/frigorifico/despacho/cargas',
        );
    }
}

<?php

namespace App\Services\Asistente\Consultas;

use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;

/** Cajas de producto terminado en existencia por formato de embalaje. */
class FormatosPt extends ConsultaProductoTerminado
{
    public function clave(): string
    {
        return 'formatos-pt';
    }

    public function titulo(): string
    {
        return 'Formatos de embalaje';
    }

    public function descripcion(): string
    {
        return 'Cajas en existencia por formato de embalaje.';
    }

    public function palabrasClave(): array
    {
        return ['formato', 'formatos', 'embalaje', 'embalajes', 'envase', 'envases'];
    }

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $filas = $this->filas($parametros);
        if ($filas->isEmpty()) {
            return ResultadoConsulta::sinDatos($this->titulo(), 'No hay producto terminado en existencia.', '/oficina/frigorifico/existencias');
        }

        $grupos = $filas->groupBy(fn (array $fila): string => (string) ($fila['envase'] ?? 'Sin formato'))
            ->map(fn ($grupo, string $formato): array => [
                'formato' => $formato,
                'folios' => $grupo->pluck('folio')->unique()->count(),
                'cajas' => $grupo->sum(fn (array $fila): int => self::cajas($fila['cantidad_cajas'] ?? null)),
            ])->sortByDesc('cajas')->values();

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $this->titulo(),
            sprintf('Hay %d formatos de embalaje en existencia; el principal es %s.', $grupos->count(), $grupos->first()['formato']),
            [
                ResultadoConsulta::cifra('Formatos', $grupos->count()),
                ResultadoConsulta::cifra('Cajas', $grupos->sum('cajas')),
            ],
            [
                ['clave' => 'formato', 'titulo' => 'Formato de embalaje'],
                ['clave' => 'folios', 'titulo' => 'Folios', 'tipo' => 'numero'],
                ['clave' => 'cajas', 'titulo' => 'Cajas', 'tipo' => 'numero'],
            ],
            $grupos->all(),
            '/oficina/frigorifico/existencias',
        );
    }
}

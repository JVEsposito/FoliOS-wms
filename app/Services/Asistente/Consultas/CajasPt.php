<?php

namespace App\Services\Asistente\Consultas;

use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;

/** Cajas de producto terminado en existencia, por cliente y especie. */
class CajasPt extends ConsultaProductoTerminado
{
    public function clave(): string
    {
        return 'cajas-pt';
    }

    public function titulo(): string
    {
        return 'Cajas de producto terminado';
    }

    public function descripcion(): string
    {
        return 'Cajas y folios en existencia, por cliente y especie.';
    }

    public function palabrasClave(): array
    {
        return ['cajas', 'caja', 'pallets', 'pallet', 'pt', 'existencia', 'stock', 'folios', 'terminado'];
    }

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $filas = $this->filas($parametros);
        if ($filas->isEmpty()) {
            return ResultadoConsulta::sinDatos($this->titulo(), 'No hay producto terminado en existencia.', '/oficina/frigorifico/existencias');
        }

        $grupos = $filas->groupBy(fn (array $fila): string => ($fila['cliente'] ?? '—').'|'.($fila['especie'] ?? '—'))
            ->map(fn ($grupo): array => [
                'cliente' => $grupo->first()['cliente'] ?? '—',
                'especie' => $grupo->first()['especie'] ?? '—',
                'folios' => $grupo->pluck('folio')->unique()->count(),
                'cajas' => $grupo->sum(fn (array $fila): int => self::cajas($fila['cantidad_cajas'] ?? null)),
            ])->sortByDesc('cajas')->values();
        $cajas = $grupos->sum('cajas');
        $folios = $filas->pluck('folio')->unique()->count();

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $this->titulo(),
            sprintf('Hay %s cajas en %s folios de producto terminado.', number_format($cajas, 0, ',', '.'), number_format($folios, 0, ',', '.')),
            [ResultadoConsulta::cifra('Cajas', $cajas), ResultadoConsulta::cifra('Folios', $folios)],
            [
                ['clave' => 'cliente', 'titulo' => 'Cliente'],
                ['clave' => 'especie', 'titulo' => 'Especie'],
                ['clave' => 'folios', 'titulo' => 'Folios', 'tipo' => 'numero'],
                ['clave' => 'cajas', 'titulo' => 'Cajas', 'tipo' => 'numero'],
            ],
            $grupos->all(),
            '/oficina/frigorifico/existencias',
        );
    }
}

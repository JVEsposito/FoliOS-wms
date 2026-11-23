<?php

namespace App\Services\Asistente\Consultas;

use App\Models\ImputacionConsumoMaterial;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;

/** Consumo de materiales imputado a órdenes de trabajo, con su merma. */
class ConsumosOt extends ConsultaBase
{
    public function clave(): string
    {
        return 'consumos-ot';
    }

    public function titulo(): string
    {
        return 'Consumos por OT';
    }

    public function descripcion(): string
    {
        return 'Cantidad consumida y merma por orden de trabajo.';
    }

    public function dominio(): string
    {
        return 'materiales';
    }

    public function filtros(): array
    {
        return ['periodo', 'cliente'];
    }

    public function palabrasClave(): array
    {
        return ['consumo', 'consumos', 'consumido', 'ot', 'orden', 'ordenes', 'merma', 'mermas', 'materiales', 'material', 'estandar'];
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeConsultarTransformacionesMateriales($usuario);
    }

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        [$desde, $hasta] = $parametros->rango();
        $filas = ImputacionConsumoMaterial::query()
            ->join('ordenes_transformacion_materiales as ordenes', 'ordenes.id', '=', 'imputaciones_consumo_materiales.orden_transformacion_material_id')
            ->where('imputaciones_consumo_materiales.created_at', '>=', $desde)
            ->where('imputaciones_consumo_materiales.created_at', '<', $hasta)
            ->when($parametros->clienteId, fn ($q, $id) => $q->where('ordenes.cliente_id', $id))
            ->groupBy('ordenes.id', 'ordenes.fecha_operacional', 'ordenes.linea', 'ordenes.turno')
            ->selectRaw('ordenes.fecha_operacional as fecha, ordenes.linea as linea, ordenes.turno as turno, count(*) as imputaciones, sum(imputaciones_consumo_materiales.cantidad) as cantidad, sum(imputaciones_consumo_materiales.cantidad_merma) as merma')
            ->orderByDesc('cantidad')
            ->get();

        if ($filas->isEmpty()) {
            return ResultadoConsulta::sinDatos($this->titulo(), "No hay consumos imputados a órdenes el {$this->periodo($parametros)}.", '/oficina/materiales/consumos');
        }

        $redondeo = fn ($valor): float => round((float) $valor, 3);

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $this->titulo(),
            sprintf('El %s se imputaron consumos a %d órdenes, con una merma total de %s.', $this->periodo($parametros), $filas->count(), number_format($redondeo($filas->sum('merma')), 3, ',', '.')),
            [
                ResultadoConsulta::cifra('Órdenes', $filas->count()),
                ResultadoConsulta::cifra('Cantidad consumida', $redondeo($filas->sum('cantidad'))),
                ResultadoConsulta::cifra('Merma', $redondeo($filas->sum('merma'))),
            ],
            [
                ['clave' => 'fecha', 'titulo' => 'Fecha'],
                ['clave' => 'linea', 'titulo' => 'Línea'],
                ['clave' => 'turno', 'titulo' => 'Turno'],
                ['clave' => 'cantidad', 'titulo' => 'Consumido', 'tipo' => 'numero'],
                ['clave' => 'merma', 'titulo' => 'Merma', 'tipo' => 'numero'],
            ],
            $filas->map(fn ($fila): array => [
                'fecha' => (string) $fila->fecha,
                'linea' => $fila->linea ?? '—',
                'turno' => $fila->turno ?? '—',
                'cantidad' => $redondeo($fila->cantidad),
                'merma' => $redondeo($fila->merma),
            ])->all(),
            '/oficina/materiales/consumos',
        );
    }
}

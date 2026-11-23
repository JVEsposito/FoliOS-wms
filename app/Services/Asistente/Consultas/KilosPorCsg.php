<?php

namespace App\Services\Asistente\Consultas;

use App\Enums\EstadoRecepcionRomana;
use App\Models\LoteMateriaPrima;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;

/**
 * Kilos netos recibidos por CSG, desde los lotes de materia prima de las recepciones
 * ingresadas en el periodo.
 */
class KilosPorCsg extends ConsultaBase
{
    public function clave(): string
    {
        return 'kilos-por-csg';
    }

    public function titulo(): string
    {
        return 'Kilos por CSG';
    }

    public function descripcion(): string
    {
        return 'Kilos netos recibidos por CSG, según los lotes de materia prima.';
    }

    public function dominio(): string
    {
        return 'materia-prima';
    }

    public function palabrasClave(): array
    {
        return ['csg', 'productor', 'productores', 'lote', 'lotes', 'predio'];
    }

    public function palabrasSecundarias(): array
    {
        return ['kilos', 'kilo', 'kg'];
    }

    public function filtros(): array
    {
        return ['periodo', 'cliente', 'csg'];
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeConsultarMateriaPrima($usuario);
    }

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $temporada = $this->temporada($parametros);
        [$desde, $hasta] = $parametros->rango();
        $filas = $temporada === null ? collect() : LoteMateriaPrima::query()
            ->join('recepciones_romana', 'recepciones_romana.id', '=', 'lotes_materia_prima.recepcion_romana_id')
            ->where('lotes_materia_prima.temporada_id', $temporada->id)
            ->whereNull('lotes_materia_prima.anulado_at')
            ->whereNull('recepciones_romana.eliminada_at')
            ->where('recepciones_romana.estado', '!=', EstadoRecepcionRomana::Anulada->value)
            ->where('recepciones_romana.ingreso_at', '>=', $desde)->where('recepciones_romana.ingreso_at', '<', $hasta)
            ->when($parametros->clienteId, fn ($q, $id) => $q->where('lotes_materia_prima.cliente_id', $id))
            ->when($parametros->csgId, fn ($q, $id) => $q->where('lotes_materia_prima.csg_validacion_id', $id))
            ->groupBy('lotes_materia_prima.csg_snapshot')
            ->selectRaw('lotes_materia_prima.csg_snapshot as csg, count(*) as lotes, sum(lotes_materia_prima.kilos_netos_confirmados) as kilos')
            ->orderByDesc('kilos')
            ->get();

        if ($filas->isEmpty()) {
            return ResultadoConsulta::sinDatos($this->titulo(), "No hay lotes de materia prima de recepciones del {$this->periodo($parametros)}.", '/oficina/materia-prima/recepciones-csg');
        }

        $kilos = round((float) $filas->sum('kilos'), 1);

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $this->titulo(),
            sprintf('El %s se recibieron %s kg netos de %d CSG.', $this->periodo($parametros), number_format($kilos, 1, ',', '.'), $filas->count()),
            [
                ResultadoConsulta::cifra('Kilos netos', $kilos, 'kg'),
                ResultadoConsulta::cifra('CSG', $filas->count()),
                ResultadoConsulta::cifra('Lotes', (int) $filas->sum('lotes')),
            ],
            [
                ['clave' => 'csg', 'titulo' => 'CSG', 'tipo' => 'codigo'],
                ['clave' => 'lotes', 'titulo' => 'Lotes', 'tipo' => 'numero'],
                ['clave' => 'kilos', 'titulo' => 'Kilos netos', 'tipo' => 'numero'],
            ],
            $filas->map(fn ($fila): array => [
                'csg' => $fila->csg ?? 'Sin CSG',
                'lotes' => (int) $fila->lotes,
                'kilos' => round((float) $fila->kilos, 1),
            ])->all(),
            '/oficina/materia-prima/recepciones-csg',
        );
    }
}

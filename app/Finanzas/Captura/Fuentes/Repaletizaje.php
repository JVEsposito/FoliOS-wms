<?php

namespace App\Finanzas\Captura\Fuentes;

use App\Finanzas\Captura\ContextoCaptura;
use App\Finanzas\Captura\FolioLeido;
use App\Finanzas\Captura\FuenteTabla;
use App\Finanzas\Captura\HechoCapturado;
use App\Finanzas\Models\Hecho;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/** Cada repaletizaje confirmado: cuántos folios juntó o separó y las cajas resultantes. */
final class Repaletizaje extends FuenteTabla
{
    public function clave(): string
    {
        return 'repaletizaje';
    }

    public function nombre(): string
    {
        return 'Repaletizajes';
    }

    public function descripcion(): string
    {
        return 'Repaletizaje confirmado (sin anular).';
    }

    public function medidas(): array
    {
        return [
            'operaciones' => ['nombre' => 'Repaletizajes', 'unidad' => 'operación'],
            'folios_origen' => ['nombre' => 'Folios de origen', 'unidad' => 'folio'],
            'folios_resultantes' => ['nombre' => 'Folios resultantes', 'unidad' => 'folio'],
            'cajas' => ['nombre' => 'Cajas resultantes', 'unidad' => 'caja'],
        ];
    }

    protected function columnaId(): string
    {
        return 'r.id';
    }

    protected function columnasCambio(): array
    {
        return ['r.updated_at'];
    }

    protected function consulta(ContextoCaptura $contexto): Builder
    {
        return $contexto->lector->tabla('repaletizajes', 'r')
            ->select([
                'r.id', 'r.codigo', 'r.modalidad', 'r.tipo_resultado', 'r.estado', 'r.folio_resultante_id',
                'r.cantidad_resultante', 'r.confirmado_at', 'r.turno',
            ])
            ->selectSub(fn (Builder $detalles) => $detalles->from('repaletizaje_detalles as d')
                ->selectRaw('COUNT(*)')->whereColumn('d.repaletizaje_id', 'r.id'), 'folios_origen')
            ->selectSub(fn (Builder $resultados) => $resultados->from('repaletizaje_resultados as x')
                ->selectRaw('COUNT(*)')->whereColumn('x.repaletizaje_id', 'r.id'), 'folios_resultantes');
    }

    protected function mapear(ContextoCaptura $contexto, Collection $filas): iterable
    {
        $folios = FolioLeido::leer($contexto, $filas->pluck('folio_resultante_id')->all());

        foreach ($filas as $fila) {
            $folio = $folios[$fila->folio_resultante_id] ?? null;

            yield new HechoCapturado(
                origenId: $fila->id,
                estado: $fila->estado === 'confirmado' ? Hecho::ESTADO_VIGENTE : Hecho::ESTADO_ANULADO,
                ocurridoAt: ContextoCaptura::fecha($fila->confirmado_at),
                clienteId: $folio?->clienteId,
                clienteNombre: $folio?->clienteNombre,
                temporadaId: $folio?->temporadaId,
                temporadaCodigo: $contexto->temporadaCodigo($folio?->temporadaId),
                referencia: $fila->codigo,
                medidas: [
                    'operaciones' => 1,
                    'folios_origen' => (int) $fila->folios_origen,
                    'folios_resultantes' => max(1, (int) $fila->folios_resultantes),
                    'cajas' => (int) $fila->cantidad_resultante,
                ],
                datos: [
                    'modalidad' => $fila->modalidad,
                    'tipo_resultado' => $fila->tipo_resultado,
                    'folio_resultante' => $folio?->numero,
                    'turno' => $fila->turno,
                ],
            );
        }
    }
}

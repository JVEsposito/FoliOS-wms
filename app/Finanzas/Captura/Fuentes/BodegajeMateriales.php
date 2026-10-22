<?php

namespace App\Finanzas\Captura\Fuentes;

use App\Finanzas\Captura\ContextoCaptura;
use App\Finanzas\Captura\FuenteTabla;
use App\Finanzas\Captura\HechoCapturado;
use App\Finanzas\Models\Hecho;
use App\Finanzas\Valorizacion\HistorialStock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

final class BodegajeMateriales extends FuenteTabla
{
    protected const LOTE = 100;

    public function clave(): string
    {
        return 'material_bodegaje';
    }

    public function nombre(): string
    {
        return 'Bodegaje de materiales';
    }

    public function descripcion(): string
    {
        return 'Permanencia por folio o unidad y día civil. Usa el máximo de existencia del día, reconstruido desde el kardex, incluyendo retiros y devoluciones.';
    }

    public function intervalo(): bool
    {
        return true;
    }

    public function contexto(): array
    {
        return ['area' => 'materiales', 'dimensiones' => ['item_id' => 'Ítem', 'folio_id' => 'Folio', 'unidad_medida' => 'Unidad de medida']];
    }

    public function medidas(): array
    {
        return ['folios' => ['nombre' => 'Folios con existencia', 'unidad' => 'folio'], 'unidades' => ['nombre' => 'Existencia del día', 'unidad' => 'unidad material', 'dimension_unidad' => 'unidad_medida']];
    }

    protected function columnaId(): string
    {
        return 'fm.folio_id';
    }

    protected function columnasCambio(): array
    {
        return ['fm.updated_at', 'cm.updated_at'];
    }

    protected function consulta(ContextoCaptura $c): Builder
    {
        return $c->lector->tabla('folios_materiales', 'fm')->join('folios as f', 'f.id', '=', 'fm.folio_id')->join('items_materiales as i', 'i.id', '=', 'fm.item_material_id')->join('clientes_materiales as cm', 'cm.id', '=', 'i.cliente_material_id')
            ->select(['fm.folio_id as id', 'fm.item_material_id as item_id', 'fm.unidad_medida', 'fm.cantidad_inicial', 'fm.cantidad_actual', 'f.numero_folio', 'f.created_at', 'f.temporada_id', 'cm.cliente_id', 'cm.nombre as cliente_nombre']);
    }

    protected function mapear(ContextoCaptura $c, Collection $filas): iterable
    {
        // La comprobación de saldo y el kardex se leen en una misma foto de la operación.
        $hechos = $c->lector->conexion()->transaction(function () use ($c, $filas): array {
            $actuales = $this->consulta($c)->whereIn('fm.folio_id', $filas->pluck('id'))->get();
            $movimientos = $c->lector->tabla('movimientos_inventario_materiales')->whereIn('folio_id', $actuales->pluck('id'))->orderBy('ocurrido_at')->orderBy('id')
                ->get(['id', 'folio_id', 'cantidad_anterior', 'cantidad_resultante', 'ocurrido_at'])->groupBy('folio_id');
            $resultado = [];
            foreach ($actuales as $fila) {
                $historial = app(HistorialStock::class)->construir($fila->created_at, $fila->cantidad_inicial, $fila->cantidad_actual,
                    ($movimientos[$fila->id] ?? collect())->map(fn ($r): array => (array) $r)->all(), config('app.operational_timezone'));
                [$clienteId, $clienteNombre] = $c->cliente($fila->cliente_id, $fila->cliente_nombre);
                $resultado[] = new HechoCapturado(origenId: $fila->id, estado: $historial['tuvo_stock'] ? Hecho::ESTADO_VIGENTE : Hecho::ESTADO_ANULADO,
                    ocurridoAt: $historial['inicio'], terminaAt: $historial['fin'], clienteId: $clienteId, clienteNombre: $clienteNombre,
                    temporadaId: $fila->temporada_id, temporadaCodigo: $c->temporadaCodigo($fila->temporada_id), referencia: $fila->numero_folio, medidas: $historial['medidas'],
                    datos: ['folio_id' => $fila->id, 'item_id' => $fila->item_id, 'unidad_medida' => $fila->unidad_medida, 'tramos' => $historial['tramos'], 'picos_diarios' => $historial['picos_diarios'], 'kardex' => $historial['aportes'], 'criterio_diario' => 'maximo']);
            }

            return $resultado;
        });
        yield from $hechos;
    }
}

<?php

namespace App\Services\Materiales;

use App\Models\AlmacenMaterial;
use App\Models\ClienteMaterial;
use App\Models\ItemMaterial;
use App\Models\SaldoMaterialAlmacen;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ServicioConsultaVencimientosMaterial
{
    public function consulta(array $filtros = []): Builder
    {
        $hoy = ServicioVencimientoMaterial::hoyChile();
        $consulta = SaldoMaterialAlmacen::query()
            ->join('folios_materiales as fm', 'fm.folio_id', '=', 'saldos_materiales_almacenes.folio_id')
            ->join('items_materiales as i', 'i.id', '=', 'fm.item_material_id')
            ->join('folios as f', 'f.id', '=', 'fm.folio_id')
            ->select('saldos_materiales_almacenes.*')
            ->where('saldos_materiales_almacenes.cantidad_actual', '>', 0)
            ->where('f.activo', true)
            ->whereHas('folioMaterial.folio.temporada', fn ($q) => $q->where('activa', true))
            ->whereNotNull('fm.fecha_vencimiento')
            ->when($filtros['cliente_id'] ?? null, fn ($q, $id) => $q->where('i.cliente_material_id', $id))
            ->when($filtros['categoria'] ?? null, fn ($q, $categoria) => $q->where('i.categoria', $categoria))
            ->when($filtros['almacen_id'] ?? null, fn ($q, $id) => $q->where('saldos_materiales_almacenes.almacen_material_id', $id));
        $alerta = max(0, (int) config('materiales.dias_alerta_vencimiento', 30));
        $limite = DB::getDriverName() === 'sqlite'
            ? "date(?, '+' || COALESCE(i.dias_alerta_vencimiento, ?) || ' days')"
            : 'DATE_ADD(?, INTERVAL COALESCE(i.dias_alerta_vencimiento, ?) DAY)';
        if (($filtros['estado'] ?? null) === 'vencido') {
            $consulta->where('fm.fecha_vencimiento', '<', $hoy);
        } else {
            if (($filtros['estado'] ?? null) === 'por_vencer') {
                $consulta->where('fm.fecha_vencimiento', '>=', $hoy);
            }
            $consulta->whereRaw("fm.fecha_vencimiento <= {$limite}", [$hoy, $alerta]);
        }

        return $consulta->orderBy('fm.fecha_vencimiento')->orderBy('f.numero_folio')->orderBy('saldos_materiales_almacenes.id');
    }

    public function cargar(Builder $consulta): Builder
    {
        return $consulta->with(['almacen', 'camara', 'posicion', 'folioMaterial.folio', 'folioMaterial.item.cliente']);
    }

    public function serializar(SaldoMaterialAlmacen $saldo): array
    {
        $material = $saldo->folioMaterial;

        return [
            'folio_id' => $material->folio_id, 'numero_folio' => $material->folio->numero_folio,
            'item' => $material->item->nombre, 'codigo_item' => $material->item->codigo,
            'cliente' => $material->item->cliente->nombre, 'categoria' => $material->item->categoria,
            'cantidad' => $saldo->cantidad_actual, 'unidad_medida' => $material->unidad_medida,
            'almacen' => $saldo->almacen->nombre, 'camara' => $saldo->camara?->codigo,
            'posicion' => $saldo->posicion?->etiqueta, 'motivo_bloqueo' => $material->motivo_bloqueo,
            'vencimiento' => $material->informacionVencimiento(),
        ];
    }

    public function resumen(array $filtros = []): array
    {
        $resumen = [];
        foreach (['por_vencer', 'vencido'] as $estado) {
            $consulta = $this->consulta([...$filtros, 'estado' => $estado]);
            $resumen[$estado] = [
                'folios' => (clone $consulta)->reorder()->distinct()->count('saldos_materiales_almacenes.folio_id'),
                'cantidades' => (clone $consulta)->reorder()
                    ->select('fm.unidad_medida')->selectRaw('SUM(saldos_materiales_almacenes.cantidad_actual) AS cantidad')
                    ->groupBy('fm.unidad_medida')->get()->map(fn ($fila) => [
                        'unidad_medida' => $fila->unidad_medida,
                        'cantidad' => number_format((float) $fila->cantidad, 3, '.', ''),
                    ])->all(),
            ];
        }

        return $resumen;
    }

    public function filtros(): array
    {
        return [
            'clientes' => ClienteMaterial::query()->whereHas('temporada', fn ($q) => $q->where('activa', true))
                ->orderBy('nombre')->get(['id', 'nombre']),
            'categorias' => ItemMaterial::query()->whereHas('cliente.temporada', fn ($q) => $q->where('activa', true))
                ->whereNotNull('categoria')->distinct()->orderBy('categoria')->pluck('categoria'),
            'almacenes' => AlmacenMaterial::query()->orderBy('nombre')->get(['id', 'nombre']),
        ];
    }
}

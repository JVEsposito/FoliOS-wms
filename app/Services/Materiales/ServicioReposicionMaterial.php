<?php

namespace App\Services\Materiales;

use App\Models\AlmacenMaterial;
use App\Models\ItemMaterial;
use App\Services\Gerencia\ServicioPanelGerencial;
use App\Services\Notificaciones\ServicioNotificacionesOperacionales;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServicioReposicionMaterial
{
    public const ESTADOS = ['quiebre', 'bajo_minimo', 'reponer', 'normal', 'sobre_maximo', 'sin_niveles'];

    public function dias(?int $dias = null): int
    {
        return max(1, min(365, $dias ?? (int) config('materiales.reposicion.dias_consumo', 30)));
    }

    private function items(?string $temporadaId = null)
    {
        $temporadaId ??= app(ServicioTemporadaActiva::class)->buscar()?->id;

        return ItemMaterial::with('cliente.temporada')->when(! $temporadaId, fn ($q) => $q->whereRaw('1 = 0'))->where('activo', true)->whereHas('cliente', fn ($q) => $q->where('activo', true)
            ->whereHas('temporada', fn ($t) => $t->where('temporada_id', $temporadaId)));
    }

    public function filas(array $filtros = [], bool $soloReposicion = true): Collection
    {
        return DB::transaction(function () use ($filtros, $soloReposicion) {
            $temporada = $filtros['temporada_id'] ?? app(ServicioTemporadaActiva::class)->buscar()?->id;
            $items = $this->items($temporada)->when($filtros['cliente_id'] ?? null, fn ($q, $id) => $q->where('cliente_material_id', $id))
                ->when($filtros['categoria'] ?? null, fn ($q, $c) => $q->where('categoria', $c))
                ->when($filtros['item_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->orderBy('codigo')->get();
            $dias = $this->dias($filtros['dias'] ?? null);
            $saldos = $this->saldos($items->modelKeys(), $temporada);
            $consumos = DB::query()->fromSub($this->movimientos($items->modelKeys(), $dias, $temporada), 'm')
                ->select('item_material_id')->selectRaw('SUM(cantidad) AS consumo')->groupBy('item_material_id')->get()->keyBy('item_material_id');

            return $items->map(fn ($item) => $this->fila($item, $saldos[$item->id] ?? null, (float) ($consumos[$item->id]->consumo ?? 0), $dias))
                ->filter(fn ($f) => isset($filtros['estado']) ? $f['estado'] === $filtros['estado'] : (! $soloReposicion || in_array($f['estado'], ['quiebre', 'bajo_minimo', 'reponer'], true)))
                ->sortBy([fn ($a, $b) => ($a['dias_cobertura'] ?? INF) <=> ($b['dias_cobertura'] ?? INF), ['codigo', 'asc'], ['id', 'asc']])->values();
        });
    }

    private function saldos(array $items, ?string $temporada): Collection
    {
        $bloqueado = '(fm.motivo_bloqueo IS NOT NULL OR (fm.fecha_vencimiento IS NOT NULL AND fm.fecha_vencimiento < ?) OR f.estado_operacional = ?)';
        $bodega = AlmacenMaterial::where('codigo', AlmacenMaterial::CODIGO_BODEGA_CENTRAL)->value('id');
        $hoy = ServicioVencimientoMaterial::hoyChile();

        return DB::table('saldos_materiales_almacenes as s')->join('folios_materiales as fm', 'fm.folio_id', '=', 's.folio_id')
            ->join('folios as f', 'f.id', '=', 's.folio_id')->whereIn('fm.item_material_id', $items)
            ->where('f.temporada_id', $temporada)->where('f.activo', true)->select('fm.item_material_id')
            ->selectRaw('SUM(s.cantidad_actual) AS total_empresa')
            ->selectRaw('SUM(CASE WHEN s.almacen_material_id = ? THEN s.cantidad_actual ELSE 0 END) AS bodega', [$bodega])
            ->selectRaw('SUM(CASE WHEN s.almacen_material_id = ? THEN s.cantidad_reservada ELSE 0 END) AS reservado', [$bodega])
            ->selectRaw("SUM(CASE WHEN s.almacen_material_id = ? AND $bloqueado THEN s.cantidad_actual ELSE 0 END) AS bloqueado_vencido", [$bodega, $hoy, 'bloqueado'])
            ->selectRaw("SUM(CASE WHEN s.almacen_material_id = ? AND NOT $bloqueado THEN CASE WHEN s.cantidad_actual > s.cantidad_reservada THEN s.cantidad_actual - s.cantidad_reservada ELSE 0 END ELSE 0 END) AS disponible", [$bodega, $hoy, 'bloqueado'])
            ->groupBy('fm.item_material_id')->get()->keyBy('item_material_id');
    }

    private function movimientos(array $items, int $dias, ?string $temporada): Builder
    {
        $inicio = Carbon::now('America/Santiago')->startOfDay()->subDays($dias - 1)->utc();
        $fin = now();
        $bodega = AlmacenMaterial::where('codigo', AlmacenMaterial::CODIGO_BODEGA_CENTRAL)->value('id');
        $almacenes = DB::table('movimientos_almacenes_materiales as m')->join('folios as f', 'f.id', '=', 'm.folio_id')
            ->whereIn('m.item_material_id', $items)->where('f.temporada_id', $temporada)->whereBetween('m.ocurrido_at', [$inicio, $fin])
            ->where(fn ($q) => $q->where(fn ($salida) => $salida->where('m.almacen_origen_id', $bodega)->whereIn('m.tipo', ['entrega', 'consumo', 'transferencia']))
                ->orWhere(fn ($devolucion) => $devolucion->where('m.almacen_destino_id', $bodega)->where('m.tipo', 'devolucion')))
            ->select('m.id', 'm.item_material_id', 'f.numero_folio as folio', 'm.tipo', 'm.ocurrido_at', 'm.motivo')
            ->selectRaw("CASE WHEN m.tipo = 'devolucion' THEN -ABS(m.cantidad) ELSE ABS(m.cantidad) END AS cantidad")
            ->selectRaw("'almacen' AS fuente");
        // El flujo legado/transformación modifica exclusivamente Bodega Central.
        // Las entregas con registro de almacén se leen arriba, sin su espejo de kardex.
        $inventario = DB::table('movimientos_inventario_materiales as m')->join('folios as f', 'f.id', '=', 'm.folio_id')
            ->whereIn('m.item_material_id', $items)->where('f.temporada_id', $temporada)->whereBetween('m.ocurrido_at', [$inicio, $fin])
            ->where(fn ($q) => $q->where('m.tipo', 'consumo_transformacion')
                ->orWhere(fn ($r) => $r->where('m.tipo', 'reversa_transformacion')->where('m.metadatos->sentido', 'restauracion_entrada'))
                ->orWhere(fn ($d) => $d->where('m.tipo', 'despacho')->whereNotExists(fn ($a) => $a->selectRaw('1')->from('movimientos_almacenes_materiales as a')->whereColumn('a.retiro_material_id', 'm.retiro_material_id'))))
            ->select('m.id', 'm.item_material_id', 'f.numero_folio as folio', 'm.tipo', 'm.ocurrido_at', 'm.motivo')
            ->selectRaw('-m.cantidad AS cantidad')->selectRaw("'inventario' AS fuente");

        return $almacenes->unionAll($inventario);
    }

    private function fila(ItemMaterial $item, ?object $saldo, float $consumo, int $dias): array
    {
        $disponible = round((float) ($saldo->disponible ?? 0), 3);
        $consumo = round($consumo, 3);
        $diario = $consumo / $dias;
        $min = $item->stock_minimo === null ? null : (float) $item->stock_minimo;
        $reorden = $item->punto_reorden === null ? null : (float) $item->punto_reorden;
        $max = $item->stock_maximo === null ? null : (float) $item->stock_maximo;
        $estado = $min === null && $reorden === null && $max === null ? 'sin_niveles'
            : ($disponible === 0.0 && $min !== null ? 'quiebre'
                : ($min !== null && $disponible < $min ? 'bajo_minimo'
                    : ($max !== null && $disponible > $max ? 'sobre_maximo'
                        : ($reorden !== null && $disponible <= $reorden ? 'reponer' : 'normal'))));
        $objetivo = $max ?? ($reorden === null ? null : $reorden * 2);

        return ['id' => $item->id, 'cliente_id' => $item->cliente_material_id, 'cliente' => $item->cliente->nombre,
            'codigo' => $item->codigo, 'item' => $item->nombre, 'categoria' => $item->categoria, 'unidad' => $item->unidad_medida,
            'disponible' => $disponible, 'reservado' => round((float) ($saldo->reservado ?? 0), 3),
            'bloqueado_vencido' => round((float) ($saldo->bloqueado_vencido ?? 0), 3), 'total_empresa' => round((float) ($saldo->total_empresa ?? 0), 3),
            'consumo_periodo' => $consumo, 'consumo_diario' => round($diario, 6), 'dias_cobertura' => $diario > 0 ? round($disponible / $diario, 2) : null,
            'cobertura_etiqueta' => $diario > 0 ? null : 'sin consumo', 'dias_periodo' => $dias,
            'stock_minimo' => $min, 'punto_reorden' => $reorden, 'stock_maximo' => $max,
            'cantidad_sugerida' => $objetivo === null ? null : (float) max(0, ceil(round($objetivo - $disponible, 3))), 'estado' => $estado];
    }

    public function detalle(ItemMaterial $item, int $dias, int $pagina = 1): array
    {
        return DB::transaction(function () use ($item, $dias, $pagina) {
            $fila = $this->filas(['item_id' => $item->id, 'dias' => $dias], false)->first();
            abort_unless($fila, 404);
            $consulta = DB::query()->fromSub($this->movimientos([$item->id], $dias, app(ServicioTemporadaActiva::class)->buscar()?->id), 'm');
            $semanas = collect();
            $semana = Carbon::now('America/Santiago')->startOfDay()->subDays($dias - 1)->startOfWeek();
            $ultima = Carbon::now('America/Santiago')->startOfWeek();
            while ($semana <= $ultima) {
                $siguiente = $semana->copy()->addWeek();
                $cantidad = (clone $consulta)->where('ocurrido_at', '>=', $semana->copy()->utc())->where('ocurrido_at', '<', $siguiente->copy()->utc())->sum('cantidad');
                $semanas->push(['semana' => $semana->toDateString(), 'consumo' => round((float) $cantidad, 3)]);
                $semana = $siguiente;
            }
            $movimientos = (clone $consulta)->orderByDesc('ocurrido_at')->orderBy('id')->paginate(100, ['*'], 'page', $pagina);
            $historial = DB::table('cambios_niveles_stock_materiales as c')->leftJoin('users as u', 'u.id', '=', 'c.user_id')->where('c.item_material_id', $item->id)
                ->select('c.*', 'u.name as responsable')->orderByDesc('c.ocurrido_at')->limit(50)->get();

            return ['item' => $fila, 'semanas' => $semanas, 'movimientos' => $movimientos, 'historial_niveles' => $historial];
        });
    }

    public function resumen(?string $temporadaId = null): array
    {
        $filas = $this->filas(['temporada_id' => $temporadaId], false);
        $conConsumo = $filas->whereNotNull('dias_cobertura');

        return ['quiebre' => $filas->where('estado', 'quiebre')->count(), 'bajo_minimo' => $filas->where('estado', 'bajo_minimo')->count(),
            'sobre_maximo' => $filas->where('estado', 'sobre_maximo')->count(), 'cobertura_media' => $conConsumo->isEmpty() ? null : round($conConsumo->avg('dias_cobertura'), 2),
            'menor_cobertura' => $conConsumo->take(10)->values()->all(), 'dias_periodo' => $this->dias()];
    }

    public function recalcularItem(string $id): bool
    {
        $item = ItemMaterial::find($id);
        if (! $item || (collect(NivelesStockMaterial::CAMPOS)->every(fn ($c) => $item->$c === null) && $item->estado_reposicion === null)) {
            return false;
        }

        return DB::transaction(function () use ($id) {
            $item = ItemMaterial::whereKey($id)->lockForUpdate()->firstOrFail();
            $fila = $this->filas(['item_id' => $id], false)->first();
            if (! $fila) {
                return false;
            }
            $cambio = $item->estado_reposicion !== $fila['estado'];
            $revision = (int) $item->reposicion_revision + ($cambio ? 1 : 0);
            $item->timestamps = false;
            $item->forceFill(['estado_reposicion' => $fila['estado'], 'reposicion_revision' => $revision, 'reposicion_calculada_at' => now()])->saveQuietly();
            if ($cambio && in_array($fila['estado'], ['quiebre', 'bajo_minimo'], true)) {
                app(ServicioNotificacionesOperacionales::class)->notificarReposicionMaterial($item, $fila, $revision);
            }
            DB::afterCommit(fn () => app(ServicioPanelGerencial::class)->invalidar());

            return $cambio;
        }, 3);
    }

    public function recalcularTodos(): int
    {
        $cambios = 0;
        foreach ($this->items()->where(fn ($q) => $q->whereNotNull('stock_minimo')->orWhereNotNull('punto_reorden')->orWhereNotNull('stock_maximo')->orWhereNotNull('estado_reposicion'))->cursor() as $item) {
            $cambios += $this->recalcularItem($item->id) ? 1 : 0;
        }

        return $cambios;
    }
}

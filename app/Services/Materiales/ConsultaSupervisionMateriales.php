<?php

namespace App\Services\Materiales;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Lecturas agregadas y paginadas; nunca reconstruye ni modifica existencias. */
class ConsultaSupervisionMateriales
{
    public function catalogos(): array
    {
        return [
            'temporadas' => DB::table('temporadas')->orderByDesc('fecha_inicio')->get(['id', 'nombre', 'activa']),
            'clientes' => DB::table('clientes_materiales as c')->join('temporadas_materiales as t', 't.id', '=', 'c.temporada_material_id')
                ->whereNotNull('c.cliente_id')->orderBy('c.nombre')->distinct()->get(['c.cliente_id as id', 'c.nombre', 't.temporada_id']),
            'almacenes' => DB::table('destinos_materiales')->orderBy('nombre')->get(['id', 'nombre']),
            'usuarios' => DB::table('users')->orderBy('name')->get(['id', 'name as nombre']),
            'hoy' => now(config('app.operational_timezone'))->toDateString(),
        ];
    }

    public function consultar(array $f): array
    {
        $consulta = match ($f['vista']) {
            'stock' => $this->stock($f),
            'inventario', 'almacenes' => $this->movimientos($f),
            'recepciones' => $this->recepciones($f),
            'diferencias' => $this->diferencias($f),
        };
        // El mismo conjunto filtrado alimenta indicadores y listado, antes de paginar.
        $resumen = match ($f['vista']) {
            'stock' => [
                'folios' => DB::query()->fromSub(clone $consulta, 's')->distinct()->count('folio_id'),
                'unidades' => DB::query()->fromSub(clone $consulta, 's')->select('unidad')
                    ->selectRaw('SUM(actual) as actual, SUM(reservada) as reservada, SUM(bloqueada) as bloqueada, SUM(disponible) as disponible')
                    ->groupBy('unidad')->orderBy('unidad')->get(),
            ],
            'diferencias' => ['unidades' => DB::query()->fromSub(clone $consulta, 'd')->select('unidad')
                ->selectRaw('SUM(CASE WHEN diferencia < 0 THEN -diferencia ELSE 0 END) as faltante')
                ->selectRaw('SUM(CASE WHEN diferencia > 0 THEN diferencia ELSE 0 END) as sobrante')
                ->groupBy('unidad')->orderBy('unidad')->get()],
            default => ['tipos' => DB::query()->fromSub(clone $consulta, 'm')->select('tipo')->selectRaw('COUNT(*) as registros')
                ->groupBy('tipo')->orderBy('tipo')->get()],
        };
        $p = DB::query()->fromSub($consulta, 'filas')->orderBy($f['vista'] === 'stock' ? 'folio' : 'fecha', $f['vista'] === 'stock' ? 'asc' : 'desc')
            ->orderBy('id')->paginate(25, ['*'], 'page', $f['page'] ?? 1);

        return ['data' => $p->items(), 'resumen' => $resumen,
            'meta' => ['pagina' => $p->currentPage(), 'paginas' => $p->lastPage(), 'total' => $p->total()],
            'filtros' => $f, 'consultado_at' => now()->toIso8601String(), 'zona_horaria' => config('app.operational_timezone')];
    }

    private function stock(array $f): Builder
    {
        $hoy = now(config('app.operational_timezone'))->toDateString();
        $bloqueada = '(fm.motivo_bloqueo IS NOT NULL OR f.estado_operacional = ? OR (fm.fecha_vencimiento IS NOT NULL AND fm.fecha_vencimiento < ?))';
        $q = DB::table('saldos_materiales_almacenes as s')->join('folios_materiales as fm', 'fm.folio_id', '=', 's.folio_id')
            ->join('folios as f', 'f.id', '=', 's.folio_id')->join('items_materiales as i', 'i.id', '=', 'fm.item_material_id')
            ->join('clientes_materiales as c', 'c.id', '=', 'i.cliente_material_id')
            ->join('destinos_materiales as a', 'a.id', '=', 's.almacen_material_id')
            ->where('f.temporada_id', $f['temporada_id'])->where('f.activo', true)->where('s.cantidad_actual', '>', 0)
            ->select('s.id', 's.folio_id', 'f.numero_folio as folio', 'i.codigo', 'i.nombre as item', 'c.nombre as cliente',
                'a.nombre as almacen', 'fm.unidad_medida as unidad', 's.cantidad_actual as actual', 's.cantidad_reservada as reservada',
                'fm.motivo_bloqueo', 'fm.fecha_vencimiento', 'f.estado_operacional', 's.version')
            ->selectRaw("CASE WHEN $bloqueada THEN s.cantidad_actual ELSE 0 END as bloqueada", ['bloqueado', $hoy])
            ->selectRaw("CASE WHEN $bloqueada THEN 0 WHEN s.cantidad_actual > s.cantidad_reservada THEN s.cantidad_actual - s.cantidad_reservada ELSE 0 END as disponible", ['bloqueado', $hoy]);
        $this->cliente($q, $f);
        $this->buscar($q, $f, ['f.numero_folio', 'i.codigo', 'i.nombre']);
        if (! empty($f['almacen_id'])) {
            $q->where('s.almacen_material_id', $f['almacen_id']);
        }

        return $q;
    }

    private function movimientos(array $f): Builder
    {
        $almacenes = $f['vista'] === 'almacenes';
        $q = DB::table($almacenes ? 'movimientos_almacenes_materiales as m' : 'movimientos_inventario_materiales as m')
            ->join('folios as f', 'f.id', '=', 'm.folio_id')->join('items_materiales as i', 'i.id', '=', 'm.item_material_id')
            ->join('clientes_materiales as c', 'c.id', '=', 'i.cliente_material_id')->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->where('f.temporada_id', $f['temporada_id'])->select('m.id', 'm.folio_id', 'f.numero_folio as folio',
                'i.codigo', 'i.nombre as item', 'i.unidad_medida as unidad', 'c.nombre as cliente', 'm.tipo', 'm.cantidad',
                'm.ocurrido_at as fecha', 'm.user_id', 'u.name as usuario', 'm.motivo', 'm.despacho_material_id', 'm.retiro_material_id');
        $q->leftJoin('retiros_materiales as rt', 'rt.id', '=', 'm.retiro_material_id')
            ->leftJoin('operaciones_retiro_materiales as op', 'op.id', '=', 'rt.operacion_retiro_material_id')
            ->leftJoin('users as au', 'au.id', '=', 'op.autorizado_por_user_id')
            ->leftJoin('users as rg', 'rg.id', '=', 'op.regularizado_por_user_id')
            ->addSelect('op.fecha_despacho', 'op.numero_documento', 'op.fecha_documento', 'op.provisional', 'op.registro_retroactivo', 'op.fecha_limite_regularizacion', 'op.autorizado_at', 'au.name as autorizado_por', 'op.motivo_autorizacion', 'op.regularizado_at', 'rg.name as regularizado_por', 'op.motivo_regularizacion');
        if ($almacenes) {
            $q->leftJoin('destinos_materiales as ao', 'ao.id', '=', 'm.almacen_origen_id')
                ->leftJoin('destinos_materiales as ad', 'ad.id', '=', 'm.almacen_destino_id')
                ->addSelect('ao.nombre as origen', 'ad.nombre as destino', 'm.documento_relacionado', 'm.operacion_id',
                    'm.saldo_origen_anterior', 'm.saldo_origen_resultante', 'm.saldo_destino_anterior', 'm.saldo_destino_resultante');
            if (! empty($f['almacen_id'])) {
                $q->where(fn (Builder $b) => $b->where('m.almacen_origen_id', $f['almacen_id'])->orWhere('m.almacen_destino_id', $f['almacen_id']));
            }
        } else {
            $q->addSelect('m.cantidad_anterior', 'm.cantidad_resultante');
        }
        $this->periodo($q, $f, 'm.ocurrido_at');
        $this->cliente($q, $f);
        $this->buscar($q, $f, ['f.numero_folio', 'i.codigo', 'i.nombre']);
        $this->actorYTipo($q, $f, 'm.user_id', 'm.tipo');

        return $q;
    }

    private function recepciones(array $f): Builder
    {
        $eventos = DB::table('eventos_recepciones_materiales as e')->join('recepciones_materiales as r', 'r.id', '=', 'e.recepcion_material_id')
            ->where('r.temporada_id', $f['temporada_id'])
            ->select('e.id', 'r.id as recepcion_id', 'r.cliente_id', 'r.temporada_id', 'r.numero_guia_despacho as guia',
                'e.tipo', 'e.user_id', 'e.ocurrido_at as fecha', 'e.observacion as motivo', 'r.estado as estado_actual')
            ->selectRaw("'evento' as fuente");
        $eliminaciones = DB::table('eliminaciones_recepciones_materiales as e')
            ->where('e.temporada_id', $f['temporada_id'])
            ->select('e.id', 'e.recepcion_material_id_original as recepcion_id', 'e.cliente_id', 'e.temporada_id', 'e.numero_guia_despacho as guia')
            ->selectRaw("'eliminada' as tipo")->addSelect('e.eliminado_por_user_id as user_id', 'e.eliminado_at as fecha', 'e.motivo')
            ->selectRaw("'eliminada' as estado_actual, 'eliminacion' as fuente");
        $this->periodo($eventos, $f, 'e.ocurrido_at');
        $this->periodo($eliminaciones, $f, 'e.eliminado_at');
        $q = DB::query()->fromSub($eventos->unionAll($eliminaciones), 'a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('clientes as c', 'c.id', '=', 'a.cliente_id')->where('a.temporada_id', $f['temporada_id'])
            ->select('a.*', 'u.name as usuario', 'c.nombre as cliente');
        if (! empty($f['cliente_id'])) {
            $q->where('a.cliente_id', $f['cliente_id']);
        }
        $this->periodo($q, $f, 'a.fecha');
        $this->buscar($q, $f, ['a.guia', 'a.recepcion_id']);
        $this->actorYTipo($q, $f, 'a.user_id', 'a.tipo');

        return $q;
    }

    private function diferencias(array $f): Builder
    {
        $q = DB::table('tomas_inventario_materiales_resultados as r')
            ->join('tomas_inventario_materiales_posiciones as p', 'p.id', '=', 'r.toma_posicion_id')
            ->join('tomas_inventario_materiales as t', 't.id', '=', 'p.toma_id')
            ->leftJoin('items_materiales as i', 'i.id', '=', 'r.item_material_id')
            ->leftJoin('clientes_materiales as c', 'c.id', '=', 'i.cliente_material_id')->leftJoin('users as u', 'u.id', '=', 'p.user_id')
            ->leftJoin('users as ap', 'ap.id', '=', 't.aprobada_por_user_id')
            ->where('t.temporada_id', $f['temporada_id'])->where('r.vigente', true)->where('r.tipo', '<>', 'coincide')
            ->select('r.id', 'r.numero_folio as folio', 'i.codigo', 'i.nombre as item', 'c.nombre as cliente', 'r.unidad_medida as unidad',
                'r.tipo', 'r.cantidad_esperada', 'r.cantidad_contada', 'r.diferencia', 'r.accion', 'r.motivo', 'r.movimiento_almacen_id',
                'p.contada_at as fecha', 'p.user_id', 'u.name as usuario', 'p.posicion_id', 't.id as toma_id', 't.estado',
                't.aprobada_at', 'ap.name as aprobado_por', 't.motivo_anulacion');
        $this->cliente($q, $f);
        $this->periodo($q, $f, 'p.contada_at');
        $this->buscar($q, $f, ['r.numero_folio', 'i.codigo', 'i.nombre']);
        $this->actorYTipo($q, $f, 'p.user_id', 'r.tipo');
        if (! empty($f['estado'])) {
            $q->where('t.estado', $f['estado']);
        }

        return $q;
    }

    public function evidencia(string $fuente, string $id): array
    {
        if ($fuente === 'evento') {
            $evento = DB::table('eventos_recepciones_materiales')->where('id', $id)->first();
            abort_unless($evento, 404);

            return ['recepcion_id' => $evento->recepcion_material_id, 'evento' => $this->eventoSeguro((array) $evento)];
        }
        $registro = DB::table('eliminaciones_recepciones_materiales')->where('id', $id)->first();
        abort_unless($registro, 404);
        $snapshot = json_decode($registro->snapshot, true) ?? [];
        $eventos = $snapshot['eventos'] ?? [];
        $detalles = $snapshot['detalles'] ?? [];
        $usuarios = DB::table('users')->whereIn('id', array_values(array_unique(array_filter(array_column($eventos, 'user_id')))))->pluck('name', 'id');

        return ['recepcion_id' => $registro->recepcion_material_id_original, 'motivo' => $registro->motivo,
            'estado_anterior' => data_get($snapshot, 'cabecera.estado'),
            'guia' => $registro->numero_guia_despacho,
            'detalles_total' => count($detalles),
            'detalles' => array_map(fn (array $d) => array_intersect_key($d, array_flip([
                'item_material_id', 'unidad_medida', 'cantidad_documental', 'cantidad_contada', 'cantidad_aceptada', 'cantidad_rechazada',
            ])), array_slice($detalles, 0, 100)),
            'eventos_total' => count($eventos),
            'eventos' => array_map(fn (array $e) => [...$this->eventoSeguro($e), 'usuario' => $usuarios[$e['user_id'] ?? ''] ?? null], array_slice($eventos, 0, 100))];
    }

    private function eventoSeguro(array $evento): array
    {
        $datos = is_string($evento['datos'] ?? null) ? json_decode($evento['datos'], true) : ($evento['datos'] ?? []);

        return ['tipo' => $evento['tipo'], 'fecha' => $evento['ocurrido_at'], 'user_id' => $evento['user_id'] ?? null,
            'motivo' => $evento['observacion'] ?? null, 'estado_anterior' => $datos['estado_anterior'] ?? null,
            'estado_resultante' => $datos['estado_resultante'] ?? null,
            'registro' => $this->registroSeguro($datos['registro'] ?? null),
            'registro_anterior' => $this->registroSeguro($datos['registro_anterior'] ?? null),
            'registro_resultante' => $this->registroSeguro($datos['registro_resultante'] ?? null),
            'documento_anterior' => $this->documentoSeguro($datos['antes'] ?? null),
            'documento_resultante' => $this->documentoSeguro($datos['despues'] ?? null),
            'fuera_plazo' => $datos['fuera_plazo'] ?? false,
            'folios_confirmados' => array_map(fn (array $folio) => array_intersect_key($folio, array_flip(['numero_folio', 'item_material_id', 'cantidad', 'bloqueado'])), array_slice($datos['folios'] ?? [], 0, 100)),
            'folios_confirmados_total' => count($datos['folios'] ?? []),
            'folios_anteriores' => array_slice($datos['folios_anteriores'] ?? [], 0, 100),
            'folios_resultantes' => array_slice($datos['folios_resultantes'] ?? [], 0, 100)];
    }

    private function registroSeguro(?array $registro): ?array
    {
        return $registro === null ? null : array_intersect_key($registro, array_flip([
            'provisional', 'retroactivo', 'fecha_recepcion', 'fecha_limite', 'motivo', 'estado',
            'autorizado_at', 'autorizado_por', 'motivo_autorizacion', 'regularizado_at', 'regularizado_por',
            'motivo_regularizacion', 'regularizada_fuera_plazo',
        ]));
    }

    private function documentoSeguro(?array $documento): ?array
    {
        if ($documento === null) {
            return null;
        }

        return [
            'numero_guia_despacho' => $documento['numero_guia_despacho'] ?? null,
            'fecha_documento' => $documento['fecha_documento'] ?? null,
            'detalles' => array_map(fn (array $detalle): array => array_intersect_key($detalle, array_flip(['id', 'item', 'unidad_medida', 'cantidad_documental'])), array_slice($documento['detalles'] ?? [], 0, 100)),
        ];
    }

    private function periodo(Builder $q, array $f, string $columna): void
    {
        $zona = config('app.operational_timezone');
        // Sumar la fecha en UTC evita arrastrar la 01:00 del cambio horario
        // chileno a la jornada siguiente cuando la medianoche no existe.
        $diaSiguiente = Carbon::parse($f['hasta'], 'UTC')->addDay()->toDateString();
        $q->where($columna, '>=', Carbon::parse($f['desde'], $zona)->startOfDay()->utc())
            ->where($columna, '<', Carbon::parse($diaSiguiente, $zona)->startOfDay()->utc());
    }

    private function cliente(Builder $q, array $f): void
    {
        if (! empty($f['cliente_id'])) {
            $q->where('c.cliente_id', $f['cliente_id']);
        }
    }

    private function actorYTipo(Builder $q, array $f, string $actor, string $tipo): void
    {
        if (! empty($f['user_id'])) {
            $q->where($actor, $f['user_id']);
        }
        if (! empty($f['tipo'])) {
            $q->where($tipo, $f['tipo']);
        }
    }

    private function buscar(Builder $q, array $f, array $columnas): void
    {
        if (! empty($f['busqueda'])) {
            $q->where(function (Builder $b) use ($f, $columnas) {
                $texto = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $f['busqueda']).'%';
                foreach ($columnas as $columna) {
                    // Las columnas son constantes del servicio; el texto siempre va enlazado.
                    $columnaSql = $b->getGrammar()->wrap($columna);
                    $b->orWhereRaw("$columnaSql LIKE ? ESCAPE '!'", [$texto]);
                }
            });
        }
    }
}

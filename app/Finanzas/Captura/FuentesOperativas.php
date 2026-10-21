<?php

namespace App\Finanzas\Captura;

use App\Finanzas\Comex\LectorComex;
use App\Finanzas\Dotacion\LectorRrhh;
use App\Finanzas\Models\Hecho;
use App\Finanzas\Prefactura\Montos;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/** Contratos explícitos de los módulos. Agregar un proceso no cambia el motor de tarifas. */
final class FuentesOperativas
{
    public function todas(): array
    {
        return array_map(fn (array $definicion): FuenteOperativa => new FuenteOperativa($definicion), [
            $this->entregasPacking(), $this->retornosPacking(), $this->transformaciones(),
            $this->inventarioMateriales(), $this->almacenesMateriales(), $this->hidrocooler(),
            $this->inspecciones(), $this->planificador(), $this->envases(), $this->comex(), $this->dotacion(),
        ]);
    }

    private function proceso(object $fila): string
    {
        return hash('sha256', implode('|', [$fila->lote_id, mb_strtoupper(trim($fila->numero_orden)), mb_strtoupper(trim($fila->linea_proceso)), mb_strtoupper(trim($fila->turno))]));
    }

    private function dimensionesPacking(): array
    {
        return ['proceso_id' => 'Proceso (lote, orden, línea y turno)', 'lote_id' => 'Lote MP', 'numero_orden' => 'Orden de packing', 'linea_proceso' => 'Línea', 'turno' => 'Turno'];
    }

    private function datosPacking(object $fila): array
    {
        return ['proceso_id' => $this->proceso($fila), 'lote_id' => $fila->lote_id, 'numero_orden' => $fila->numero_orden, 'linea_proceso' => $fila->linea_proceso, 'turno' => $fila->turno];
    }

    private function entregasPacking(): array
    {
        return ['clave' => 'packing_entrega', 'nombre' => 'Entregas a packing', 'area' => 'packing',
            'descripcion' => 'Entregas vigentes por proceso de origen. Puede combinarlas con retornos del mismo proceso.',
            'id' => 'e.id', 'cambios' => ['e.updated_at', 'l.updated_at'], 'dimensiones' => $this->dimensionesPacking(),
            'medidas' => ['entregas' => ['nombre' => 'Entregas', 'unidad' => 'entrega'], 'kilos' => ['nombre' => 'Kilos entregados', 'unidad' => 'kg'], 'envases' => ['nombre' => 'Envases entregados', 'unidad' => 'envase']],
            'consulta' => fn (ContextoCaptura $c) => $c->lector->tabla('entregas_fruta_proceso', 'e')->join('lotes_materia_prima as l', 'l.id', '=', 'e.lote_materia_prima_id')
                ->select(['e.id', 'e.created_at', 'e.entregado_at as ocurrido_at', 'e.anulado_at', 'l.anulado_at as lote_anulado', 'l.cliente_id', 'l.temporada_id', 'l.id as lote_id', 'e.numero_orden', 'e.linea_proceso', 'e.turno', 'e.kilos_enviados', 'e.cantidad_envases']),
            'hecho' => fn (ContextoCaptura $c, object $r) => $this->hecho($c, $r, $r->anulado_at === null && $r->lote_anulado === null, ['entregas' => 1, 'kilos' => $r->kilos_enviados, 'envases' => (int) $r->cantidad_envases], $this->datosPacking($r), $r->numero_orden),
        ];
    }

    private function retornosPacking(): array
    {
        return ['clave' => 'packing_retorno', 'nombre' => 'Retornos packing regularizados', 'area' => 'packing',
            'descripcion' => 'Kilos definitivos distribuidos por proceso y tipo de resultado. Cada origen se cuenta una vez; los bins pendientes requieren regularización.',
            'id' => 'o.id', 'cambios' => ['o.updated_at', 'b.updated_at', 'l.updated_at'], 'dimensiones' => [...$this->dimensionesPacking(), 'tipo_resultado' => 'Código del resultado', 'bin_id' => 'Bin de retorno'],
            'medidas' => ['origenes' => ['nombre' => 'Orígenes de retorno', 'unidad' => 'origen'], 'kilos' => ['nombre' => 'Kilos retornados definitivos', 'unidad' => 'kg']],
            'consulta' => fn (ContextoCaptura $c) => $c->lector->tabla('bin_retorno_packing_origenes', 'o')->join('bins_retorno_packing as b', 'b.id', '=', 'o.bin_retorno_packing_id')->join('lotes_materia_prima as l', 'l.id', '=', 'o.lote_materia_prima_id')->leftJoin('tipos_resultado_packing as t', 't.id', '=', 'b.tipo_resultado_packing_id')
                ->select(['o.id', 'o.created_at', 'b.regularizado_at as ocurrido_at', 'b.estado', 'l.anulado_at as lote_anulado', 'l.cliente_id', 'l.temporada_id', 'l.id as lote_id', 'o.numero_orden', 'o.linea_proceso', 'o.turno', 'o.kilos_aportados_definitivos', 'b.id as bin_id', 't.codigo as tipo_resultado']),
            'hecho' => fn (ContextoCaptura $c, object $r) => $this->hecho($c, $r, $r->estado === 'regularizado' && $r->lote_anulado === null && $r->kilos_aportados_definitivos !== null, ['origenes' => 1, 'kilos' => $r->kilos_aportados_definitivos ?? '0'], [...$this->datosPacking($r), 'tipo_resultado' => $r->tipo_resultado, 'bin_id' => $r->bin_id], $r->numero_orden),
        ];
    }

    private function transformaciones(): array
    {
        return ['clave' => 'material_transformacion', 'nombre' => 'Producción de recetas de materiales', 'area' => 'materiales',
            'descripcion' => 'Lotes cerrados con producción real y versión de receta. Para cobrar unidades, filtre también su unidad de medida.',
            'id' => 'l.id', 'cambios' => ['l.updated_at', 'o.updated_at'],
            'dimensiones' => ['receta_id' => 'Receta', 'version_receta_id' => 'Versión de receta', 'orden_id' => 'Orden de transformación', 'unidad_medida' => 'Unidad de salida', 'linea' => 'Línea', 'turno' => 'Turno'],
            'medidas' => ['lotes' => ['nombre' => 'Lotes producidos', 'unidad' => 'lote'], 'unidades' => ['nombre' => 'Cantidad real producida', 'unidad' => 'unidad de salida', 'dimension_unidad' => 'unidad_medida']],
            'consulta' => fn (ContextoCaptura $c) => $c->lector->tabla('lotes_transformacion_materiales', 'l')->join('ordenes_transformacion_materiales as o', 'o.id', '=', 'l.orden_transformacion_material_id')->join('versiones_recetas_materiales as v', 'v.id', '=', 'o.version_receta_material_id')->join('recetas_materiales as r', 'r.id', '=', 'v.receta_material_id')
                ->select(['l.id', 'l.created_at', 'l.cerrado_at as ocurrido_at', 'l.estado', 'o.cancelado_at', 'o.cliente_id', 'o.temporada_id', 'v.receta_material_id as receta_id', 'r.nombre as receta_nombre', 'v.id as version_receta_id', 'v.numero_version', 'v.unidad_medida_salida as unidad_medida', 'o.id as orden_id', 'o.linea', 'o.turno', 'l.numero_lote', 'l.cantidad_real_salida']),
            'hecho' => fn (ContextoCaptura $c, object $r) => $this->hecho($c, $r, $r->estado === 'cerrado' && $r->cancelado_at === null, ['lotes' => 1, 'unidades' => $r->cantidad_real_salida ?? '0'], ['receta_id' => $r->receta_id, 'receta_nombre' => $r->receta_nombre, 'version_receta_id' => $r->version_receta_id, 'numero_version' => $r->numero_version, 'orden_id' => $r->orden_id, 'unidad_medida' => $r->unidad_medida, 'linea' => $r->linea, 'turno' => $r->turno], 'Lote '.$r->numero_lote.' · '.substr($r->orden_id, 0, 8)),
        ];
    }

    private function inventarioMateriales(): array
    {
        return ['clave' => 'material_inventario', 'nombre' => 'Movimientos de inventario de materiales', 'area' => 'materiales',
            'descripcion' => 'Kardex: ingresos, retiros, devoluciones, transformaciones y transferencias. Filtre el tipo y la unidad del servicio que ofrece.',
            'id' => 'm.id', 'cambios' => ['m.updated_at', 'cm.updated_at'],
            'dimensiones' => ['tipo' => 'Tipo de movimiento', 'item_id' => 'Ítem', 'folio_id' => 'Folio', 'unidad_medida' => 'Unidad de medida', 'ceco' => 'Centro de costo'],
            'medidas' => ['movimientos' => ['nombre' => 'Movimientos', 'unidad' => 'movimiento'], 'unidades' => ['nombre' => 'Cantidad del movimiento', 'unidad' => 'unidad material', 'dimension_unidad' => 'unidad_medida']],
            'consulta' => fn (ContextoCaptura $c) => $c->lector->tabla('movimientos_inventario_materiales', 'm')->join('items_materiales as i', 'i.id', '=', 'm.item_material_id')->join('clientes_materiales as cm', 'cm.id', '=', 'i.cliente_material_id')->join('folios as f', 'f.id', '=', 'm.folio_id')->join('folios_materiales as fm', 'fm.folio_id', '=', 'm.folio_id')
                ->select(['m.id', 'm.created_at', 'm.ocurrido_at', 'cm.cliente_id', 'cm.nombre as cliente_nombre', 'f.temporada_id', 'm.tipo', 'm.item_material_id as item_id', 'm.folio_id', 'fm.unidad_medida', 'm.cantidad', 'm.destino_centro_costo as ceco', 'f.numero_folio']),
            'hecho' => fn (ContextoCaptura $c, object $r) => $this->hecho($c, $r, true, ['movimientos' => 1, 'unidades' => $r->cantidad], ['tipo' => $r->tipo, 'item_id' => $r->item_id, 'folio_id' => $r->folio_id, 'unidad_medida' => $r->unidad_medida, 'ceco' => $r->ceco], $r->numero_folio),
        ];
    }

    private function almacenesMateriales(): array
    {
        return ['clave' => 'material_almacen', 'nombre' => 'Operaciones de almacenes de materiales', 'area' => 'materiales',
            'descripcion' => 'Movimientos inmutables entre almacenes, con sus orígenes, destinos y centro de costo.',
            'id' => 'm.id', 'cambios' => ['m.created_at', 'cm.updated_at'],
            'dimensiones' => ['tipo' => 'Tipo de movimiento', 'item_id' => 'Ítem', 'folio_id' => 'Folio', 'almacen_origen_id' => 'Almacén de origen', 'almacen_destino_id' => 'Almacén de destino', 'ceco' => 'Centro de costo'],
            'medidas' => ['movimientos' => ['nombre' => 'Movimientos entre almacenes', 'unidad' => 'movimiento']],
            'consulta' => fn (ContextoCaptura $c) => $c->lector->tabla('movimientos_almacenes_materiales', 'm')->join('items_materiales as i', 'i.id', '=', 'm.item_material_id')->join('clientes_materiales as cm', 'cm.id', '=', 'i.cliente_material_id')->join('folios as f', 'f.id', '=', 'm.folio_id')
                ->select(['m.id', 'm.created_at', 'm.ocurrido_at', 'cm.cliente_id', 'cm.nombre as cliente_nombre', 'f.temporada_id', 'm.tipo', 'm.item_material_id as item_id', 'm.folio_id', 'm.almacen_origen_id', 'm.almacen_destino_id', 'm.centro_costo as ceco', 'f.numero_folio']),
            'hecho' => fn (ContextoCaptura $c, object $r) => $this->hecho($c, $r, true, ['movimientos' => 1], ['tipo' => $r->tipo, 'item_id' => $r->item_id, 'folio_id' => $r->folio_id, 'almacen_origen_id' => $r->almacen_origen_id, 'almacen_destino_id' => $r->almacen_destino_id, 'ceco' => $r->ceco], $r->numero_folio),
        ];
    }

    private function hidrocooler(): array
    {
        return ['clave' => 'hidrocooler_mp', 'nombre' => 'Hidroenfriado de materia prima', 'area' => 'recepcion',
            'descripcion' => 'Procesos completados, con las cantidades conservadas al iniciar el hidrocooler.',
            'id' => 'h.id', 'cambios' => ['h.updated_at', 'l.updated_at'], 'dimensiones' => ['lote_id' => 'Lote MP', 'equipo' => 'Equipo', 'turno' => 'Turno'],
            'medidas' => ['procesos' => ['nombre' => 'Procesos de hidroenfriado', 'unidad' => 'proceso'], 'kilos' => ['nombre' => 'Kilos hidroenfriados', 'unidad' => 'kg'], 'envases' => ['nombre' => 'Envases hidroenfriados', 'unidad' => 'envase']],
            'consulta' => fn (ContextoCaptura $c) => $c->lector->tabla('procesos_hidrocooler_materia_prima', 'h')->join('lotes_materia_prima as l', 'l.id', '=', 'h.lote_materia_prima_id')
                ->select(['h.id', 'h.created_at', 'h.termino_at as ocurrido_at', 'h.estado', 'l.anulado_at as lote_anulado', 'l.cliente_id', 'l.temporada_id', 'l.id as lote_id', 'h.equipo', 'h.turno', 'h.codigo', 'h.kilos_netos_snapshot', 'h.cantidad_envases_snapshot']),
            'hecho' => fn (ContextoCaptura $c, object $r) => $this->hecho($c, $r, $r->estado === 'completado' && $r->lote_anulado === null, ['procesos' => 1, 'kilos' => $r->kilos_netos_snapshot ?? '0', 'envases' => (int) $r->cantidad_envases_snapshot], ['lote_id' => $r->lote_id, 'equipo' => $r->equipo, 'turno' => $r->turno, 'cantidades_historicas_disponibles' => $r->kilos_netos_snapshot !== null], $r->codigo),
        ];
    }

    private function inspecciones(): array
    {
        return ['clave' => 'inspeccion_sag', 'nombre' => 'Inspecciones SAG finalizadas', 'area' => 'logistica',
            'descripcion' => 'Un servicio por lote de inspección finalizado. La preparación o reserva sin ejecución puede cobrarse por acuerdo.',
            'id' => 's.id', 'cambios' => ['s.updated_at'], 'dimensiones' => ['tipo' => 'Tipo de inspección', 'inspeccion_id' => 'Lote de inspección'],
            'medidas' => ['inspecciones' => ['nombre' => 'Lotes inspeccionados', 'unidad' => 'inspección']],
            'consulta' => fn (ContextoCaptura $c) => $c->lector->tabla('lotes_inspeccion_sag', 's')->select(['s.id', 's.id as inspeccion_id', 's.created_at', 's.finalizado_at as ocurrido_at', 's.cliente_id', 's.temporada_id', 's.estado', 's.tipo', 's.codigo']),
            'hecho' => fn (ContextoCaptura $c, object $r) => $this->hecho($c, $r, $r->estado === 'finalizado', ['inspecciones' => 1], ['tipo' => $r->tipo, 'inspeccion_id' => $r->id], $r->codigo),
        ];
    }

    private function planificador(): array
    {
        return ['clave' => 'planificador_paso', 'nombre' => 'Pasos ejecutados del planificador', 'area' => 'logistica',
            'descripcion' => 'Cada tarea completada, identificada por movimiento y paso. Un paso no equivale necesariamente a una maniobra completa.',
            'id' => 't.id', 'cambios' => ['t.updated_at'], 'dimensiones' => ['tipo_movimiento' => 'Tipo de movimiento', 'tipo_paso' => 'Paso de maniobra', 'folio_id' => 'Folio', 'plan_id' => 'Plan operacional'],
            'medidas' => ['pasos' => ['nombre' => 'Pasos completados', 'unidad' => 'paso']],
            'consulta' => fn (ContextoCaptura $c) => $c->lector->tabla('tareas_movimiento', 't')->select(['t.id', 't.created_at', 't.completada_at as ocurrido_at', 't.estado', 't.folio_id', 't.tipo_movimiento', 't.tipo_paso_maniobra as tipo_paso', 't.plan_operacional_id as plan_id']),
            'enriquecer' => fn (ContextoCaptura $c, Collection $filas) => $this->foliosMovimiento($c, $filas->pluck('folio_id')->all()),
            'hecho' => function (ContextoCaptura $c, object $r, array $folios) {
                $folio = $folios[$r->folio_id] ?? null;
                $r->cliente_id = $folio['cliente_id'] ?? null;
                $r->cliente_nombre = $folio['cliente_nombre'] ?? null;
                $r->temporada_id = $folio['temporada_id'] ?? null;

                return $this->hecho($c, $r, $r->estado === 'completada', ['pasos' => 1], ['tipo_movimiento' => $r->tipo_movimiento, 'tipo_paso' => $r->tipo_paso, 'folio_id' => $r->folio_id, 'plan_id' => $r->plan_id], $folio['numero'] ?? substr($r->id, 0, 8));
            },
        ];
    }

    private function foliosMovimiento(ContextoCaptura $c, array $ids): array
    {
        $resultado = [];
        foreach (FolioLeido::leer($c, $ids) as $id => $folio) {
            $resultado[$id] = ['cliente_id' => $folio->clienteId, 'cliente_nombre' => $folio->clienteNombre, 'temporada_id' => $folio->temporadaId, 'numero' => $folio->numero];
        }
        foreach ($c->lector->tabla('folios_materiales', 'fm')->join('folios as f', 'f.id', '=', 'fm.folio_id')->join('items_materiales as i', 'i.id', '=', 'fm.item_material_id')->join('clientes_materiales as cm', 'cm.id', '=', 'i.cliente_material_id')->whereIn('fm.folio_id', $ids)->get(['fm.folio_id', 'cm.cliente_id', 'cm.nombre', 'f.temporada_id', 'f.numero_folio']) as $folio) {
            $resultado[$folio->folio_id] = ['cliente_id' => $folio->cliente_id, 'cliente_nombre' => $folio->nombre, 'temporada_id' => $folio->temporada_id, 'numero' => $folio->numero_folio];
        }

        return $resultado;
    }

    private function envases(): array
    {
        return ['clave' => 'envases_movimiento', 'nombre' => 'Movimientos de cuenta de envases', 'area' => 'envases',
            'descripcion' => 'Movimientos y efectos con signo, diferenciados por cliente, tipo de envase y propiedad.',
            'id' => 'm.id', 'cambios' => ['m.updated_at'], 'dimensiones' => ['tipo' => 'Tipo de movimiento', 'tipo_envase' => 'Tipo de envase', 'propiedad' => 'Propiedad', 'documento_tipo' => 'Tipo de documento'],
            'medidas' => ['movimientos' => ['nombre' => 'Movimientos', 'unidad' => 'movimiento'], 'envases' => ['nombre' => 'Envases del movimiento', 'unidad' => 'envase'], 'efecto_cuenta' => ['nombre' => 'Efecto firmado en la cuenta', 'unidad' => 'envase']],
            'consulta' => fn (ContextoCaptura $c) => $c->lector->tabla('movimientos_envases', 'm')->select(['m.id', 'm.created_at', 'm.ocurrido_at', 'm.cliente_id', 'm.temporada_id', 'm.tipo_movimiento as tipo', 'm.tipo_envase', 'm.propiedad', 'm.documento_tipo', 'm.numero_documento', 'm.cantidad', 'm.signo_cuenta']),
            'hecho' => fn (ContextoCaptura $c, object $r) => $this->hecho($c, $r, true, ['movimientos' => 1, 'envases' => (int) $r->cantidad, 'efecto_cuenta' => (int) $r->cantidad * (int) $r->signo_cuenta], ['tipo' => $r->tipo, 'tipo_envase' => $r->tipo_envase, 'propiedad' => $r->propiedad, 'documento_tipo' => $r->documento_tipo], $r->numero_documento ?? substr($r->id, 0, 8)),
        ];
    }

    private function comex(): array
    {
        return ['clave' => 'comex_reserva', 'nombre' => 'Gestión de reservas COMEX', 'area' => 'comex',
            'descripcion' => 'Reservas confirmadas o cerradas: gestión comercial, independiente del embarque físico.',
            'id' => 'b.id', 'cambios' => ['b.updated_at'], 'dimensiones' => ['gestion' => 'Tipo de gestión', 'booking_id' => 'Booking'],
            'medidas' => ['reservas' => ['nombre' => 'Reservas gestionadas', 'unidad' => 'reserva']],
            'consulta' => fn (ContextoCaptura $c) => app(LectorComex::class)->hechosReservas(),
            'hecho' => fn (ContextoCaptura $c, object $r) => $this->hecho($c, $r, in_array($r->estado, ['confirmado', 'cerrado'], true), ['reservas' => 1], ['gestion' => $r->gestion, 'booking_id' => $r->id], $r->codigo),
        ];
    }

    private function dotacion(): array
    {
        return ['clave' => 'rrhh_programacion', 'nombre' => 'Dotación programada por cliente', 'area' => 'rrhh',
            'descripcion' => 'Horas y jornadas programadas sin datos personales. La programación no acredita asistencia; puede cobrarse como disponibilidad.',
            'id' => 'a.id', 'cambios' => ['a.updated_at'], 'dimensiones' => ['asignacion_id' => 'Asignación', 'cargo' => 'Cargo', 'area' => 'Área de dotación', 'ceco' => 'Centro de costo'],
            'medidas' => ['horas' => ['nombre' => 'Horas programadas', 'unidad' => 'hora'], 'jornadas' => ['nombre' => 'Jornadas programadas', 'unidad' => 'jornada']],
            'consulta' => fn (ContextoCaptura $c) => app(LectorRrhh::class)->hechosProgramacion(),
            'hecho' => function (ContextoCaptura $c, object $r) {
                $minutos = max(0, (int) ContextoCaptura::fecha($r->desde_at)->diffInMinutes(ContextoCaptura::fecha($r->hasta_at)) - (int) $r->descanso_programado_minutos);

                return $this->hecho($c, $r, $r->estado !== 'cancelada' && $minutos > 0, ['horas' => Montos::texto(BigDecimal::of($minutos)->dividedBy(60, 6, RoundingMode::HalfUp)), 'jornadas' => 1], ['asignacion_id' => $r->id, 'cargo' => $r->cargo, 'area' => $r->area, 'ceco' => $r->ceco, 'base_cobro' => 'disponibilidad', 'asistencia_acreditada' => false], 'Asignación '.substr($r->id, 0, 8));
            },
        ];
    }

    private function hecho(ContextoCaptura $contexto, object $fila, bool $vigente, array $medidas, array $datos, string $referencia): HechoCapturado
    {
        [$clienteId, $clienteNombre] = $contexto->cliente($fila->cliente_id ?? null, $fila->cliente_nombre ?? null);

        return new HechoCapturado(origenId: $fila->id, estado: $vigente ? Hecho::ESTADO_VIGENTE : Hecho::ESTADO_ANULADO,
            ocurridoAt: ContextoCaptura::fecha($fila->ocurrido_at ?? $fila->created_at), clienteId: $clienteId, clienteNombre: $clienteNombre,
            temporadaId: $fila->temporada_id ?? null, temporadaCodigo: $contexto->temporadaCodigo($fila->temporada_id ?? null),
            referencia: mb_substr($referencia, 0, 100), medidas: $medidas, datos: $datos);
    }
}

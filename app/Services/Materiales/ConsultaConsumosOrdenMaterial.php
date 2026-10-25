<?php

namespace App\Services\Materiales;

use App\Enums\EstadoLoteTransformacionMaterial;
use App\Enums\EstadoOrdenTransformacionMaterial;
use App\Enums\TipoMovimientoInventarioMaterial;
use App\Models\OrdenTransformacionMaterial;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Database\Eloquent\Builder;

class ConsultaConsumosOrdenMaterial
{
    public function consulta(array $filtros): Builder
    {
        $temporadaId = $filtros['temporada_id'] ?? app(ServicioTemporadaActiva::class)->buscar()?->id;

        return OrdenTransformacionMaterial::where('temporada_id', $temporadaId)
            ->when($filtros['desde'] ?? null, fn ($q, $v) => $q->where('fecha_operacional', '>=', $v))
            ->when($filtros['hasta'] ?? null, fn ($q, $v) => $q->where('fecha_operacional', '<=', $v))
            ->when($filtros['cliente_id'] ?? null, fn ($q, $v) => $q->where('cliente_id', $v))
            ->when($filtros['almacen_id'] ?? null, fn ($q, $v) => $q->where('almacen_origen_id', $v))
            ->when($filtros['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->when($filtros['turno'] ?? null, fn ($q, $v) => $q->where('turno', $v))
            ->when($filtros['linea'] ?? null, fn ($q, $v) => $q->where('linea', $v))
            ->when($filtros['proceso'] ?? null, fn ($q, $v) => $q->where('proceso', $v))
            ->when($filtros['operador'] ?? null, fn ($q, $v) => $q->where('operador_nombre', 'like', '%'.$v.'%'))
            ->when($filtros['q'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('id', 'like', '%'.$v.'%')->orWhere('referencia_ot', 'like', '%'.$v.'%')));
    }

    public function relaciones(bool $resumen = false): array
    {
        $relaciones = ['temporada', 'cliente', 'almacenOrigen', 'creadoPor', 'lotes', 'cuadraturas',
            'movimientosConsumo', 'imputacionesConsumo.movimiento'];
        if ($resumen) {
            $relaciones = array_values(array_diff($relaciones, ['lotes', 'movimientosConsumo']));
            $relaciones['lotes'] = fn ($q) => $q->where('estado', 'cerrado')
                ->select('orden_transformacion_material_id', 'estado')
                ->selectRaw('SUM(cantidad_real_salida) as cantidad_real_salida, SUM(merma_real) as merma_real')
                ->groupBy('orden_transformacion_material_id', 'estado');
            $relaciones['movimientosConsumo'] = fn ($q) => $q->select('orden_transformacion_material_id', 'item_material_id')
                ->selectRaw('SUM(CASE WHEN tipo = ? AND cantidad < 0 THEN -cantidad ELSE 0 END) as retirado_total', ['consumo_transformacion'])
                ->selectRaw('SUM(CASE WHEN tipo IN (?, ?) AND cantidad > 0 THEN cantidad ELSE 0 END) as devuelto_total', ['devolucion_transformacion', 'reversa_transformacion'])
                ->groupBy('orden_transformacion_material_id', 'item_material_id');
        }

        return $relaciones;
    }

    public function detalle(OrdenTransformacionMaterial $orden, bool $conRegistros = true): array
    {
        $orden->loadMissing($this->relaciones());
        if ($conRegistros) {
            $orden->loadMissing(['movimientosConsumo.usuario', 'imputacionesConsumo.movimiento.usuario']);
        }
        $componentes = collect(data_get($orden->snapshot_receta, 'componentes', []));
        $base = (float) data_get($orden->snapshot_receta, 'salida.cantidad_base', 0);
        $lotes = $orden->lotes->where('estado', EstadoLoteTransformacionMaterial::Cerrado);
        $salida = round($lotes->sum('cantidad_real_salida'), 3);
        $filas = [];
        foreach ($componentes as $componente) {
            $id = (string) $componente['item_id'];
            $unidad = (string) ($componente['unidad_medida'] ?? '');
            $filas[$id.'|'.$unidad] = [
                'item_id' => $id, 'codigo' => $componente['codigo'] ?? '', 'nombre' => $componente['nombre'] ?? '', 'unidad' => $unidad,
                'estandar_planificado' => $base > 0 ? round((float) $componente['cantidad_estandar'] * (float) $orden->cantidad_planificada_salida / $base, 3) : null,
                'esperado' => $base > 0 ? round((float) $componente['cantidad_estandar'] * $salida / $base, 3) : null,
                'tolerancia_porcentaje' => (float) ($componente['tolerancia_porcentaje'] ?? 0),
                'retirado' => 0.0, 'devuelto' => 0.0, 'compartido' => 0.0, 'merma_compartida' => 0.0,
                'merma_declarada' => null,
            ];
        }
        $registros = [];
        foreach ($orden->movimientosConsumo as $movimiento) {
            if ($movimiento->getAttribute('retirado_total') !== null) {
                $componente = $componentes->firstWhere('item_id', $movimiento->item_material_id);
                if ($componente) {
                    $clave = $movimiento->item_material_id.'|'.($componente['unidad_medida'] ?? '');
                    $filas[$clave]['retirado'] += (float) $movimiento->retirado_total;
                    $filas[$clave]['devuelto'] += (float) $movimiento->devuelto_total;
                }

                continue;
            }
            $tipo = $movimiento->tipo;
            $cantidad = (float) $movimiento->cantidad;
            $consumo = $tipo === TipoMovimientoInventarioMaterial::ConsumoTransformacion && $cantidad < 0;
            $devolucion = in_array($tipo, [TipoMovimientoInventarioMaterial::DevolucionTransformacion, TipoMovimientoInventarioMaterial::ReversaTransformacion], true) && $cantidad > 0;
            if (! $consumo && ! $devolucion) {
                continue; // Producción y sus espejos no son consumo de componentes.
            }
            $componente = $componentes->firstWhere('item_id', $movimiento->item_material_id);
            if (! $componente) {
                continue;
            }
            $clave = $movimiento->item_material_id.'|'.($componente['unidad_medida'] ?? '');
            $filas[$clave][$consumo ? 'retirado' : 'devuelto'] += abs($cantidad);
            if ($conRegistros) {
                $registros[] = ['id' => $movimiento->id, 'tipo' => $tipo->value, 'item_id' => $movimiento->item_material_id,
                    'cantidad' => $cantidad, 'unidad' => $componente['unidad_medida'] ?? '', 'folio_id' => $movimiento->folio_id,
                    'operador' => $orden->operador_nombre, 'registrado_por' => $movimiento->usuario?->name,
                    'registrado_por_id' => $movimiento->user_id, 'fecha_registro' => $movimiento->ocurrido_at?->toAtomString(), 'motivo' => $movimiento->motivo];
            }
        }
        foreach ($orden->imputacionesConsumo as $imputacion) {
            $s = $imputacion->snapshot;
            $clave = $s['item_id'].'|'.$s['unidad'];
            $filas[$clave] ??= ['item_id' => $s['item_id'], 'codigo' => $s['codigo'], 'nombre' => $s['nombre'], 'unidad' => $s['unidad'],
                'estandar_planificado' => null, 'esperado' => null, 'tolerancia_porcentaje' => null,
                'retirado' => 0.0, 'devuelto' => 0.0, 'compartido' => 0.0, 'merma_compartida' => 0.0, 'merma_declarada' => null];
            $filas[$clave]['compartido'] += (float) $imputacion->cantidad;
            $filas[$clave]['merma_compartida'] += (float) $imputacion->cantidad_merma;
            if ($conRegistros) {
                $registros[] = ['id' => $imputacion->id, 'movimiento_id' => $imputacion->movimiento_almacen_id, 'tipo' => 'consumo_compartido',
                    'item_id' => $s['item_id'], 'cantidad' => -(float) $imputacion->cantidad, 'merma' => (float) $imputacion->cantidad_merma,
                    'unidad' => $s['unidad'], 'folio' => $s['folio'], 'operador' => $s['operador'],
                    'registrado_por' => $s['registrado_por'], 'registrado_por_id' => $s['registrado_por_id'],
                    'fecha_registro' => $imputacion->movimiento->ocurrido_at?->toAtomString(), 'motivo' => $imputacion->movimiento->motivo];
            }
        }
        $pendiente = $orden->seCuadraAlCierre() && ! in_array($orden->estado, [EstadoOrdenTransformacionMaterial::Cerrada, EstadoOrdenTransformacionMaterial::Cancelada], true);
        foreach ($filas as &$fila) {
            $cuadratura = $orden->cuadraturas->firstWhere('item_material_id', $fila['item_id']);
            foreach (['retirado', 'devuelto', 'compartido', 'merma_compartida'] as $campo) {
                $fila[$campo] = round($fila[$campo], 3);
            }
            $fila['consumo_neto'] = round($fila['retirado'] - $fila['devuelto'] + $fila['compartido'], 3);
            $fila['merma_declarada'] = $cuadratura ? round((float) $cuadratura->cantidad_merma + $fila['merma_compartida'], 3) : ($fila['compartido'] > 0 && $fila['retirado'] == 0 ? $fila['merma_compartida'] : null);
            $fila['utilizado'] = $pendiente && $fila['retirado'] > $fila['devuelto'] ? null
                : round($fila['consumo_neto'] - (float) ($cuadratura?->cantidad_merma ?? 0) - $fila['merma_compartida'], 3);
            $fila['desviacion'] = $fila['utilizado'] !== null && $fila['esperado'] !== null ? round($fila['utilizado'] - $fila['esperado'], 3) : null;
            $fila['desviacion_neta'] = $fila['utilizado'] !== null && $fila['esperado'] !== null ? round($fila['consumo_neto'] - $fila['esperado'], 3) : null;
            $fila['desviacion_neta_pct'] = $fila['desviacion_neta'] !== null && $fila['esperado'] > 0 ? round($fila['desviacion_neta'] / $fila['esperado'] * 100, 3) : null;
            $fila['desviacion_pct'] = $fila['desviacion'] !== null && $fila['esperado'] > 0 ? round($fila['desviacion'] / $fila['esperado'] * 100, 3) : null;
            $limite = $fila['esperado'] !== null ? round($fila['esperado'] * (float) $fila['tolerancia_porcentaje'] / 100, 3) : null;
            $fila['estado'] = $fila['utilizado'] === null ? 'pendiente_cuadratura' : ($fila['esperado'] === null ? 'sin_estandar'
                : (abs($fila['desviacion']) > $limite + 0.0005 ? 'fuera_tolerancia' : 'dentro_tolerancia'));
        }
        unset($fila);

        return ['id' => $orden->id, 'codigo' => 'OT-'.strtoupper(substr($orden->id, 0, 8)), 'referencia_ot' => $orden->referencia_ot,
            'temporada_id' => $orden->temporada_id, 'temporada' => $orden->temporada?->nombre,
            'cliente' => $orden->cliente?->nombre, 'bodega' => $orden->almacenOrigen?->nombre,
            'proceso' => $orden->proceso, 'linea' => $orden->linea, 'turno' => $orden->turno,
            'fecha_operacional' => $orden->fecha_operacional?->toDateString(), 'operador' => $orden->operador_nombre,
            'creada_por' => $orden->creadoPor?->name, 'estado' => $orden->estado->value, 'modo_operacion' => $orden->modo_operacion->value,
            'salida_real' => $salida, 'unidad_salida' => data_get($orden->snapshot_receta, 'salida.unidad_medida'),
            // La merma de producción está en la unidad de salida; no se suma a las unidades de entrada.
            'merma_produccion' => $orden->seCuadraAlCierre() ? null : round($lotes->sum('merma_real'), 3),
            'componentes' => array_values($filas), 'registros' => $registros];
    }
}

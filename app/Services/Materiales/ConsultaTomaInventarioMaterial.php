<?php

namespace App\Services\Materiales;

use App\Models\Camara;
use App\Models\TomaInventarioMaterial;
use App\Models\TomaInventarioMaterialResultado;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConsultaTomaInventarioMaterial
{
    public function detalle(TomaInventarioMaterial $toma, array $filtros = []): array
    {
        $resultados = TomaInventarioMaterialResultado::with(['item', 'tarea.posicion.camara'])->where('vigente', true)
            ->whereHas('tarea', fn ($q) => $q->where('toma_id', $toma->id))->orderBy('numero_folio')->get();
        $posiciones = $toma->posiciones()->with('posicion.camara')->get();
        $usuarios = User::whereIn('id', $posiciones->pluck('user_id')->merge([$toma->abierta_por_user_id, $toma->revisada_por_user_id, $toma->aprobada_por_user_id])->filter())->pluck('name', 'id');
        $filas = $resultados->map(function ($r) {
            $posicion = $r->tarea->posicion;

            return ['id' => $r->id, 'folio_id' => $r->folio_id, 'folio' => $r->numero_folio,
                'item_id' => $r->item_material_id, 'item' => $r->item?->nombre ?? 'Sin identificar', 'categoria' => $r->item?->categoria ?? 'Sin identificar',
                'unidad' => $r->unidad_medida, 'camara' => $posicion->camara->codigo, 'posicion' => "B{$posicion->banda} P{$posicion->posicion} N{$posicion->nivel}",
                'posicion_id' => $posicion->id, 'cantidad_inicial' => (float) $r->cantidad_inicial,
                'variacion_operativa' => round((float) $r->cantidad_esperada - (float) $r->cantidad_inicial, 3),
                'esperado' => (float) $r->cantidad_esperada, 'contado' => (float) $r->cantidad_contada, 'diferencia' => (float) $r->diferencia,
                'diferencia_absoluta' => abs((float) $r->diferencia), 'diferencia_pct' => $r->diferencia_pct === null ? null : (float) $r->diferencia_pct,
                'tipo' => $r->tipo, 'accion' => $r->accion, 'motivo' => $r->motivo, 'ajuste_id' => $r->movimiento_almacen_id,
                'movimientos' => $r->movimientos, 'posicion_destino_id' => $r->posicion_destino_id];
        });
        $porFolio = $filas->groupBy(fn ($f) => $f['folio_id'] ?? 'numero:'.$f['folio']);
        $total = $porFolio->count();
        $cantidadCorrecta = $porFolio->filter(fn ($g) => $g->every(fn ($f) => $f['tipo'] === 'coincide'))->count();
        $presenciaCorrecta = $porFolio->filter(fn ($g) => $g->every(fn ($f) => in_array($f['tipo'], ['coincide', 'diferencia_cantidad'], true)))->count();
        $totales = fn ($grupo) => $grupo->map(fn ($g) => ['categoria' => $g->first()['categoria'], 'item' => $g->first()['item'], 'unidad' => $g->first()['unidad'], 'folios' => $g->count(), 'esperado' => $g->sum('esperado'), 'contado' => $g->sum('contado'), 'diferencia' => $g->sum('diferencia')])->values()->all();

        // No suma unidades incompatibles: categoría se desglosa por unidad.
        return ['id' => $toma->id, 'estado' => $toma->estado, 'version' => $toma->version,
            'categoria' => $toma->categoria, 'camaras' => Camara::whereIn('id', $toma->camara_ids)->pluck('codigo')->all(),
            'abierta_at' => $toma->abierta_at?->toAtomString(), 'revisada_at' => $toma->revisada_at?->toAtomString(), 'aprobada_at' => $toma->aprobada_at?->toAtomString(),
            'abierta_por' => $usuarios[$toma->abierta_por_user_id] ?? null, 'revisada_por' => $usuarios[$toma->revisada_por_user_id] ?? null, 'aprobada_por' => $usuarios[$toma->aprobada_por_user_id] ?? null,
            'posiciones' => $posiciones->map(fn ($p) => ['id' => $p->id, 'posicion_id' => $p->posicion_id, 'estado' => $p->estado, 'camarero' => $usuarios[$p->user_id] ?? null, 'camara' => $p->posicion->camara->codigo, 'posicion' => "B{$p->posicion->banda} P{$p->posicion->posicion}", 'contada_at' => $p->contada_at?->toAtomString()])->all(),
            'exactitud_presencia_pct' => $total ? round($presenciaCorrecta / $total * 100, 2) : null,
            'exactitud_cantidad_pct' => $total ? round($cantidadCorrecta / $total * 100, 2) : null,
            'totales_items' => $totales($filas->groupBy('item_id')),
            'totales_categorias' => $totales($filas->groupBy(fn ($f) => $f['categoria'].'|'.$f['unidad'])),
            'diferencias' => $filas->filter(fn ($r) => (! isset($filtros['tipo']) || $r['tipo'] === $filtros['tipo'])
                && (! isset($filtros['item_id']) || $r['item_id'] === $filtros['item_id'])
                && (! isset($filtros['categoria']) || $r['categoria'] === $filtros['categoria']))->values()->all(),
            'reubicaciones' => DB::table('reubicaciones_toma_inventario_materiales')->whereIn('resultado_id', $resultados->pluck('id'))->get()->all()];
    }

    public function ciego(TomaInventarioMaterial $toma, User $user): array
    {
        // Lista explícita. No se serializan foto, saldos, resultados ni lecturas.
        $posiciones = $toma->posiciones()->where('user_id', $user->id)->with('posicion.camara')->get();

        return ['id' => $toma->id, 'contenido' => 'materiales', 'categoria' => $toma->categoria, 'verificar_cantidad' => true, 'estado' => $toma->estado === 'en_conteo' ? 'pendiente' : 'completada',
            'objetivo' => $posiciones->count(), 'completadas' => $posiciones->where('estado', 'contada')->count(),
            'items' => $posiciones->map(fn ($p) => ['id' => $p->id, 'version' => $p->version, 'resultado' => $p->estado === 'contada' ? 'coincide' : null,
                'posicion' => ['camara' => $p->posicion->camara->codigo, 'banda' => $p->posicion->banda, 'posicion' => $p->posicion->posicion, 'nivel' => $p->posicion->nivel]])->all()];
    }
}

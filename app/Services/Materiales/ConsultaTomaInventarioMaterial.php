<?php

namespace App\Services\Materiales;

use App\Models\AlmacenMaterial;
use App\Models\Camara;
use App\Models\Temporada;
use App\Models\TomaInventarioMaterial;
use App\Models\TomaInventarioMaterialResultado;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConsultaTomaInventarioMaterial
{
    public function detalle(TomaInventarioMaterial $toma, array $filtros = []): array
    {
        if ($toma->cierre_snapshot) {
            return $this->filtrar($toma->cierre_snapshot, $filtros);
        }
        $resultados = TomaInventarioMaterialResultado::with(['item.cliente', 'tarea.posicion.camara'])->where('vigente', true)
            ->whereHas('tarea', fn ($q) => $q->where('toma_id', $toma->id))->orderBy('numero_folio')->get();
        $posiciones = $toma->posiciones()->with('posicion.camara')->get()->sortBy([['posicion.camara_id', 'asc'], ['posicion.banda', 'asc'], ['posicion.posicion', 'asc'], ['posicion.nivel', 'asc']])->values();
        $usuarios = User::whereIn('id', $posiciones->pluck('user_id')->merge([$toma->abierta_por_user_id, $toma->revisada_por_user_id, $toma->aprobada_por_user_id])->merge($resultados->pluck('decidida_por_user_id'))->filter())->pluck('name', 'id');
        $almacenes = AlmacenMaterial::whereIn('id', $resultados->map(fn ($r) => $r->saldo_confirmado['almacen_id'] ?? null)->filter())->pluck('nombre', 'id');
        $filas = $resultados->map(function ($r) use ($usuarios, $almacenes) {
            $posicion = $r->tarea->posicion;

            return ['id' => $r->id, 'folio_id' => $r->folio_id, 'folio' => $r->numero_folio,
                'cliente_id' => $r->item?->cliente_material_id, 'cliente' => $r->item?->cliente?->nombre ?? 'Sin identificar',
                'almacen_id' => $r->saldo_confirmado['almacen_id'] ?? null, 'bodega' => $almacenes[$r->saldo_confirmado['almacen_id'] ?? ''] ?? 'Sin identificar',
                'clasificacion' => $r->clasificacion, 'decidida_por' => $usuarios[$r->decidida_por_user_id] ?? null, 'decidida_at' => $r->decidida_at?->toAtomString(),
                'contada_por' => $usuarios[$r->tarea->user_id] ?? null, 'contada_at' => $r->tarea->contada_at?->toAtomString(),
                'ajuste_aplicado' => (float) ($r->aplicacion['delta'] ?? 0), 'saldo_antes' => $r->aplicacion['saldo_antes'] ?? null, 'saldo_despues' => $r->aplicacion['saldo_despues'] ?? null,
                'item_id' => $r->item_material_id, 'item' => $r->item?->nombre ?? 'Sin identificar', 'categoria' => $r->item?->categoria ?? 'Sin identificar',
                'unidad' => $r->unidad_medida, 'camara' => $posicion->camara->codigo, 'posicion' => "B{$posicion->banda} P{$posicion->posicion} N{$posicion->nivel}",
                'posicion_id' => $posicion->id, 'cantidad_inicial' => (float) $r->cantidad_inicial,
                'variacion_operativa' => round((float) $r->cantidad_esperada - (float) $r->cantidad_inicial, 3),
                'esperado' => (float) $r->cantidad_esperada, 'contado' => (float) $r->cantidad_contada, 'diferencia' => (float) $r->diferencia,
                'diferencia_absoluta' => abs((float) $r->diferencia), 'diferencia_pct' => $r->diferencia_pct === null ? null : (float) $r->diferencia_pct,
                'cantidad_saldo_al_contar' => isset($r->saldo_confirmado['cantidad']) ? (float) $r->saldo_confirmado['cantidad'] : null,
                'diferencia_reubicacion' => $r->tipo === 'sobrante' && isset($r->saldo_confirmado['cantidad'])
                    ? round((float) $r->cantidad_contada - (float) $r->saldo_confirmado['cantidad'], 3) : null,
                'tipo' => $r->tipo, 'accion' => $r->accion, 'motivo' => $r->motivo, 'ajuste_id' => $r->movimiento_almacen_id,
                'movimientos' => $r->movimientos, 'posicion_destino_id' => $r->posicion_destino_id];
        });
        $porFolio = $filas->groupBy(fn ($f) => $f['folio_id'] ?? 'numero:'.$f['folio']);
        $total = $porFolio->count();
        $cantidadCorrecta = $porFolio->filter(fn ($g) => $g->every(fn ($f) => $f['tipo'] === 'coincide'))->count();
        $presenciaCorrecta = $porFolio->filter(fn ($g) => $g->every(fn ($f) => in_array($f['tipo'], ['coincide', 'diferencia_cantidad'], true)))->count();
        $totales = fn ($grupo) => $grupo->map(fn ($g) => ['categoria' => $g->first()['categoria'], 'item' => $g->first()['item'], 'unidad' => $g->first()['unidad'], 'folios' => $g->count(), 'esperado' => $g->sum('esperado'), 'contado' => $g->sum('contado'), 'diferencia' => $g->sum('diferencia')])->values()->all();

        // No suma unidades incompatibles: categoría se desglosa por unidad.
        $temporada = Temporada::find($toma->temporada_id);
        $almacen = $toma->almacen_id ? AlmacenMaterial::find($toma->almacen_id) : null;
        $datos = ['finalidad' => $toma->finalidad, 'temporada_id' => $toma->temporada_id, 'temporada' => $temporada?->nombre ?? $temporada?->codigo ?? $toma->temporada_id,
            'almacen_id' => $toma->almacen_id, 'bodega' => $almacen?->nombre, 'id' => $toma->id, 'estado' => $toma->estado, 'version' => $toma->version,
            'categoria' => $toma->categoria, 'camaras' => Camara::whereIn('id', $toma->camara_ids)->pluck('codigo')->all(),
            'abierta_at' => $toma->abierta_at?->toAtomString(), 'revisada_at' => $toma->revisada_at?->toAtomString(), 'aprobada_at' => $toma->aprobada_at?->toAtomString(),
            'abierta_por' => $usuarios[$toma->abierta_por_user_id] ?? null, 'revisada_por' => $usuarios[$toma->revisada_por_user_id] ?? null, 'aprobada_por' => $usuarios[$toma->aprobada_por_user_id] ?? null,
            'posiciones' => $posiciones->map(fn ($p) => ['id' => $p->id, 'posicion_id' => $p->posicion_id, 'estado' => $p->estado, 'camarero' => $usuarios[$p->user_id] ?? null, 'camara' => $p->posicion->camara->codigo, 'posicion' => "B{$p->posicion->banda} P{$p->posicion->posicion}", 'contada_at' => $p->contada_at?->toAtomString()])->all(),
            'exactitud_presencia_pct' => $total ? round($presenciaCorrecta / $total * 100, 2) : null,
            'exactitud_cantidad_pct' => $total ? round($cantidadCorrecta / $total * 100, 2) : null,
            'totales_items' => $totales($filas->groupBy(fn ($f) => $f['item_id'].'|'.$f['unidad'])),
            'totales_categorias' => $totales($filas->groupBy(fn ($f) => $f['categoria'].'|'.$f['unidad'])),
            'resumen_cierre' => $filas->groupBy(fn ($f) => implode('|', [$f['almacen_id'], $f['cliente_id'], $f['item_id'], $f['unidad']]))->map(fn ($g) => [
                'bodega' => $g->first()['bodega'], 'cliente' => $g->first()['cliente'], 'item' => $g->first()['item'], 'unidad' => $g->first()['unidad'],
                'esperado' => round($g->sum('esperado'), 3), 'contado' => round($g->sum('contado'), 3),
                'faltante_observado' => round($g->sum(fn ($f) => max(0, -$f['diferencia'])), 3),
                'sobrante_observado' => round($g->sum(fn ($f) => max(0, $f['diferencia'])), 3),
                'merma_aplicada' => max(0, round(-$g->where('clasificacion', 'merma')->sum('ajuste_aplicado'), 3)),
                'ajuste_aplicado' => round($g->sum('ajuste_aplicado'), 3),
            ])->values()->all(),
            'historial_decisiones' => TomaInventarioMaterialResultado::whereHas('tarea', fn ($q) => $q->where('toma_id', $toma->id))->orderBy('created_at')->orderBy('id')->get()
                ->flatMap(fn ($r) => collect($r->historial_decisiones ?? [])->map(fn ($d) => ['resultado_id' => $r->id, 'folio' => $r->numero_folio, 'vigente' => $r->vigente, ...$d]))->values()->all(),
            'diferencias' => $filas->all(),
            'reubicaciones' => DB::table('reubicaciones_toma_inventario_materiales')->whereIn('resultado_id', $resultados->pluck('id'))->get()->map(fn ($r) => (array) $r)->all()];

        return $this->filtrar($datos, $filtros);
    }

    private function filtrar(array $datos, array $filtros): array
    {
        $datos['diferencias'] = collect($datos['diferencias'])->filter(fn ($r) => collect(['tipo', 'item_id', 'categoria', 'clasificacion', 'cliente_id'])->every(fn ($clave) => empty($filtros[$clave]) || ($r[$clave] ?? null) === $filtros[$clave]))->values()->all();

        return $datos;
    }

    public function ciego(TomaInventarioMaterial $toma, User $user): array
    {
        // Lista explícita. No se serializan foto, saldos, resultados ni lecturas.
        $posiciones = $toma->posiciones()->where('user_id', $user->id)->with('posicion.camara')->get()->sortBy([['posicion.camara_id', 'asc'], ['posicion.banda', 'asc'], ['posicion.posicion', 'asc'], ['posicion.nivel', 'asc']])->values();

        return ['id' => $toma->id, 'contenido' => 'materiales', 'categoria' => $toma->categoria, 'verificar_cantidad' => true, 'estado' => $toma->estado === 'en_conteo' ? 'pendiente' : 'completada',
            'objetivo' => $posiciones->count(), 'completadas' => $posiciones->where('estado', 'contada')->count(),
            'items' => $posiciones->map(fn ($p) => ['id' => $p->id, 'version' => $p->version, 'resultado' => $p->estado === 'contada' ? 'coincide' : null,
                'posicion' => ['camara' => $p->posicion->camara->codigo, 'banda' => $p->posicion->banda, 'posicion' => $p->posicion->posicion, 'nivel' => $p->posicion->nivel]])->all()];
    }
}

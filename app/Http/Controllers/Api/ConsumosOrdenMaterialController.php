<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoOrdenTransformacionMaterial;
use App\Http\Controllers\Controller;
use App\Models\AlmacenMaterial;
use App\Models\Cliente;
use App\Models\FolioMaterial;
use App\Models\OrdenTransformacionMaterial;
use App\Models\Temporada;
use App\Services\Existencias\GeneradorLibroXlsx;
use App\Services\Materiales\ConsultaConsumosOrdenMaterial;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ConsumosOrdenMaterialController extends Controller
{
    public function opciones(Request $r)
    {
        Gate::authorize('gestionar-transformaciones-materiales');
        $datos = $r->validate(['folio_id' => ['required', 'uuid', 'exists:folios_materiales,folio_id'], 'q' => ['nullable', 'string', 'max:100']]);
        $folio = FolioMaterial::with(['folio', 'item.cliente'])->findOrFail($datos['folio_id']);
        $ordenes = OrdenTransformacionMaterial::where('temporada_id', app(ServicioTemporadaActiva::class)->buscar()?->id)
            ->where('temporada_id', $folio->folio->temporada_id)->where('cliente_id', $folio->item->cliente->cliente_id)
            ->whereIn('estado', ['en_proceso', 'pendiente_cierre'])
            ->when($datos['q'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('referencia_ot', 'like', '%'.$v.'%')->orWhere('id', 'like', '%'.$v.'%')))
            ->orderByDesc('fecha_operacional')->orderByDesc('id')->limit(51)->get(['id', 'referencia_ot', 'proceso', 'turno', 'fecha_operacional']);

        return response()->json(['data' => $ordenes->take(50)->values(), 'mas_resultados' => $ordenes->count() > 50])->header('Cache-Control', 'no-store');
    }

    public function index(Request $r, ConsultaConsumosOrdenMaterial $consulta)
    {
        Gate::authorize('consultar-transformaciones-materiales');
        $filtros = $this->filtros($r);
        $pagina = $consulta->consulta($filtros)->with($consulta->relaciones(true))
            ->orderByDesc('fecha_operacional')->orderByDesc('id')->paginate(25);

        return response()->json(['data' => $pagina->getCollection()->map(fn ($o) => $consulta->detalle($o, false)),
            'meta' => ['page' => $pagina->currentPage(), 'last_page' => $pagina->lastPage(), 'total' => $pagina->total()],
            'temporadas' => Temporada::orderByDesc('fecha_inicio')->get(['id', 'nombre', 'activa']),
            'clientes' => Cliente::orderBy('nombre')->get(['id', 'nombre']),
            'bodegas' => AlmacenMaterial::where('tipo', 'fisica')->orderBy('nombre')->get(['id', 'nombre'])])->header('Cache-Control', 'no-store');
    }

    public function show(OrdenTransformacionMaterial $ordenTransformacionMaterial, ConsultaConsumosOrdenMaterial $consulta)
    {
        Gate::authorize('consultar-transformaciones-materiales');

        return response()->json(['data' => $consulta->detalle($ordenTransformacionMaterial)])->header('Cache-Control', 'no-store');
    }

    public function excel(Request $r, ConsultaConsumosOrdenMaterial $consulta, GeneradorLibroXlsx $excel)
    {
        Gate::authorize('consultar-transformaciones-materiales');
        $filtros = $this->filtros($r);
        $filas = function () use ($consulta, $filtros) {
            foreach ($consulta->consulta($filtros)->with($consulta->relaciones(true))->lazyById(100) as $orden) {
                $datos = $consulta->detalle($orden, false);
                foreach ($datos['componentes'] as $componente) {
                    yield array_merge($datos, $componente, ['orden' => $datos['codigo'], 'estado_orden' => $datos['estado']]);
                }
            }
        };
        $titulos = ['orden' => 'Orden', 'referencia_ot' => 'Referencia OT', 'temporada' => 'Temporada', 'fecha_operacional' => 'Fecha operacional',
            'cliente' => 'Cliente', 'bodega' => 'Bodega de componentes', 'proceso' => 'Proceso', 'linea' => 'Línea', 'turno' => 'Turno', 'operador' => 'Operador físico declarado',
            'creada_por' => 'Orden registrada por', 'estado_orden' => 'Estado orden', 'codigo' => 'Código ítem', 'nombre' => 'Ítem', 'unidad' => 'Unidad',
            'estandar_planificado' => 'Estándar para salida planificada', 'esperado' => 'Estándar para salida real', 'retirado' => 'Retirado para transformación',
            'devuelto' => 'Devuelto / reversado', 'compartido' => 'Consumo compartido imputado', 'consumo_neto' => 'Consumo neto', 'merma_declarada' => 'Merma declarada',
            'utilizado' => 'Utilizado', 'desviacion' => 'Desviación de utilizado', 'desviacion_pct' => 'Desviación utilizado %', 'desviacion_neta' => 'Desviación neta (incluye merma)', 'desviacion_neta_pct' => 'Desviación neta %', 'estado' => 'Evaluación'];
        $numeros = ['estandar_planificado', 'esperado', 'retirado', 'devuelto', 'compartido', 'consumo_neto', 'merma_declarada', 'utilizado', 'desviacion', 'desviacion_pct', 'desviacion_neta', 'desviacion_neta_pct'];
        $columnas = collect($titulos)->map(fn ($titulo, $clave) => ['clave' => $clave, 'titulo' => $titulo, 'tipo' => in_array($clave, $numeros) ? 'numero' : 'texto'])->values()->all();
        $ruta = $excel->generar('Consumos por orden de trabajo', $columnas, $filas(), [
            'usuario' => $r->user()->name, 'fecha_corte' => now()->toAtomString(),
            'adicionales' => collect($filtros)->except('page')->map(fn ($v, $k) => [$k, (string) $v])->values()->all(),
        ], 'Consumos OT');

        return response()->download($ruta, 'consumos-por-ot.xlsx')->deleteFileAfterSend();
    }

    private function filtros(Request $r): array
    {
        return $r->validate(['temporada_id' => ['nullable', 'uuid', 'exists:temporadas,id'], 'cliente_id' => ['nullable', 'uuid'], 'almacen_id' => ['nullable', 'uuid'],
            'desde' => ['nullable', 'date_format:Y-m-d'], 'hasta' => ['nullable', 'date_format:Y-m-d', ...($r->filled('desde') ? ['after_or_equal:desde'] : [])],
            'estado' => ['nullable', Rule::enum(EstadoOrdenTransformacionMaterial::class)], 'proceso' => ['nullable', 'string', 'max:100'],
            'linea' => ['nullable', 'string', 'max:100'], 'turno' => ['nullable', 'string', 'max:80'], 'operador' => ['nullable', 'string', 'max:150'],
            'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
    }
}

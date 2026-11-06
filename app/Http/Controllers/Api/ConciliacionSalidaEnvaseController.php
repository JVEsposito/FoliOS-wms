<?php

namespace App\Http\Controllers\Api;

use App\Enums\TipoEnvaseRomana;
use App\Enums\TipoMovimientoEnvase;
use App\Exceptions\ConflictoOperacion;
use App\Http\Controllers\Controller;
use App\Models\GuiaDespachoEnvase;
use App\Models\RecepcionRomana;
use App\Services\Envases\ServicioConciliacionSalidaEnvases;
use App\Services\Envases\ServicioGuiaEmitidaEnvases;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ConciliacionSalidaEnvaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('ajustar-cuenta-envases');
        $datos = $request->validate([
            'temporada_id' => ['required', 'uuid', 'exists:temporadas,id'],
            'cliente_id' => ['nullable', 'uuid', 'exists:clientes,id'],
            'buscar' => ['nullable', 'string', 'max:100'],
            'coincidencia' => ['nullable', Rule::in(['numero', 'manual'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $this->asegurarTemporadaActiva($datos['temporada_id']);
        $manual = ($datos['coincidencia'] ?? 'numero') === 'manual';
        if ($manual) {
            $request->validate(['cliente_id' => ['required'], 'buscar' => ['required', 'string', 'min:3']]);
        }
        $numero = app(ServicioGuiaEmitidaEnvases::class)->normalizar($datos['buscar'] ?? null);
        $consulta = DB::table('guias_despacho_envases as g')
            ->join('recepciones_romana as r', function ($join) use ($manual): void {
                $join->on('r.temporada_id', '=', 'g.temporada_id')->on('r.cliente_id', '=', 'g.cliente_id');
                if (! $manual) {
                    $join->on('r.documento_salida_normalizado', '=', 'g.documento_salida_normalizado');
                }
            })
            ->join('clientes as c', 'c.id', '=', 'g.cliente_id')
            ->where('g.temporada_id', $datos['temporada_id'])
            ->where('g.estado', 'confirmada')->where('r.estado', 'cerrado')
            ->whereNull('r.eliminada_at')->where('r.documento_salida_normalizado', '!=', '')
            ->where('g.documento_salida_normalizado', '!=', '')
            ->when($datos['cliente_id'] ?? null, fn ($q, $id) => $q->where('g.cliente_id', $id))
            // Coincidencia documental con al menos un envase/propiedad cargado en ambos flujos.
            // El servicio vuelve a comprobar integridad, cantidades y correcciones previas.
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('movimientos_envases as mr')
                ->join('movimientos_envases as md', fn ($join) => $join
                    ->on('md.tipo_envase', '=', 'mr.tipo_envase')->on('md.propiedad', '=', 'mr.propiedad'))
                ->whereColumn('mr.recepcion_romana_id', 'r.id')->whereColumn('md.documento_id', 'g.id')
                ->where('mr.tipo_movimiento', TipoMovimientoEnvase::SalidaRecepcionFruta->value)
                ->where('md.tipo_movimiento', TipoMovimientoEnvase::DespachoCliente->value)
                ->where('md.documento_tipo', 'guia_despacho_envases')
                ->where('mr.cantidad', '>', 0)->where('md.cantidad', '>', 0))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('movimientos_envases as conciliacion')
                ->whereColumn('conciliacion.documento_id', 'g.id')
                ->where('conciliacion.tipo_movimiento', TipoMovimientoEnvase::ConciliacionSalida->value))
            ->when($datos['buscar'] ?? null, fn ($q, $buscar) => $q->where(function ($q) use ($buscar, $numero, $manual): void {
                $columnas = ['g.numero', 'g.numero_guia_despacho', 'r.numero_recepcion', 'r.numero_guia_despacho', 'r.numero_guia_salida'];
                foreach ($manual ? $columnas : [...$columnas, 'c.nombre', 'c.codigo'] as $columna) {
                    $q->orWhere($columna, 'like', '%'.$buscar.'%');
                }
                if ($numero) {
                    $q->orWhere('g.documento_salida_normalizado', $numero)->orWhere('r.documento_salida_normalizado', $numero);
                }
            }))
            ->orderByDesc('r.salida_at')->orderBy('g.id')->orderBy('r.id')
            ->paginate(15, ['g.id as guia_id', 'g.numero as despacho', 'g.numero_guia_despacho as guia_salida',
                'r.id as recepcion_id', 'r.numero_recepcion as recepcion', 'r.numero_guia_despacho as guia_ingreso',
                'r.numero_guia_salida as guia_salida_romana',
                'c.nombre as cliente', 'r.salida_at'], 'page', $datos['page'] ?? 1);

        return response()->json(['data' => $consulta->items(), 'meta' => [
            'page' => $consulta->currentPage(), 'last_page' => $consulta->lastPage(), 'total' => $consulta->total(),
        ]])->header('Cache-Control', 'no-store, private');
    }

    public function previsualizar(Request $request, ServicioConciliacionSalidaEnvases $servicio): JsonResponse
    {
        Gate::authorize('ajustar-cuenta-envases');
        $datos = $this->validar($request);
        [$recepcion, $guia] = $this->documentos($datos);

        return $this->respuesta($recepcion, $guia, $servicio->previsualizar($recepcion, $guia, $datos['conservar'], $datos['guias_distintas_verificadas'] ?? false));
    }

    public function aplicar(Request $request, ServicioConciliacionSalidaEnvases $servicio): JsonResponse
    {
        Gate::authorize('ajustar-cuenta-envases');
        $datos = $this->validar($request, true);
        [$recepcion, $guia] = $this->documentos($datos);
        $resultado = $servicio->aplicar($recepcion, $guia, $datos['conservar'], $datos['hash'], $datos['motivo'], $request->user(), $datos['guias_distintas_verificadas'] ?? false);

        return $this->respuesta($recepcion, $guia, $resultado);
    }

    private function validar(Request $request, bool $aplicar = false): array
    {
        return $request->validate([
            'recepcion_id' => ['required', 'uuid', 'exists:recepciones_romana,id'],
            'guia_id' => ['required', 'uuid', 'exists:guias_despacho_envases,id'],
            'conservar' => ['required', Rule::in(['romana', 'despacho'])],
            'guias_distintas_verificadas' => ['sometimes', 'boolean'],
            ...($aplicar ? [
                'hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
                'motivo' => ['required', 'string', 'min:10', 'max:2000'],
                'cantidades_verificadas' => ['required', 'accepted'],
            ] : []),
        ]);
    }

    private function documentos(array $datos): array
    {
        $recepcion = RecepcionRomana::findOrFail($datos['recepcion_id']);
        $guia = GuiaDespachoEnvase::findOrFail($datos['guia_id']);
        $this->asegurarTemporadaActiva($guia->temporada_id);

        return [$recepcion, $guia];
    }

    private function asegurarTemporadaActiva(string $temporadaId): void
    {
        if ($temporadaId !== app(ServicioTemporadaActiva::class)->obtener()->id) {
            throw new ConflictoOperacion('Selecciona la temporada activa para conciliar salidas duplicadas.');
        }
    }

    private function respuesta(RecepcionRomana $recepcion, GuiaDespachoEnvase $guia, array $plan): JsonResponse
    {
        $propiedades = ['propia' => 'De la planta', 'arrendada' => 'Arrendada', 'cliente' => 'Del cliente'];
        $filas = array_map(function (array $fila) use ($propiedades): array {
            [$tipo, $propiedad] = explode('|', $fila['envase_propiedad']);

            return [...$fila, 'envase' => TipoEnvaseRomana::from($tipo)->etiqueta(), 'propiedad' => $propiedades[$propiedad],
                'compensacion' => $fila['romana'] + $fila['despacho'] - $fila['conservar']];
        }, $plan['filas']);
        $auditoria = $plan['aplicada'] ? $guia->conciliacionesSalida()->with('creadoPor:id,name')->get()
            ->unique(fn ($m) => $m->creado_por_user_id.'|'.($m->datos['hash_plan'] ?? ''))
            ->map(fn ($m) => ['responsable' => $m->creadoPor?->name, 'motivo' => $m->datos['motivo'],
                'conservar' => $m->datos['conservar'], 'fecha' => $m->ocurrido_at->toAtomString()])->values()->all() : [];

        return response()->json(['data' => [
            'aplicada' => $plan['aplicada'], 'hash' => $plan['hash'], 'filas' => $filas,
            'compensaciones' => count($plan['movimientos'] ?? []), 'auditoria' => $auditoria,
            'documentos' => ['cliente' => $guia->cliente->nombre, 'recepcion' => $recepcion->numero_recepcion,
                'guia_ingreso' => $recepcion->numero_guia_despacho, 'despacho' => $guia->numero, 'guia_salida' => $guia->numero_guia_despacho,
                'guia_salida_romana' => $recepcion->numero_guia_salida],
        ]])->header('Cache-Control', 'no-store, private');
    }
}

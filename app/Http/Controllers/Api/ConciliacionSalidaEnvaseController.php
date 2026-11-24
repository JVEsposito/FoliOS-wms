<?php

namespace App\Http\Controllers\Api;

use App\Enums\TipoEnvaseRomana;
use App\Exceptions\ConflictoOperacion;
use App\Http\Controllers\Controller;
use App\Models\GuiaDespachoEnvase;
use App\Models\RecepcionRomana;
use App\Services\Envases\ServicioConciliacionSalidaEnvases;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $consulta = app(ServicioConciliacionSalidaEnvases::class)
            ->pendientes($datos['temporada_id'], $datos['cliente_id'] ?? null, $datos['buscar'] ?? null, $manual)
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

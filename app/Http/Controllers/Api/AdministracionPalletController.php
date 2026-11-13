<?php

namespace App\Http\Controllers\Api;

use App\Enums\ContenidoCamara;
use App\Enums\DominioTransicionOperacional;
use App\Enums\EstadoTransicionOperacional;
use App\Enums\PrioridadCamara;
use App\Exceptions\ConflictoOperacion;
use App\Http\Controllers\Controller;
use App\Models\Camara;
use App\Models\Folio;
use App\Models\Posicion;
use App\Models\TransicionOperacional;
use App\Services\Planificador\ServicioAdministracionPallets;
use App\Services\Planificador\ServicioEstadoArbitrajePlanificador;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Transiciones\ComandoTransicionOperacional;
use App\Services\Transiciones\MotorTransicionesOperacionales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdministracionPalletController extends Controller
{
    public function consultar(Request $request, ServicioAdministracionPallets $servicio): JsonResponse
    {
        $datos = $request->validate(['folio' => ['required', 'string', 'max:100']]);
        $temporada = app(ServicioTemporadaActiva::class)->buscar() ?? abort(404, 'No existe una temporada activa.');
        $folio = Folio::where('temporada_id', $temporada->id)->where('numero_folio', trim($datos['folio']))->firstOrFail();

        return response()->json(['data' => $servicio->consultar($folio)])->header('Cache-Control', 'no-store, private');
    }

    public function intervenir(Request $request, Folio $folio, ServicioAdministracionPallets $servicio): JsonResponse
    {
        $datos = $request->validate([
            'accion' => ['required', Rule::in(['liberar_tareas', 'designar_destino', 'corregir_ubicacion'])],
            'operacion_id' => ['required', 'uuid'], 'revision' => ['required', 'string', 'size:64'],
            'motivo' => ['required', 'string', 'min:3', 'max:500', 'regex:/\S/u'],
            'confirmacion_fisica' => ['required_if:accion,corregir_ubicacion', 'boolean', 'accepted_if:accion,corregir_ubicacion'],
            'camara_id' => ['required_unless:accion,liberar_tareas', 'nullable', 'uuid'],
            'banda' => ['required_unless:accion,liberar_tareas', 'nullable', 'integer', 'min:1', 'max:40'],
            'posicion' => ['required_unless:accion,liberar_tareas', 'nullable', 'integer', 'min:1', 'max:40'],
            'nivel' => ['required_unless:accion,liberar_tareas', 'nullable', 'integer', 'min:1', 'max:10'],
            'version_plano_destino' => ['required_unless:accion,liberar_tareas', 'nullable', 'integer', 'min:0'],
        ]);
        if ($datos['accion'] !== 'liberar_tareas') {
            $datos['posicion_destino_id'] = Posicion::where('camara_id', $datos['camara_id'])->where('banda', $datos['banda'])
                ->where('posicion', $datos['posicion'])->where('nivel', $datos['nivel'])->firstOrFail()->id;
        }

        return response()->json(['data' => $servicio->intervenir($folio, $request->user(), $datos)]);
    }

    public function prioridad(Request $request, Camara $camara, MotorTransicionesOperacionales $motor): JsonResponse
    {
        $datos = $request->validate(['operacion_id' => ['required', 'uuid'], 'version_prioridad' => ['required', 'integer', 'min:1'],
            'prioridad_operacional' => ['required', Rule::enum(PrioridadCamara::class)], 'motivo' => ['required', 'string', 'min:3', 'max:500', 'regex:/\S/u']]);
        $resultado = $motor->ejecutar(new ComandoTransicionOperacional(
            dominio: DominioTransicionOperacional::Planificador, tipo: 'camara.prioridad_operacional_admin', usuario: $request->user(),
            payload: ['camara_id' => $camara->id, ...$datos], operacionId: $datos['operacion_id'], sujetoTipo: Camara::class, sujetoId: $camara->id,
            referencia: $camara->codigo,
        ), function () use ($camara, $datos, $request): array {
            $previa = TransicionOperacional::where('tipo', 'camara.prioridad_operacional_admin')->where('operacion_id', $datos['operacion_id'])->where('estado', EstadoTransicionOperacional::Aplicada)->first();
            if ($previa) {
                return $previa->resultado['datos'];
            }
            $actual = Camara::lockForUpdate()->findOrFail($camara->id);
            if ($actual->version_prioridad !== (int) $datos['version_prioridad']) {
                throw new ConflictoOperacion('La prioridad de esta cámara cambió. Actualiza antes de guardar.');
            }
            if ($actual->contenido !== ContenidoCamara::Productos && $datos['prioridad_operacional'] !== 'general') {
                throw new ConflictoOperacion('Las prioridades del planificador se configuran en cámaras de producto terminado.');
            }
            $anterior = $actual->prioridad_operacional?->value ?? 'general';
            $actual->update(['prioridad_operacional' => $datos['prioridad_operacional'], 'version_prioridad' => $actual->version_prioridad + 1,
                'actualizado_por_user_id' => $request->user()->id]);
            if ($temporada = app(ServicioTemporadaActiva::class)->buscar()) {
                app(ServicioEstadoArbitrajePlanificador::class)->solicitar($temporada->id, 'prioridad_camara_administrativa');
            }

            return ['camara_id' => $actual->id, 'anterior' => $anterior, 'prioridad_operacional' => $actual->prioridad_operacional->value, 'version_prioridad' => $actual->version_prioridad];
        });

        return response()->json(['data' => $resultado]);
    }
}

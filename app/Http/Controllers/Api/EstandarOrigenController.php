<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConexionExterna;
use App\Services\Integraciones\ServicioLecturaEstandaresOrigen;
use App\Services\Integraciones\ServicioOperacionesIntegracion;
use App\Services\Materiales\ServicioIncorporacionEstandaresOrigen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Estándares de planta: configuración de la vista, lectura en cola, vínculos e incorporación. */
class EstandarOrigenController extends Controller
{
    public function __construct(
        private readonly ServicioLecturaEstandaresOrigen $lector,
        private readonly ServicioIncorporacionEstandaresOrigen $incorporacion,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $config = $this->lector->configuracion();
        $lectura = $this->incorporacion->ultimaLectura();
        $puedeConfigurar = Gate::allows('administrar-accesos');
        $revision = $this->incorporacion->revisar();

        return response()->json([
            'configuracion' => [
                'conexion' => $config['conexion'] ? ['id' => $config['conexion']->id, 'nombre' => $config['conexion']->nombre] : null,
                'conexion_id' => $config['conexion_id'],
                'tabla' => $config['tabla'],
                'temporada_origen' => $config['temporada_origen'],
                'empresas' => $config['empresas'],
                'habilitada' => $config['habilitada'],
                'version' => $config['version'],
            ],
            'conexiones' => $puedeConfigurar
                ? ConexionExterna::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre'])->map->only(['id', 'nombre'])->all()
                : [],
            'ultima_lectura' => $lectura ? [
                'id' => $lectura->id,
                'estado' => $lectura->estado,
                'leida_at' => $lectura->leida_at?->toIso8601String(),
                'temporada_origen' => $lectura->temporada_origen,
                'filas' => $lectura->filas,
                'estandares' => $lectura->estandares,
                'filas_duplicadas' => $lectura->filas_duplicadas,
                'cabeceras_descartadas' => $lectura->cabeceras_descartadas,
                'mensaje' => $lectura->mensaje,
                'usuario' => $lectura->usuario?->name,
            ] : null,
            'estandares' => $revision,
            'insumos_pendientes' => $this->incorporacion->insumosPendientes($revision),
            'resumen' => collect($revision)->countBy('estado')->all(),
            'puede_configurar' => $puedeConfigurar,
            'puede_administrar' => Gate::allows('administrar-estandares-materiales'),
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function configurar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'conexion_id' => ['required', 'uuid'],
            'tabla' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'temporada_origen' => ['required', 'string', 'max:40'],
            'empresas' => ['present', 'array', 'max:30'],
            'empresas.*' => ['required', 'string', 'max:40'],
            'habilitada' => ['required', 'boolean'],
            'version_conocida' => ['required', 'integer', 'min:0'],
        ]);
        $config = $this->lector->guardarConfiguracion($datos, $request->user());

        return response()->json(['message' => 'Configuración de estándares de planta guardada.', 'version' => $config['version']]);
    }

    public function leer(Request $request, ServicioOperacionesIntegracion $operaciones): JsonResponse
    {
        return $operaciones->aceptada($operaciones->solicitarEstandares($request->user()));
    }

    public function vincularCliente(Request $request): JsonResponse
    {
        $datos = $request->validate(['cod_empresa' => ['required', 'string', 'max:40'], 'cliente_id' => ['required', 'uuid']]);

        return response()->json($this->incorporacion->vincularCliente($datos['cod_empresa'], $datos['cliente_id'], $request->user()));
    }

    public function vincularEmbalaje(Request $request): JsonResponse
    {
        $datos = $request->validate(['estandar_origen_id' => ['required', 'uuid'], 'envase_validacion_id' => ['required', 'uuid']]);

        return response()->json($this->incorporacion->vincularEmbalaje($datos['estandar_origen_id'], $datos['envase_validacion_id'], $request->user()));
    }

    public function vincularInsumo(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'cod_empresa' => ['required', 'string', 'max:40'],
            'cod_insumo' => ['required', 'string', 'max:100'],
            'item_material_id' => ['required', 'uuid'],
        ]);
        $this->incorporacion->vincularInsumo($datos['cod_empresa'], $datos['cod_insumo'], $datos['item_material_id'], $request->user());

        return response()->json(['message' => "El insumo {$datos['cod_insumo']} de {$datos['cod_empresa']} quedó reconocido."]);
    }

    public function incorporar(Request $request): JsonResponse
    {
        $datos = $request->validate(['ids' => ['nullable', 'array', 'max:500'], 'ids.*' => ['uuid']]);
        $resumen = $this->incorporacion->incorporar($datos['ids'] ?? null, $request->user());

        return response()->json([
            'message' => "Estándares de planta: {$resumen['creados']} nuevos, {$resumen['actualizados']} actualizados, {$resumen['al_dia']} al día y {$resumen['omitidos']} pendientes de cliente o embalaje.",
            'resumen' => $resumen,
        ]);
    }
}

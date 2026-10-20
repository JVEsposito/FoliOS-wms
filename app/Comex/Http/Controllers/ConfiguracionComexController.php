<?php

namespace App\Comex\Http\Controllers;

use App\Comex\Configuracion\DocumentosSugeridos;
use App\Comex\Configuracion\GestorConfiguracion;
use App\Comex\Expediente\EmbarquesOperacion;
use App\Comex\Models\ClienteComex;
use App\Comex\Models\PaisTipoDocumento;
use App\Comex\Models\TipoDocumento;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Configuración de Comex: documentos exigidos y gestión de cada cliente. */
class ConfiguracionComexController extends Controller
{
    public function __construct(
        private readonly GestorConfiguracion $gestor,
        private readonly EmbarquesOperacion $operacion,
        private readonly DocumentosSugeridos $sugeridos,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $configurados = ClienteComex::query()->get()->keyBy('cliente_id');
        $operacion = collect($this->operacion->clientes())->keyBy('id');

        return response()->json([
            'tipos' => TipoDocumento::query()->with('paises')->orderBy('orden')->orderBy('nombre')->get()->map(fn (TipoDocumento $tipo): array => $this->tipo($tipo))->all(),
            // Clientes de la operación con su gestión en Comex; los inactivos solo si ya se configuraron.
            'clientes' => $operacion
                ->filter(fn (array $cliente): bool => $cliente['activo'] || $configurados->has($cliente['id']))
                ->map(fn (array $cliente): array => $this->cliente($cliente, $configurados->get($cliente['id'])))
                ->values()->all(),
            'paises' => $this->operacion->paises(),
            'sugeridos_faltantes' => $this->sugeridos->faltantes(),
            'datos_operacion' => collect(TipoDocumento::DATOS_OPERACION)->map(fn (string $nombre, string $clave): array => ['clave' => $clave, 'nombre' => $nombre])->values()->all(),
            'permisos' => ['configurar' => $request->user()->can('configurar-comex')],
        ]);
    }

    public function storeTipo(Request $request): JsonResponse
    {
        $tipo = $this->gestor->crearTipo($this->validarTipo($request, nuevo: true), $request->user());

        return response()->json(['message' => "Documento «{$tipo->nombre}» agregado.", 'data' => $this->tipo($tipo)], 201);
    }

    public function storeSugeridos(Request $request): JsonResponse
    {
        $agregados = $this->sugeridos->cargar($request->user());

        return response()->json([
            'message' => match ($agregados) {
                0 => 'Los documentos sugeridos ya estaban configurados.',
                1 => 'Se agregó 1 documento sugerido.',
                default => "Se agregaron {$agregados} documentos sugeridos.",
            },
            'agregados' => $agregados,
        ]);
    }

    public function updateTipo(Request $request, TipoDocumento $tipo): JsonResponse
    {
        $tipo = $this->gestor->actualizarTipo($tipo, $this->validarTipo($request, nuevo: false), $request->user());

        return response()->json(['message' => "Documento «{$tipo->nombre}» actualizado.", 'data' => $this->tipo($tipo)]);
    }

    public function updateCliente(Request $request, string $cliente): JsonResponse
    {
        $datos = Validator::make([...$request->all(), 'cliente' => $cliente], [
            'cliente' => ['required', 'uuid'],
            'gestion' => ['required', Rule::in(ClienteComex::GESTIONES)],
            'observacion' => ['nullable', 'string', 'max:500'],
            'version' => ['required', 'integer', 'min:0'],
        ], [
            'cliente.uuid' => 'El cliente indicado no es válido.',
            'gestion.required' => 'Indique cómo sigue Comex a este cliente.',
            'gestion.in' => 'La gestión indicada no es válida.',
            'observacion.max' => 'La observación no puede superar 500 caracteres.',
            'version.required' => 'Falta la versión. Recargue la configuración.',
        ])->validate();

        $registro = $this->gestor->guardarCliente($cliente, $datos['gestion'], $datos['observacion'] ?? null, (int) $datos['version'], $request->user());

        return response()->json([
            'message' => "Gestión de {$registro->cliente_nombre} actualizada.",
            'data' => $this->cliente(['id' => $registro->cliente_id, 'nombre' => $registro->cliente_nombre, 'activo' => true], $registro),
        ]);
    }

    /** @return array<string, mixed> */
    private function validarTipo(Request $request, bool $nuevo): array
    {
        return Validator::make($request->all(), [
            'nombre' => ['required', 'string', 'min:3', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'momento' => ['required', Rule::in(TipoDocumento::MOMENTOS)],
            'exigencia' => ['required', Rule::in(TipoDocumento::EXIGENCIAS)],
            'transportes' => ['required', 'array', 'min:1'],
            'transportes.*' => [Rule::in(TipoDocumento::TRANSPORTES)],
            'gestiones' => ['required', 'array', 'min:1'],
            'gestiones.*' => [Rule::in([ClienteComex::SERVICIO, ClienteComex::PROPIA])],
            'paises' => ['nullable', 'array', 'max:250'],
            'paises.*' => ['string', 'size:2'],
            'pide_numero' => ['required', 'boolean'],
            'pide_fecha' => ['required', 'boolean'],
            'pide_archivo' => ['required', 'boolean'],
            'dato_operacion' => ['nullable', Rule::in(array_keys(TipoDocumento::DATOS_OPERACION))],
            'orden' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'activo' => ['required', 'boolean'],
            'version' => [$nuevo ? 'nullable' : 'required', 'integer', 'min:0'],
        ], [
            'nombre.required' => 'Indique el nombre del documento.',
            'nombre.min' => 'El nombre debe tener al menos 3 caracteres.',
            'nombre.max' => 'El nombre no puede superar 120 caracteres.',
            'descripcion.max' => 'La descripción no puede superar 500 caracteres.',
            'momento.required' => 'Indique si el documento se necesita antes o después de la salida.',
            'momento.in' => 'El momento indicado no es válido.',
            'exigencia.required' => 'Indique cuándo se exige el documento.',
            'exigencia.in' => 'La exigencia indicada no es válida.',
            'transportes.required' => 'Elija al menos una modalidad de transporte.',
            'transportes.min' => 'Elija al menos una modalidad de transporte.',
            'transportes.*.in' => 'La modalidad de transporte no es válida.',
            'gestiones.required' => 'Elija al menos un tipo de cliente.',
            'gestiones.min' => 'Elija al menos un tipo de cliente.',
            'gestiones.*.in' => 'El tipo de cliente no es válido.',
            'paises.*.size' => 'Uno de los países no es válido.',
            'dato_operacion.in' => 'El dato de la operación no es válido.',
            'orden.integer' => 'El orden debe ser un número entero.',
            'version.required' => 'Falta la versión. Recargue la configuración.',
        ])->validate();
    }

    /** @return array<string, mixed> */
    private function tipo(TipoDocumento $tipo): array
    {
        return [
            'id' => $tipo->id,
            'codigo' => $tipo->codigo,
            'nombre' => $tipo->nombre,
            'descripcion' => $tipo->descripcion,
            'momento' => $tipo->momento,
            'exigencia' => $tipo->exigencia,
            'transportes' => $tipo->transportes,
            'gestiones' => $tipo->gestiones,
            'paises' => $tipo->paises->map(fn (PaisTipoDocumento $pais): array => ['iso2' => $pais->pais_iso2, 'nombre' => $pais->pais_nombre])->values()->all(),
            'pide_numero' => $tipo->pide_numero,
            'pide_fecha' => $tipo->pide_fecha,
            'pide_archivo' => $tipo->pide_archivo,
            'dato_operacion' => $tipo->dato_operacion,
            'orden' => $tipo->orden,
            'activo' => $tipo->activo,
            'sugerido' => $tipo->sugerido,
            'version' => $tipo->version,
            'actualizado_por' => $tipo->actualizado_por_nombre,
            'actualizado_at' => $tipo->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array{id: string, nombre: string, activo: bool}  $cliente
     * @return array<string, mixed>
     */
    private function cliente(array $cliente, ?ClienteComex $registro): array
    {
        return [
            'cliente_id' => $cliente['id'],
            'nombre' => $cliente['nombre'],
            'activo' => $cliente['activo'],
            'gestion' => $registro->gestion ?? ClienteComex::SERVICIO,
            'configurado' => $registro !== null,
            'observacion' => $registro?->observacion,
            'version' => $registro->version ?? 0,
            'actualizado_por' => $registro?->actualizado_por_nombre,
            'actualizado_at' => $registro?->updated_at?->toIso8601String(),
        ];
    }
}

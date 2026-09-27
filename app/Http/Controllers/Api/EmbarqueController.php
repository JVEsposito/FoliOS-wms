<?php

namespace App\Http\Controllers\Api;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Http\Controllers\Controller;
use App\Http\Requests\ActualizarEmbarqueRequest;
use App\Http\Requests\CancelarEmbarqueRequest;
use App\Http\Requests\ConfirmarEmbarqueRequest;
use App\Http\Requests\GuardarEmbarqueRequest;
use App\Models\Anden;
use App\Models\Camara;
use App\Models\Cliente;
use App\Models\Embarque;
use App\Models\Pais;
use App\Models\Puerto;
use App\Services\Embarques\ServicioCalendarioEmbarques;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class EmbarqueController extends Controller
{
    public function index(
        Request $request,
        ServicioCalendarioEmbarques $servicio,
    ): JsonResponse {
        Gate::authorize('consultar-catalogo-cargas');
        $filtros = $request->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
        ]);
        $desde = CarbonImmutable::parse($filtros['desde'], config('app.timezone'))->startOfDay();
        $hasta = CarbonImmutable::parse($filtros['hasta'], config('app.timezone'))->startOfDay();

        abort_if($desde->diffInDays($hasta) > 31, Response::HTTP_UNPROCESSABLE_ENTITY,
            'El calendario permite consultar un máximo de 32 días por vez.');

        $temporada = app(ServicioTemporadaActiva::class)->buscar() ?? abort(404);
        $embarques = Embarque::query()
            ->where('temporada_id', $temporada->id)
            ->whereBetween('fecha_programada', [$desde->toDateString(), $hasta->toDateString()])
            ->with([
                'cliente', 'carga', 'puertoEmbarque.pais',
                'instructivos.paisDestino', 'instructivos.puertoDestino',
                'sobrecupoAutorizadoPor',
            ])
            ->orderBy('fecha_programada')
            ->orderBy('hora_programada')
            ->get();

        return response()->json([
            'temporada' => [
                'id' => $temporada->id,
                'codigo' => $temporada->codigo,
                'nombre' => $temporada->nombre,
                'intervalo_embarques_minutos' => $temporada->intervalo_embarques_minutos,
            ],
            'ventanas' => $servicio->ventanas($temporada, $desde, $hasta),
            'embarques' => $embarques->map(fn (Embarque $embarque): array => $this->embarque($embarque)),
            'catalogos' => [
                'clientes' => Cliente::query()->where('activo', true)
                    ->orderBy('codigo')->get(['id', 'codigo', 'nombre', 'codigo_folio_materiales']),
                'camaras' => Camara::query()
                    ->where('estado', EstadoCamara::Activa->value)
                    ->where('contenido', ContenidoCamara::Productos->value)
                    ->orderBy('codigo')->get(['id', 'codigo', 'nombre']),
                'andenes' => Anden::query()->where('activo', true)
                    ->orderBy('codigo')->get(['id', 'codigo', 'nombre']),
                'paises' => Pais::query()
                    ->where('activo', true)
                    ->whereHas('puertos', fn ($consulta) => $consulta->where('activo', true))
                    ->orderBy('nombre_es')
                    ->get(['id', 'iso_alpha2', 'nombre_es']),
                'puertos' => Puerto::query()->where('activo', true)
                    ->with('pais:id,iso_alpha2,nombre_es')
                    ->orderBy('nombre')
                    ->get(['id', 'pais_id', 'codigo', 'nombre', 'tipo']),
            ],
            'permisos' => [
                'gestionar' => $request->user()->can('gestionar-cargas'),
                'autorizar_sobrecupo' => $request->user()->can('autorizar-sobrecupo-embarques'),
            ],
        ]);
    }

    public function store(
        GuardarEmbarqueRequest $request,
        ServicioCalendarioEmbarques $servicio,
    ): JsonResponse {
        $embarque = $servicio->crear($request->validated(), $request->user());

        return response()->json(['data' => $this->embarque($embarque)], Response::HTTP_CREATED);
    }

    public function show(
        Embarque $embarque,
        ServicioCalendarioEmbarques $servicio,
    ): JsonResponse {
        Gate::authorize('consultar-catalogo-cargas');

        return response()->json(['data' => $this->embarque($servicio->cargar($embarque))]);
    }

    public function update(
        ActualizarEmbarqueRequest $request,
        Embarque $embarque,
        ServicioCalendarioEmbarques $servicio,
    ): JsonResponse {
        $actualizado = $servicio->actualizar(
            $embarque,
            $request->validated(),
            $request->user(),
            $request->integer('version_esperada'),
        );

        return response()->json(['data' => $this->embarque($actualizado)]);
    }

    public function confirmar(
        ConfirmarEmbarqueRequest $request,
        Embarque $embarque,
        ServicioCalendarioEmbarques $servicio,
    ): JsonResponse {
        $confirmado = $servicio->confirmar(
            $embarque,
            $request->validated(),
            $request->user(),
            $request->integer('version_esperada'),
        );

        return response()->json(['data' => $this->embarque($confirmado)]);
    }

    public function cancelar(
        CancelarEmbarqueRequest $request,
        Embarque $embarque,
        ServicioCalendarioEmbarques $servicio,
    ): JsonResponse {
        $cancelado = $servicio->cancelar(
            $embarque,
            $request->user(),
            $request->integer('version_esperada'),
            $request->validated('motivo'),
        );

        return response()->json(['data' => $this->embarque($cancelado)]);
    }

    /** @return array<string, mixed> */
    private function embarque(Embarque $embarque): array
    {
        $embarque->loadMissing([
            'cliente', 'carga', 'puertoEmbarque.pais',
            'instructivos.paisDestino', 'instructivos.puertoDestino',
            'sobrecupoAutorizadoPor',
        ]);

        return [
            'id' => $embarque->id,
            'codigo' => $embarque->codigo,
            'numero_correlativo' => $embarque->numero_correlativo,
            'version' => $embarque->version,
            'fecha_programada' => $embarque->fecha_programada->toDateString(),
            'hora_programada' => substr((string) $embarque->hora_programada, 0, 5),
            'intervalo_minutos' => $embarque->intervalo_minutos,
            'modalidad' => $embarque->modalidad->value,
            'estado' => $embarque->estado->value,
            'cliente' => [
                'id' => $embarque->cliente->id,
                'codigo' => $embarque->cliente->codigo,
                'nombre' => $embarque->cliente->nombre,
            ],
            'referencia_correo' => $embarque->referencia_correo,
            'nave_vuelo' => $embarque->nave_vuelo,
            'transportista' => $embarque->transportista,
            'puerto_embarque' => $embarque->puerto_embarque,
            'puerto_embarque_id' => $embarque->puerto_embarque_id,
            'puerto_embarque_catalogo' => $embarque->puertoEmbarque ? [
                'id' => $embarque->puertoEmbarque->id,
                'codigo' => $embarque->puertoEmbarque->codigo,
                'nombre' => $embarque->puertoEmbarque->nombre,
                'tipo' => $embarque->puertoEmbarque->tipo,
                'pais' => $embarque->puertoEmbarque->pais ? [
                    'id' => $embarque->puertoEmbarque->pais->id,
                    'iso_alpha2' => $embarque->puertoEmbarque->pais->iso_alpha2,
                    'nombre' => $embarque->puertoEmbarque->pais->nombre_es,
                ] : null,
            ] : null,
            'contenedor' => $embarque->contenedor,
            'sello' => $embarque->sello,
            'patente_camion' => $embarque->patente_camion,
            'patente_trasera' => $embarque->patente_trasera,
            'documentos' => $embarque->documentos,
            'observacion' => $embarque->observacion,
            'sobrecupo' => $embarque->sobrecupo_autorizado_at ? [
                'motivo' => $embarque->sobrecupo_motivo,
                'autorizado_at' => $embarque->sobrecupo_autorizado_at->toAtomString(),
                'autorizado_por' => $embarque->sobrecupoAutorizadoPor?->name,
            ] : null,
            'carga' => $embarque->carga ? [
                'id' => $embarque->carga->id,
                'codigo' => $embarque->carga->codigo,
                'estado' => $embarque->carga->estado->value,
                'version' => $embarque->carga->version,
            ] : null,
            'instructivos' => $embarque->instructivos->map(fn ($instructivo): array => [
                'id' => $instructivo->id,
                'orden' => $instructivo->orden,
                'numero_externo' => $instructivo->numero_externo,
                'recibidor' => $instructivo->recibidor,
                'destino_pais' => $instructivo->destino_pais,
                'pais_destino_id' => $instructivo->pais_destino_id,
                'destino_ciudad' => $instructivo->destino_ciudad,
                'puerto_destino_id' => $instructivo->puerto_destino_id,
                'pais_destino' => $instructivo->paisDestino ? [
                    'id' => $instructivo->paisDestino->id,
                    'iso_alpha2' => $instructivo->paisDestino->iso_alpha2,
                    'nombre' => $instructivo->paisDestino->nombre_es,
                ] : null,
                'puerto_destino' => $instructivo->puertoDestino ? [
                    'id' => $instructivo->puertoDestino->id,
                    'codigo' => $instructivo->puertoDestino->codigo,
                    'nombre' => $instructivo->puertoDestino->nombre,
                    'tipo' => $instructivo->puertoDestino->tipo,
                ] : null,
                'cantidad_pallets' => $instructivo->cantidad_pallets,
                'cantidad_cajas' => $instructivo->cantidad_cajas,
                'booking' => $instructivo->booking,
                'sps' => $instructivo->sps,
                'dus' => $instructivo->dus,
                'planilla_sag' => $instructivo->planilla_sag,
                'sello_sag' => $instructivo->sello_sag,
                'observacion' => $instructivo->observacion,
            ])->values(),
            'totales' => [
                'instructivos' => $embarque->instructivos->count(),
                'pallets' => $embarque->instructivos->sum('cantidad_pallets'),
                'cajas' => $embarque->instructivos->sum('cantidad_cajas'),
            ],
            'cancelacion_motivo' => $embarque->cancelacion_motivo,
            'confirmado_at' => $embarque->confirmado_at?->toAtomString(),
            'cancelado_at' => $embarque->cancelado_at?->toAtomString(),
            'created_at' => $embarque->created_at?->toAtomString(),
            'updated_at' => $embarque->updated_at?->toAtomString(),
        ];
    }
}

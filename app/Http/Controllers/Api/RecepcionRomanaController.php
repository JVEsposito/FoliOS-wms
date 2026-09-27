<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConceptoEnvasesRomana;
use App\Enums\EstadoRecepcionRomana;
use App\Enums\EstadoValidacionMp;
use App\Enums\TipoEnvaseRomana;
use App\Enums\TipoRecepcionRomana;
use App\Enums\TipoServicioRomana;
use App\Http\Controllers\Controller;
use App\Http\Requests\ActualizarRecepcionRomanaRequest;
use App\Http\Requests\AnularPesajeEnvasesRomanaRequest;
use App\Http\Requests\CerrarRecepcionRomanaRequest;
use App\Http\Requests\ConfirmarIngresoRomanaRequest;
use App\Http\Requests\ConsultarRecepcionesRomanaRequest;
use App\Http\Requests\CorregirRecepcionRomanaRequest;
use App\Http\Requests\CrearRecepcionRomanaRequest;
use App\Http\Requests\RegistrarPesajeEnvasesRomanaRequest;
use App\Models\Cliente;
use App\Models\EventoRecepcionRomana;
use App\Models\PesajeEnvaseRecepcionRomana;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use App\Services\Romana\GeneradorAvisoReciboPdf;
use App\Services\Romana\ServicioRecepcionRomana;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class RecepcionRomanaController extends Controller
{
    public function catalogos(): JsonResponse
    {
        Gate::authorize('consultar-romana');

        return response()->json([
            'temporadas' => Temporada::query()
                ->productivas()
                ->orderByDesc('activa')
                ->orderByDesc('fecha_inicio')
                ->get(['id', 'codigo', 'nombre', 'fecha_inicio', 'fecha_fin', 'activa']),
            'clientes' => Cliente::query()
                ->where('activo', true)
                ->withCount(['catalogosValidacion', 'catalogosMateriales'])
                ->orderBy('nombre')
                ->get()
                ->map(fn (Cliente $cliente): array => [
                    'id' => $cliente->id,
                    'nombre' => $cliente->nombre,
                    'codigo' => $cliente->codigo,
                    'codigo_externo' => $cliente->codigo_externo,
                    'presente_en_validacion' => $cliente->catalogos_validacion_count > 0,
                    'presente_en_materiales' => $cliente->catalogos_materiales_count > 0,
                ]),
            'tipos_servicio' => array_map(
                fn (TipoServicioRomana $tipo): array => ['codigo' => $tipo->value, 'nombre' => match ($tipo) {
                    TipoServicioRomana::Almacenaje => 'Almacenaje',
                    TipoServicioRomana::Proceso => 'Proceso',
                    TipoServicioRomana::Prefrio => 'Pre-frío',
                }],
                TipoServicioRomana::cases(),
            ),
            'tipos_recepcion' => [
                ['codigo' => TipoRecepcionRomana::FrutaConEnvases->value, 'nombre' => 'Fruta con envases'],
                [
                    'codigo' => TipoRecepcionRomana::FrutaPesajeEnvases->value,
                    'nombre' => 'Fruta con pesaje acumulativo de envases',
                ],
                ['codigo' => TipoRecepcionRomana::SoloEnvases->value, 'nombre' => 'Solo envases'],
            ],
            'conceptos_envases' => [
                ['codigo' => ConceptoEnvasesRomana::Compra->value, 'nombre' => 'Compra de envases propios'],
                ['codigo' => ConceptoEnvasesRomana::Arriendo->value, 'nombre' => 'Arriendo de envases'],
            ],
            'tipos_envase' => array_map(
                fn (TipoEnvaseRomana $tipo): array => ['codigo' => $tipo->value, 'nombre' => ucfirst($tipo->value)],
                TipoEnvaseRomana::cases(),
            ),
        ]);
    }

    public function index(ConsultarRecepcionesRomanaRequest $request): JsonResponse
    {
        $filtros = $request->validated();
        $base = RecepcionRomana::query();
        if (! empty($filtros['desde'])) {
            $base->whereDate('ingreso_at', '>=', $filtros['desde']);
        }
        if (! empty($filtros['hasta'])) {
            $base->whereDate('ingreso_at', '<=', $filtros['hasta']);
        }
        $base->when(
            $filtros['temporada_id'] ?? null,
            fn (Builder $consulta, string $temporadaId): Builder => $consulta
                ->where('temporada_id', $temporadaId),
            fn (Builder $consulta): Builder => $consulta->whereHas(
                'temporada',
                fn (Builder $temporada): Builder => $temporada->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id),
            ),
        );
        if (! empty($filtros['buscar'])) {
            $buscar = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($filtros['buscar'])).'%';
            $base->where(function (Builder $consulta) use ($buscar): void {
                $consulta
                    ->where('numero_recepcion', 'like', $buscar)
                    ->orWhere('numero_guia_despacho', 'like', $buscar)
                    ->orWhere('patente_camion', 'like', $buscar)
                    ->orWhere('nombre_conductor', 'like', $buscar)
                    ->orWhere('cliente_nombre_snapshot', 'like', $buscar);
            });
        }

        $resumen = [
            'en_bascula_ingreso' => (clone $base)->where('estado', EstadoRecepcionRomana::EnBasculaIngreso)->count(),
            'en_pesaje_envases' => (clone $base)->where('estado', EstadoRecepcionRomana::EnPesajeEnvases)->count(),
            'en_bascula_salida' => (clone $base)->where('estado', EstadoRecepcionRomana::EnBasculaSalida)->count(),
            'cerradas' => (clone $base)->where('estado', EstadoRecepcionRomana::Cerrado)->count(),
            'peso_neto' => round((float) (clone $base)->where('estado', EstadoRecepcionRomana::Cerrado)->sum('peso_neto'), 2),
        ];

        if (! empty($filtros['estado'])) {
            $base->where('estado', $filtros['estado']);
        }
        $paginacion = $base
            ->with(['creadoPor', 'ingresoConfirmadoPor', 'cerradoPor', 'validacionTomadaPor', 'detallesEnvases'])
            ->orderByDesc('ingreso_at')
            ->paginate((int) ($filtros['por_pagina'] ?? 30));

        return response()->json([
            'data' => collect($paginacion->items())
                ->map(fn (RecepcionRomana $recepcion): array => $this->recepcion($recepcion)),
            'resumen' => $resumen,
            'meta' => [
                'pagina_actual' => $paginacion->currentPage(),
                'ultima_pagina' => $paginacion->lastPage(),
                'por_pagina' => $paginacion->perPage(),
                'total' => $paginacion->total(),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(
        CrearRecepcionRomanaRequest $request,
        ServicioRecepcionRomana $servicio,
    ): JsonResponse {
        $recepcion = $servicio->crear($request->validated(), $request->user());

        return response()->json(['data' => $this->recepcion($recepcion, true)], Response::HTTP_CREATED);
    }

    public function show(RecepcionRomana $recepcion): JsonResponse
    {
        Gate::authorize('consultar-romana');
        $recepcion->load([
            'creadoPor',
            'ingresoConfirmadoPor',
            'cerradoPor',
            'detallesEnvases',
            'pesajesEnvases' => fn ($consulta) => $consulta
                ->with(['registradoPor', 'anuladoPor'])
                ->orderBy('secuencia'),
            'validacionTomadaPor',
            'eventos' => fn ($consulta) => $consulta->with('usuario')->orderBy('ocurrido_at'),
        ]);

        return response()->json(['data' => $this->recepcion($recepcion, true)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function update(
        ActualizarRecepcionRomanaRequest $request,
        RecepcionRomana $recepcion,
        ServicioRecepcionRomana $servicio,
    ): JsonResponse {
        $recepcion = $servicio->actualizar($recepcion, $request->validated(), $request->user());

        return response()->json(['data' => $this->recepcion($recepcion, true)]);
    }

    public function corregir(
        CorregirRecepcionRomanaRequest $request,
        RecepcionRomana $recepcion,
        ServicioRecepcionRomana $servicio,
    ): JsonResponse {
        $recepcion = $servicio->corregirAdministrativamente(
            $recepcion,
            $request->validated(),
            $request->user(),
        );

        return response()->json(['data' => $this->recepcion($recepcion, true)]);
    }

    public function confirmarIngreso(
        ConfirmarIngresoRomanaRequest $request,
        RecepcionRomana $recepcion,
        ServicioRecepcionRomana $servicio,
    ): JsonResponse {
        $recepcion = $servicio->confirmarIngreso(
            $recepcion,
            (string) $request->validated('operacion_id'),
            $request->user(),
        );

        return response()->json(['data' => $this->recepcion($recepcion, true)]);
    }

    public function cerrar(
        CerrarRecepcionRomanaRequest $request,
        RecepcionRomana $recepcion,
        ServicioRecepcionRomana $servicio,
    ): JsonResponse {
        $recepcion = $servicio->cerrar($recepcion, $request->validated(), $request->user());

        return response()->json(['data' => $this->recepcion($recepcion, true)]);
    }

    public function registrarPesajeEnvases(
        RegistrarPesajeEnvasesRomanaRequest $request,
        RecepcionRomana $recepcion,
        ServicioRecepcionRomana $servicio,
    ): JsonResponse {
        $recepcion = $servicio->registrarPesajeEnvases(
            $recepcion,
            $request->validated(),
            $request->user(),
        );

        return response()->json(['data' => $this->recepcion($recepcion, true)]);
    }

    public function anularPesajeEnvases(
        AnularPesajeEnvasesRomanaRequest $request,
        RecepcionRomana $recepcion,
        PesajeEnvaseRecepcionRomana $pesaje,
        ServicioRecepcionRomana $servicio,
    ): JsonResponse {
        $recepcion = $servicio->anularPesajeEnvases(
            $recepcion,
            $pesaje,
            $request->validated(),
            $request->user(),
        );

        return response()->json(['data' => $this->recepcion($recepcion, true)]);
    }

    public function avisoRecibo(
        RecepcionRomana $recepcion,
        GeneradorAvisoReciboPdf $generador,
    ): Response {
        Gate::authorize('consultar-romana');
        $pdf = $generador->generar($recepcion);
        $nombre = 'aviso-recibo-'.strtolower((string) $recepcion->numero_recepcion).'.pdf';

        return response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function registroPesajeEnBlanco(GeneradorAvisoReciboPdf $generador): Response
    {
        Gate::authorize('consultar-romana');

        return response($generador->generarEnBlanco(), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="registro-pesaje-romana-en-blanco.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** @return array<string, mixed> */
    private function recepcion(RecepcionRomana $recepcion, bool $conEventos = false): array
    {
        $esPesajeEnvases = $recepcion->tipo_recepcion === TipoRecepcionRomana::FrutaPesajeEnvases;
        $esSoloEnvases = $recepcion->tipo_recepcion === TipoRecepcionRomana::SoloEnvases;
        $cantidadDeclaradaPesaje = $esPesajeEnvases
            ? (int) ($recepcion->detallesEnvases
                ->first(fn ($detalle): bool => $detalle->tipo_envase === $recepcion->tipo_envase_pesaje)
                ?->cantidad_declarada ?? 0)
            : null;
        $cantidadPesada = $esPesajeEnvases ? (int) $recepcion->cantidad_envases_pesados : null;
        $cantidadPendiente = $esPesajeEnvases
            ? max(0, (int) $cantidadDeclaradaPesaje - (int) $cantidadPesada)
            : null;
        $pesajeCompleto = $esPesajeEnvases
            && $cantidadDeclaradaPesaje > 0
            && $cantidadPesada === $cantidadDeclaradaPesaje;
        $puedeEditar = $recepcion->estado->esEditable()
            && (! $esPesajeEnvases || $cantidadPesada === 0);

        $datos = [
            'id' => $recepcion->id,
            'numero_recepcion' => $recepcion->numero_recepcion,
            'estado' => $recepcion->estado->value,
            'estado_validacion_mp' => $recepcion->estado_validacion_mp->value,
            'temporada' => [
                'id' => $recepcion->temporada_id,
                'codigo' => $recepcion->temporada_codigo_snapshot,
                'nombre' => $recepcion->temporada_nombre_snapshot,
            ],
            'cliente' => [
                'id' => $recepcion->cliente_id,
                'codigo' => $recepcion->cliente_codigo_snapshot,
                'nombre' => $recepcion->cliente_nombre_snapshot,
            ],
            'tipo_servicio' => $recepcion->tipo_servicio->value,
            'tipo_recepcion' => $recepcion->tipo_recepcion->value,
            'concepto_envases' => $recepcion->concepto_envases?->value,
            'cantidad_envases_declarados' => $recepcion->cantidad_envases_declarados,
            'tipo_envase_declarado' => $recepcion->tipo_envase_declarado->value,
            'envases' => $recepcion->detallesEnvases->map(fn ($detalle): array => [
                'id' => $detalle->id,
                'tipo_envase' => $detalle->tipo_envase->value,
                'cantidad_declarada' => $detalle->cantidad_declarada,
                'cantidad_validada' => $detalle->cantidad_validada,
                'tara_unitaria_salida' => $detalle->tara_unitaria_salida !== null
                    ? (float) $detalle->tara_unitaria_salida
                    : null,
                'diferencia' => $detalle->cantidad_validada === null
                    ? null
                    : $detalle->cantidad_validada - $detalle->cantidad_declarada,
            ])->values(),
            'numero_guia_despacho' => $recepcion->numero_guia_despacho,
            'patente_camion' => $recepcion->patente_camion,
            'tipo_camion' => $recepcion->tipo_camion?->value,
            'patente_carro' => $recepcion->patente_carro,
            'rut_conductor' => $recepcion->rut_conductor,
            'nombre_conductor' => $recepcion->nombre_conductor,
            'peso_bruto' => $recepcion->peso_bruto !== null ? (float) $recepcion->peso_bruto : null,
            'peso_tara' => $recepcion->peso_tara !== null ? (float) $recepcion->peso_tara : null,
            'peso_neto' => $recepcion->peso_neto !== null ? (float) $recepcion->peso_neto : null,
            'salida_sin_envases' => $recepcion->salida_sin_envases,
            'peso_tara_envases' => $recepcion->peso_tara_envases !== null
                ? (float) $recepcion->peso_tara_envases
                : null,
            'peso_tara_total' => $recepcion->peso_tara !== null
                ? round(
                    (float) $recepcion->peso_tara + (float) ($recepcion->peso_tara_envases ?? 0),
                    3,
                )
                : null,
            'tipo_envase_calculo_neto' => $recepcion->tipo_envase_calculo_neto,
            'cantidad_envase_calculo_neto' => $recepcion->cantidad_envase_calculo_neto,
            'peso_neto_por_envase' => $recepcion->peso_neto_por_envase !== null
                ? (float) $recepcion->peso_neto_por_envase
                : null,
            'pesaje_envases' => $esPesajeEnvases ? [
                'tipo_envase' => $recepcion->tipo_envase_pesaje?->value,
                'tara_unitaria' => $recepcion->tara_unitaria_envase !== null
                    ? (float) $recepcion->tara_unitaria_envase
                    : null,
                'cantidad_declarada' => $cantidadDeclaradaPesaje,
                'cantidad_pesada' => $cantidadPesada,
                'cantidad_pendiente' => $cantidadPendiente,
                'completo' => $pesajeCompleto,
                'cantidad_lecturas_activas' => $recepcion->relationLoaded('pesajesEnvases')
                    ? $recepcion->pesajesEnvases->whereNull('anulado_at')->count()
                    : null,
            ] : null,
            'fecha_ingreso' => $esSoloEnvases
                ? $recepcion->ingreso_at?->copy()
                    ->setTimezone(config('app.operational_timezone'))
                    ->toDateString()
                : null,
            'ingreso_at' => $recepcion->ingreso_at?->toAtomString(),
            'ingreso_confirmado_at' => $recepcion->ingreso_confirmado_at?->toAtomString(),
            'salida_at' => $recepcion->salida_at?->toAtomString(),
            'validacion_tomada_at' => $recepcion->validacion_tomada_at?->toAtomString(),
            'validado_at' => $recepcion->validado_at?->toAtomString(),
            'observacion' => $recepcion->observacion,
            'observacion_cierre' => $recepcion->observacion_cierre,
            'version' => $recepcion->version,
            'puede_editar' => $puedeEditar,
            'correccion_administrativa_disponible' => $recepcion->estado_validacion_mp === EstadoValidacionMp::Pendiente
                && (! $esPesajeEnvases || $cantidadPesada === 0),
            'puede_confirmar_ingreso' => $recepcion->estado === EstadoRecepcionRomana::EnBasculaIngreso,
            'puede_registrar_pesaje' => $recepcion->estado === EstadoRecepcionRomana::EnPesajeEnvases,
            'puede_cerrar' => $recepcion->estado === EstadoRecepcionRomana::EnBasculaSalida
                || ($recepcion->estado === EstadoRecepcionRomana::EnPesajeEnvases && $pesajeCompleto),
            'aviso_recibo_disponible' => $recepcion->estado === EstadoRecepcionRomana::Cerrado,
            'creado_por' => $this->usuario($recepcion->creadoPor),
            'ingreso_confirmado_por' => $this->usuario($recepcion->ingresoConfirmadoPor),
            'cerrado_por' => $this->usuario($recepcion->cerradoPor),
            'validacion_tomada_por' => $this->usuario($recepcion->validacionTomadaPor),
        ];

        if ($conEventos) {
            $datos['lecturas_pesaje_envases'] = $recepcion->relationLoaded('pesajesEnvases')
                ? $recepcion->pesajesEnvases
                    ->map(fn (PesajeEnvaseRecepcionRomana $pesaje): array => [
                        'id' => $pesaje->id,
                        'secuencia' => $pesaje->secuencia,
                        'tipo_envase' => $pesaje->tipo_envase->value,
                        'cantidad_envases' => $pesaje->cantidad_envases,
                        'peso_bruto' => (float) $pesaje->peso_bruto,
                        'tara_unitaria' => (float) $pesaje->tara_unitaria_envase,
                        'peso_tara' => (float) $pesaje->peso_tara,
                        'peso_neto' => (float) $pesaje->peso_neto,
                        'peso_neto_por_envase' => round(
                            (float) $pesaje->peso_neto / $pesaje->cantidad_envases,
                            3,
                        ),
                        'observacion' => $pesaje->observacion,
                        'pesado_at' => $pesaje->pesado_at?->toAtomString(),
                        'registrado_por' => $this->usuario($pesaje->registradoPor),
                        'anulado' => $pesaje->anulado_at !== null,
                        'anulado_at' => $pesaje->anulado_at?->toAtomString(),
                        'anulado_por' => $this->usuario($pesaje->anuladoPor),
                        'motivo_anulacion' => $pesaje->motivo_anulacion,
                    ])->values()
                : collect();
            $datos['eventos'] = $recepcion->eventos
                ->map(fn (EventoRecepcionRomana $evento): array => [
                    'id' => $evento->id,
                    'tipo' => $evento->tipo->value,
                    'estado_anterior' => $evento->estado_anterior?->value,
                    'estado_nuevo' => $evento->estado_nuevo->value,
                    'ocurrido_at' => $evento->ocurrido_at?->toAtomString(),
                    'usuario' => $this->usuario($evento->usuario),
                    'datos' => $evento->datos,
                ])->values();
        }

        return $datos;
    }

    /** @return array{id: int, nombre: string}|null */
    private function usuario(mixed $usuario): ?array
    {
        return $usuario ? ['id' => $usuario->id, 'nombre' => $usuario->name] : null;
    }
}

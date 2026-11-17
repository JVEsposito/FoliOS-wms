<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConceptoEnvasesRomana;
use App\Enums\EstadoGuiaDespachoEnvase;
use App\Enums\EstadoRecepcionRomana;
use App\Enums\EstadoRevisionMovimientoEnvase;
use App\Enums\EstadoValidacionMp;
use App\Enums\PropiedadEnvase;
use App\Enums\TipoEnvaseRomana;
use App\Enums\TipoMovimientoEnvase;
use App\Enums\TipoRecepcionRomana;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\DetalleEnvaseRecepcionRomana;
use App\Models\DetalleGuiaDespachoEnvase;
use App\Models\GuiaDespachoEnvase;
use App\Models\MovimientoEnvase;
use App\Models\ReasignacionEnvases;
use App\Models\ReasignacionProductorEnvases;
use App\Models\RevisionMovimientoEnvase;
use App\Models\Temporada;
use App\Services\Envases\GeneradorCuentaEnvasesProductorXlsx;
use App\Services\Envases\GeneradorEstadoCuentaEnvasesPdf;
use App\Services\Envases\ServicioAjustesCuentaEnvases;
use App\Services\Envases\ServicioCorreccionCantidadEnvases;
use App\Services\Envases\ServicioCorreccionPropiedadEnvases;
use App\Services\Envases\ServicioCorreccionSaldoHistoricoEnvases;
use App\Services\Envases\ServicioCuentaCorrienteEnvases;
use App\Services\Envases\ServicioReasignacionEnvases;
use App\Services\Envases\ServicioReasignacionProductorEnvases;
use App\Services\Existencias\GeneradorLibroXlsx;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CuentaCorrienteEnvaseController extends Controller
{
    public function registrarAjuste(Request $request, ServicioAjustesCuentaEnvases $servicio): JsonResponse
    {
        Gate::authorize('ajustar-cuenta-envases');
        $datos = $request->validate([
            'operacion_id' => ['required', 'uuid'],
            'temporada_id' => ['required', 'uuid', 'exists:temporadas,id'],
            'cliente_id' => ['required', 'uuid', 'exists:clientes,id'],
            'tipo_movimiento' => ['required', Rule::in([TipoMovimientoEnvase::AperturaCuenta->value, TipoMovimientoEnvase::AjusteCuenta->value])],
            'tipo_envase' => ['required', Rule::enum(TipoEnvaseRomana::class)],
            'propiedad' => ['required', Rule::enum(PropiedadEnvase::class)],
            'cantidad' => ['required', 'integer', 'between:-100000000,100000000', 'not_in:0'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'referencia' => ['required', 'string', 'max:80'],
            'motivo' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $movimiento = $servicio->registrar($datos, $request->user());

        return response()->json(['data' => $this->movimiento($movimiento->load(['cliente', 'temporada', 'creadoPor', 'revisiones']))], 201);
    }

    public function revertirAjuste(Request $request, MovimientoEnvase $movimientoEnvase, ServicioAjustesCuentaEnvases $servicio): JsonResponse
    {
        Gate::authorize('ajustar-cuenta-envases');
        $datos = $request->validate([
            'operacion_id' => ['required', 'uuid'], 'fecha' => ['required', 'date_format:Y-m-d'],
            'motivo' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $movimiento = $servicio->revertir($movimientoEnvase, $datos, $request->user());

        return response()->json(['data' => $this->movimiento($movimiento->load(['cliente', 'temporada', 'creadoPor', 'revisiones']))], 201);
    }

    public function corregirSaldoHistorico(Request $request, MovimientoEnvase $movimientoEnvase, ServicioCorreccionSaldoHistoricoEnvases $servicio): JsonResponse
    {
        Gate::authorize('ajustar-cuenta-envases');
        $datos = $request->validate(['operacion_id' => ['required', 'uuid'],
            'impacto_conocido' => ['required', 'integer', 'between:-100000000,100000000'],
            'saldo_correcto' => ['required', 'integer', 'between:-100000000,100000000'],
            'es_saldo_historico' => ['required', 'accepted'], 'motivo' => ['required', 'string', 'min:10', 'max:2000']]);
        $datos['impacto_conocido'] = (int) $datos['impacto_conocido'];
        $datos['saldo_correcto'] = (int) $datos['saldo_correcto'];
        $movimiento = $servicio->corregir($movimientoEnvase, $datos, $request->user());

        return response()->json(['data' => $this->movimiento($movimiento->load(['cliente', 'temporada', 'creadoPor', 'revisiones']))], 201);
    }

    /** Guías a las que pertenece el movimiento y dónde están hoy, para reasignarlas. */
    public function reasignacion(MovimientoEnvase $movimientoEnvase, ServicioReasignacionEnvases $servicio): JsonResponse
    {
        Gate::authorize('ajustar-cuenta-envases');

        return response()->json(['data' => $servicio->opciones($movimientoEnvase)]);
    }

    /** Reasigna una guía completa a otro productor CSG o a otra cuenta corriente, sin tocar kilos ni cantidades. */
    public function reasignar(Request $request, ServicioReasignacionEnvases $servicio): JsonResponse
    {
        Gate::authorize('ajustar-cuenta-envases');
        $datos = $request->validate([
            'operacion_id' => ['required', 'uuid'],
            'documento_clave' => ['required', 'string', 'max:120'],
            'reasignacion_vigente_id' => ['nullable', 'uuid'],
            'cliente_destino_id' => ['required', 'uuid'],
            'productor_csg_id' => ['required', 'uuid'],
            'motivo' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'cliente_destino_id.required' => 'Selecciona la cuenta corriente de destino.',
            'productor_csg_id.required' => 'Selecciona el productor CSG de destino.',
            'motivo.required' => 'Indica el motivo de la reasignación.',
            'motivo.min' => 'El motivo debe tener al menos 10 caracteres.',
        ]);

        return response()->json(['data' => $this->reasignacionDatos($servicio->reasignar($datos, $request->user()))], 201);
    }

    public function deshacerReasignacion(Request $request, ReasignacionEnvases $reasignacionEnvases, ServicioReasignacionEnvases $servicio): JsonResponse
    {
        Gate::authorize('ajustar-cuenta-envases');
        $datos = $request->validate([
            'operacion_id' => ['required', 'uuid'],
            'motivo' => ['required', 'string', 'min:10', 'max:2000'],
        ], ['motivo.required' => 'Indica por qué se deshace la reasignación.', 'motivo.min' => 'El motivo debe tener al menos 10 caracteres.']);

        return response()->json(['data' => $this->reasignacionDatos($servicio->deshacer($reasignacionEnvases, $datos, $request->user()))]);
    }

    /** Guías del movimiento con sus productores y cantidades, para reasignarlas a otro productor de la cuenta. */
    public function reasignacionProductor(MovimientoEnvase $movimientoEnvase, ServicioReasignacionProductorEnvases $servicio): JsonResponse
    {
        Gate::authorize('ajustar-cuenta-envases');

        return response()->json(['data' => $servicio->opciones($movimientoEnvase->loadMissing('cliente'))]);
    }

    /** Reasigna un movimiento de envases a otro productor CSG de la misma cuenta, completo o por cantidades. */
    public function reasignarProductor(Request $request, ServicioReasignacionProductorEnvases $servicio): JsonResponse
    {
        Gate::authorize('ajustar-cuenta-envases');
        $datos = $request->validate([
            'operacion_id' => ['required', 'uuid'],
            'documento_clave' => ['required', 'string', 'max:120'],
            'productor_origen_codigo' => ['nullable', 'string', 'max:40'],
            'productor_csg_id' => ['required', 'uuid'],
            'razon_social' => ['required', 'string', 'max:200'],
            'modalidad' => ['required', Rule::in([ReasignacionProductorEnvases::COMPLETA, ReasignacionProductorEnvases::PARCIAL])],
            'envases' => ['required_if:modalidad,'.ReasignacionProductorEnvases::PARCIAL, 'array', 'max:'.count(TipoEnvaseRomana::cases())],
            'envases.*.tipo_envase' => ['required', 'distinct', Rule::enum(TipoEnvaseRomana::class)],
            'envases.*.cantidad' => ['required', 'integer', 'min:0', 'max:100000'],
            'motivo' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'productor_csg_id.required' => 'Selecciona el CSG del productor correcto.',
            'razon_social.required' => 'Falta la razón social del CSG de destino.',
            'envases.required_if' => 'Indica cuántos envases se reasignan.',
            'motivo.required' => 'Indica el motivo de la reasignación.',
            'motivo.min' => 'El motivo debe tener al menos 10 caracteres.',
        ]);
        $registro = $servicio->reasignar($datos, $request->user());

        return response()->json(['data' => [
            'id' => $registro->id, 'documento_clave' => $registro->documento_clave, 'numero_documento' => $registro->numero_documento,
            'modalidad' => $registro->modalidad, 'origen' => $registro->productor_origen, 'destino' => $registro->productor_destino,
            'envases' => $registro->envases, 'motivo' => $registro->motivo, 'registrado_at' => $registro->registrado_at?->toAtomString(),
        ]], 201);
    }

    /**
     * Lo reasignado entre productores de este envase en la guía del movimiento, para el kardex interno.
     *
     * @param  Collection<int, ReasignacionProductorEnvases>  $registros
     * @return list<array<string, mixed>>
     */
    private function reasignacionesProductorDe(MovimientoEnvase $movimiento, $registros): array
    {
        $base = ServicioCuentaCorrienteEnvases::claveProductor($movimiento);
        if ($base === null) {
            return [];
        }

        return $registros->filter(fn (ReasignacionProductorEnvases $r): bool => $r->cliente_id === $movimiento->cliente_id
            && ServicioReasignacionProductorEnvases::baseDe($r->documento_clave) === $base)
            ->map(fn (ReasignacionProductorEnvases $r): ?array => ($cantidad = (int) collect($r->envases)->where('tipo_envase', $movimiento->tipo_envase->value)->sum('cantidad')) > 0 ? [
                'id' => $r->id, 'numero_documento' => $r->numero_documento, 'origen' => $r->productor_origen, 'destino' => $r->productor_destino,
                'cantidad' => $cantidad, 'modalidad' => $r->modalidad, 'motivo' => $r->motivo, 'usuario' => $r->usuario?->name,
                'registrado_at' => $r->registrado_at?->toAtomString(),
            ] : null)->filter()->values()->all();
    }

    /** @return array<string, mixed> */
    private function reasignacionDatos(ReasignacionEnvases $reasignacion): array
    {
        $reasignacion->loadMissing(['clienteOrigen:id,codigo,nombre', 'clienteDestino:id,codigo,nombre']);

        return [
            'id' => $reasignacion->id, 'documento_clave' => $reasignacion->documento_clave, 'numero_documento' => $reasignacion->numero_documento,
            'deshace' => $reasignacion->deshace, 'vigente' => $reasignacion->vigente, 'envases' => $reasignacion->envases,
            'origen' => ['cliente' => $reasignacion->clienteOrigen?->only(['id', 'codigo', 'nombre']), 'productor' => $reasignacion->productor_origen],
            'destino' => ['cliente' => $reasignacion->clienteDestino?->only(['id', 'codigo', 'nombre']), 'productor' => $reasignacion->productor_destino],
            'motivo' => $reasignacion->motivo, 'registrado_at' => $reasignacion->registrado_at?->toAtomString(),
        ];
    }

    public function catalogos(): JsonResponse
    {
        Gate::authorize('consultar-cuenta-envases');

        return response()->json([
            'clientes' => Cliente::query()->where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            'temporadas' => Temporada::query()->productivas()->orderByDesc('fecha_inicio')->get(['id', 'codigo', 'nombre', 'activa']),
            'tipos_envase' => TipoEnvaseRomana::catalogo(),
            'hoy' => now(config('app.operational_timezone'))->toDateString(),
            'puede_ajustar' => Gate::allows('ajustar-cuenta-envases'),
            'estados_revision' => array_map(
                fn (EstadoRevisionMovimientoEnvase $estado): string => $estado->value,
                EstadoRevisionMovimientoEnvase::cases(),
            ),
        ]);
    }

    public function index(
        Request $request,
        ServicioTemporadaActiva $temporadaActiva,
        ServicioCuentaCorrienteEnvases $cuenta,
    ): JsonResponse {
        Gate::authorize('consultar-cuenta-envases');
        $filtros = $this->filtros($request, $temporadaActiva);

        $consulta = MovimientoEnvase::query()
            ->withSum('correccionesCuenta as ajuste_cuenta_acumulado', DB::raw('CAST(cantidad AS SIGNED) * signo_cuenta'))
            ->withExists('correccionesInvalidantes')
            ->with(['cliente:id,codigo,nombre', 'temporada:id,codigo,nombre,activa', 'creadoPor:id,name', 'revisiones.usuario:id,name', 'recepcion:id,tipo_recepcion,estado']);
        $cuenta->aplicarFiltros($consulta, $filtros);
        $movimientos = $consulta->orderByDesc('ocurrido_at')->limit(300)->get();
        $corregidos = MovimientoEnvase::query()
            ->where('tipo_movimiento', TipoMovimientoEnvase::CorreccionPropiedad->value)
            ->whereIn('movimiento_origen_id', $movimientos->pluck('id'))
            ->pluck('movimiento_origen_id')->all();
        $cantidadesCorregidas = MovimientoEnvase::query()
            ->where('tipo_movimiento', TipoMovimientoEnvase::CorreccionCantidad->value)
            ->whereIn('movimiento_origen_id', $movimientos->pluck('id'))
            ->pluck('movimiento_origen_id')->all();
        $conGuias = DetalleGuiaDespachoEnvase::query()
            ->whereIn('movimiento_origen_id', $movimientos->pluck('id'))
            ->pluck('movimiento_origen_id')->all();
        $conMovimientos = MovimientoEnvase::query()
            ->whereIn('movimiento_origen_id', $movimientos->pluck('id'))
            ->pluck('movimiento_origen_id')->all();
        $guiasEmitidas = GuiaDespachoEnvase::query()
            ->whereKey($movimientos->where('documento_tipo', 'guia_despacho_envases')->pluck('documento_id')->filter()->unique()->values())
            ->withExists('archivoVigente')
            ->get(['id', 'numero_guia_despacho'])
            ->keyBy('id');

        $pendientes = DetalleEnvaseRecepcionRomana::query()
            ->with('recepcion')
            ->whereHas('recepcion', function (Builder $recepcion) use ($filtros, $cuenta): void {
                $recepcion->where('estado', '!=', EstadoRecepcionRomana::Anulada->value)
                    ->where('estado_validacion_mp', '!=', EstadoValidacionMp::Validada->value);
                if (! empty($filtros['cliente_id'])) {
                    $recepcion->where('cliente_id', $filtros['cliente_id']);
                }
                if (! empty($filtros['temporada_id'])) {
                    $recepcion->where('temporada_id', $filtros['temporada_id']);
                }
                $cuenta->filtrarDiasOperacionales($recepcion, 'ingreso_at', $filtros['desde'] ?? null, $filtros['hasta'] ?? null);
            })
            ->when(! empty($filtros['tipo_envase']), fn (Builder $q) => $q->where('tipo_envase', $filtros['tipo_envase']))
            ->get();

        $propiosEntregados = collect($cuenta->propiosEntregados($filtros['temporada_id'], $filtros['cliente_id'] ?? null))
            ->keyBy(fn (array $fila): string => $fila['cliente_id'].'|'.$fila['tipo_envase']);
        // Las compras de envases propios no mueven la cuenta de nadie (signo 0): se ven en Existencia.
        $balances = MovimientoEnvase::query()
            ->selectRaw('cliente_id, tipo_envase, SUM(CAST(cantidad AS SIGNED) * signo_cuenta) as saldo')
            ->where('temporada_id', $filtros['temporada_id'])
            ->where('signo_cuenta', '!=', 0)
            ->when(
                ! empty($filtros['cliente_id']),
                fn (Builder $consulta): Builder => $consulta->where('cliente_id', $filtros['cliente_id']),
            )
            ->when(
                ! empty($filtros['tipo_envase']),
                fn (Builder $consulta): Builder => $consulta->where('tipo_envase', $filtros['tipo_envase']),
            )
            ->groupBy('cliente_id', 'tipo_envase')
            ->with('cliente:id,codigo,nombre')
            ->get()
            ->map(fn (MovimientoEnvase $movimiento): array => [
                'cliente' => ['id' => $movimiento->cliente->id, 'codigo' => $movimiento->cliente->codigo, 'nombre' => $movimiento->cliente->nombre],
                'tipo_envase' => $movimiento->tipo_envase->value,
                'saldo' => (int) $movimiento->getAttribute('saldo'),
                'propios_entregados' => (int) ($propiosEntregados->get($movimiento->cliente_id.'|'.$movimiento->tipo_envase->value)['cantidad'] ?? 0),
            ])->values();

        $reservas = DetalleGuiaDespachoEnvase::query()
            ->with('guia.cliente:id,codigo,nombre')
            ->whereHas('guia', function (Builder $guia) use ($filtros, $cuenta): void {
                $cuenta->filtrarDiasOperacionales($guia, 'salida_at', $filtros['desde'] ?? null, $filtros['hasta'] ?? null);
                $guia->where('temporada_id', $filtros['temporada_id'])
                    ->where('estado', EstadoGuiaDespachoEnvase::Borrador->value)
                    ->when(
                        ! empty($filtros['cliente_id']),
                        fn (Builder $consulta): Builder => $consulta
                            ->where('cliente_id', $filtros['cliente_id']),
                    )
                    ->when(
                        ! empty($filtros['buscar']),
                        function (Builder $consulta) use ($filtros): void {
                            $buscar = '%'.str_replace(
                                ['%', '_'],
                                ['\\%', '\\_'],
                                trim($filtros['buscar']),
                            ).'%';
                            $consulta->where(function (Builder $filtro) use ($buscar): void {
                                $filtro->where('numero', 'like', $buscar)
                                    ->orWhereHas(
                                        'cliente',
                                        fn (Builder $cliente): Builder => $cliente
                                            ->where('nombre', 'like', $buscar),
                                    );
                            });
                        },
                    );
            })
            ->when(
                ! empty($filtros['tipo_envase']),
                fn (Builder $consulta): Builder => $consulta
                    ->where('tipo_envase', $filtros['tipo_envase']),
            )
            ->get()
            ->groupBy('guia_despacho_envase_id')
            ->map(function ($detalles): array {
                $guia = $detalles->first()->guia;

                return [
                    'guia_id' => $guia->id,
                    'numero' => $guia->numero,
                    'cliente' => [
                        'id' => $guia->cliente->id,
                        'codigo' => $guia->cliente->codigo,
                        'nombre' => $guia->cliente->nombre,
                    ],
                    'salida_at' => $guia->salida_at?->toAtomString(),
                    'cantidad_total' => (int) $detalles->sum('cantidad'),
                    'lineas' => $detalles
                        ->groupBy(fn ($detalle): string => $detalle->tipo_envase->value.'|'.$detalle->propiedad->value)
                        ->map(function ($lineas, string $clave): array {
                            [$tipo, $propiedad] = explode('|', $clave, 2);

                            return [
                                'tipo_envase' => $tipo,
                                'propiedad' => $propiedad,
                                'cantidad' => (int) $lineas->sum('cantidad'),
                            ];
                        })
                        ->values(),
                ];
            })
            ->values();

        // Reasignaciones entre productores de las guías de estos movimientos: solo trazabilidad interna del kardex.
        $reasignacionesProductor = ReasignacionProductorEnvases::query()
            ->where(fn (Builder $q) => $q->whereIn('recepcion_romana_id', $movimientos->pluck('recepcion_romana_id')->filter()->unique()->values())
                ->orWhereIn('guia_despacho_envase_id', $movimientos->where('documento_tipo', 'guia_despacho_envases')->pluck('documento_id')->filter()->unique()->values()))
            ->with('usuario:id,name')->orderBy('registrado_at')->get();

        return response()->json([
            'data' => $movimientos->map(function (MovimientoEnvase $movimiento) use ($corregidos, $cantidadesCorregidas, $conGuias, $conMovimientos, $guiasEmitidas, $reasignacionesProductor): array {
                $guia = $movimiento->documento_tipo === 'guia_despacho_envases'
                    ? $guiasEmitidas->get($movimiento->documento_id)
                    : null;

                return [
                    ...$this->movimiento(
                        $movimiento,
                        in_array($movimiento->id, $corregidos, true),
                        in_array($movimiento->id, $cantidadesCorregidas, true),
                        in_array($movimiento->id, $conGuias, true) || in_array($movimiento->id, $conMovimientos, true),
                    ),
                    // N° de la guía emitida en el sistema de facturación y si su archivo está subido.
                    'guia_emitida' => $guia ? [
                        'numero' => $guia->numero_guia_despacho,
                        'archivo' => (bool) $guia->archivo_vigente_exists,
                    ] : null,
                    'reasignaciones_productor' => $this->reasignacionesProductorDe($movimiento, $reasignacionesProductor),
                ];
            }),
            'pendientes' => $pendientes->map(fn (DetalleEnvaseRecepcionRomana $detalle): array => [
                'id' => $detalle->id,
                'estado' => 'pendiente_validacion',
                'numero_recepcion' => $detalle->recepcion->numero_recepcion,
                'numero_guia' => $detalle->recepcion->numero_guia_despacho,
                'cliente' => [
                    'id' => $detalle->recepcion->cliente_id,
                    'codigo' => $detalle->recepcion->cliente_codigo_snapshot,
                    'nombre' => $detalle->recepcion->cliente_nombre_snapshot,
                ],
                'temporada' => [
                    'id' => $detalle->recepcion->temporada_id,
                    'codigo' => $detalle->recepcion->temporada_codigo_snapshot,
                ],
                'tipo_recepcion' => $detalle->recepcion->tipo_recepcion->value,
                'tipo_envase' => $detalle->tipo_envase->value,
                'cantidad_declarada' => $detalle->cantidad_declarada,
                'ingreso_at' => $detalle->recepcion->ingreso_at?->toAtomString(),
            ])->values(),
            'balances' => $balances,
            'existencia' => $cuenta->existencia($filtros['temporada_id']),
            'reservas' => $reservas,
            'resumen' => [
                'movimientos_confirmados' => $movimientos->count(),
                'lineas_pendientes_validacion' => $pendientes->count(),
                'observados' => $movimientos->where('estado_revision', EstadoRevisionMovimientoEnvase::Observado)->count(),
                'guias_borrador' => $reservas->count(),
                'envases_reservados' => (int) $reservas->sum('cantidad_total'),
                'sincronizado_at' => now()->toAtomString(),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function revisar(Request $request, MovimientoEnvase $movimientoEnvase): JsonResponse
    {
        Gate::authorize('revisar-cuenta-envases');
        $datos = $request->validate([
            'estado' => ['required', Rule::in([
                EstadoRevisionMovimientoEnvase::Revisado->value,
                EstadoRevisionMovimientoEnvase::Observado->value,
            ])],
            'nota' => [
                'nullable',
                Rule::requiredIf($request->input('estado') === EstadoRevisionMovimientoEnvase::Observado->value),
                'string',
                'max:2000',
            ],
        ]);

        DB::transaction(function () use ($movimientoEnvase, $datos, $request): void {
            $movimiento = MovimientoEnvase::query()
                ->whereHas('temporada', fn (Builder $temporada): Builder => $temporada
                    ->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
                ->lockForUpdate()
                ->findOrFail($movimientoEnvase->id);
            $estado = EstadoRevisionMovimientoEnvase::from($datos['estado']);
            RevisionMovimientoEnvase::create([
                'movimiento_envase_id' => $movimiento->id,
                'estado' => $estado,
                'nota' => $datos['nota'] ?? null,
                'user_id' => $request->user()->id,
                'revisado_at' => now(),
            ]);
            $movimiento->update(['estado_revision' => $estado]);
        });

        return response()->json(['data' => $this->movimiento(
            $movimientoEnvase->refresh()->load(['cliente', 'temporada', 'creadoPor', 'revisiones.usuario']),
        )]);
    }

    public function corregirPropiedad(
        Request $request,
        MovimientoEnvase $movimientoEnvase,
        ServicioCorreccionPropiedadEnvases $servicio,
    ): JsonResponse {
        Gate::authorize('corregir-propiedad-envases');
        $esDevolucion = $request->input('concepto_envases') === ConceptoEnvasesRomana::Devolucion->value;
        $datos = $request->validate([
            'concepto_envases' => ['required', Rule::enum(ConceptoEnvasesRomana::class)],
            'motivo' => ['required', 'string', 'min:10', 'max:2000'],
            // Devolución: productor CSG, guía de salida devuelta, propiedad por tipo y, si cambia, fecha y hora del reingreso.
            'devolucion_productor_csg_id' => [Rule::requiredIf($esDevolucion), 'nullable', 'uuid'],
            'devolucion_guia_salida' => ['nullable', 'string', 'max:80'],
            'propiedades' => [Rule::requiredIf($esDevolucion), 'nullable', 'array'],
            'propiedades.*' => [Rule::enum(PropiedadEnvase::class)],
            'ingreso_at' => ['nullable', 'date_format:Y-m-d\\TH:i'],
        ], [
            'devolucion_productor_csg_id.required' => 'Selecciona el productor CSG que devolvió los envases.',
            'propiedades.required' => 'Indica la propiedad de cada envase devuelto.',
            'ingreso_at.date_format' => 'Indica la fecha y hora del reingreso.',
        ]);
        $servicio->corregir(
            $movimientoEnvase,
            ConceptoEnvasesRomana::from($datos['concepto_envases']),
            trim($datos['motivo']),
            $request->user(),
            $esDevolucion ? [
                'productor_csg_id' => $datos['devolucion_productor_csg_id'],
                'guia_salida' => $datos['devolucion_guia_salida'] ?? null,
                'propiedades' => $datos['propiedades'],
                'ingreso_at' => ! empty($datos['ingreso_at'])
                    ? CarbonImmutable::createFromFormat('Y-m-d\\TH:i', $datos['ingreso_at'], config('app.operational_timezone'))->utc()
                    : null,
            ] : [],
        );

        return response()->json(['message' => $esDevolucion
            ? 'Ingreso asociado a la devolución del productor. El ingreso original y sus asientos de ajuste siguen disponibles para auditoría.'
            : 'Propiedad corregida. El ingreso original y sus asientos de ajuste siguen disponibles para auditoría.']);
    }

    public function corregirCantidad(
        Request $request,
        MovimientoEnvase $movimientoEnvase,
        ServicioCorreccionCantidadEnvases $servicio,
    ): JsonResponse {
        Gate::authorize('corregir-cantidad-envases');
        $datos = $request->validate([
            'cantidad_correcta' => ['required', 'integer', 'min:1', 'max:100000'],
            'motivo' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $servicio->corregir($movimientoEnvase, (int) $datos['cantidad_correcta'], trim($datos['motivo']), $request->user());

        return response()->json(['message' => 'Cantidad corregida. El ingreso original y el ajuste permanecen visibles para auditoría.']);
    }

    /** Kardex de movimientos con los filtros de la oficina y saldo acumulado de la cuenta. */
    public function excel(
        Request $request,
        ServicioTemporadaActiva $temporadaActiva,
        ServicioCuentaCorrienteEnvases $cuenta,
        GeneradorLibroXlsx $excel,
    ): BinaryFileResponse {
        Gate::authorize('consultar-cuenta-envases');
        $filtros = $this->filtros($request, $temporadaActiva);
        $temporada = Temporada::query()->findOrFail($filtros['temporada_id']);
        $cliente = ! empty($filtros['cliente_id']) ? Cliente::query()->find($filtros['cliente_id']) : null;
        $columnas = [
            ['clave' => 'fecha_hora', 'titulo' => 'Fecha y hora', 'tipo' => 'fecha_hora'],
            ['clave' => 'cliente_codigo', 'titulo' => 'Código cliente'],
            ['clave' => 'cliente', 'titulo' => 'Cliente'],
            ['clave' => 'documento', 'titulo' => 'Documento'],
            ['clave' => 'guia_emitida', 'titulo' => 'Guía de despacho emitida'],
            ['clave' => 'movimiento', 'titulo' => 'Movimiento'],
            ['clave' => 'tipo_envase', 'titulo' => 'Envase'],
            ['clave' => 'propiedad', 'titulo' => 'Propiedad'],
            ['clave' => 'cantidad', 'titulo' => 'Cantidad', 'tipo' => 'numero'],
            ['clave' => 'impacto_cuenta', 'titulo' => 'Efecto en cuenta', 'tipo' => 'numero'],
            ['clave' => 'saldo_cuenta', 'titulo' => 'Saldo de cuenta', 'tipo' => 'numero'],
            ['clave' => 'impacto_existencia', 'titulo' => 'Efecto en existencia', 'tipo' => 'numero'],
            ['clave' => 'revision', 'titulo' => 'Revisión'],
            ['clave' => 'revisado_por', 'titulo' => 'Revisado por'],
            ['clave' => 'nota_revision', 'titulo' => 'Nota de revisión'],
            ['clave' => 'registrado_por', 'titulo' => 'Registrado por'],
            ['clave' => 'motivo_correccion', 'titulo' => 'Motivo de corrección'],
        ];
        $ruta = $excel->generar('Cuenta corriente de envases', $columnas, $cuenta->kardex($filtros), [
            'fecha_corte' => now(config('app.operational_timezone'))->format('d-m-Y H:i'),
            'usuario' => $request->user()->name,
            'temporada' => $temporada->codigo.' · '.$temporada->nombre,
            'cliente' => $cliente ? $cliente->codigo.' · '.$cliente->nombre : null,
            'periodo' => $this->periodo($filtros),
        ], 'Movimientos');

        return response()->download($ruta, 'cuenta-envases-'.$temporada->codigo.'.xlsx')->deleteFileAfterSend();
    }

    /** Estado de cuenta de un cliente para enviarle, en PDF o Excel. */
    public function estadoCuenta(
        Request $request,
        ServicioTemporadaActiva $temporadaActiva,
        ServicioCuentaCorrienteEnvases $cuenta,
        GeneradorEstadoCuentaEnvasesPdf $pdf,
        GeneradorCuentaEnvasesProductorXlsx $excel,
    ): Response|BinaryFileResponse {
        Gate::authorize('consultar-cuenta-envases');
        $datos = $request->validate([
            'cliente_id' => ['required', 'uuid', 'exists:clientes,id'],
            'formato' => ['nullable', Rule::in(['pdf', 'xlsx'])],
            'productor' => ['nullable', 'string', 'max:140'],
            // Solo los envases elegidos (por ejemplo, solo esponjas o bins y totes); sin indicar, todos.
            'tipos_envase' => ['nullable', 'array', 'max:'.count(TipoEnvaseRomana::cases())],
            'tipos_envase.*' => ['distinct', Rule::enum(TipoEnvaseRomana::class)],
        ]);
        $filtros = $this->filtros($request, $temporadaActiva);
        $cliente = Cliente::query()->findOrFail($filtros['cliente_id']);
        $temporada = Temporada::query()->findOrFail($filtros['temporada_id']);
        $tipos = collect($datos['tipos_envase'] ?? [])->sortBy(fn (string $t): int => TipoEnvaseRomana::from($t)->orden())->values()->all();
        $todos = $tipos === [] || count($tipos) === count(TipoEnvaseRomana::cases());
        $nombre = 'estado-cuenta-envases-'.Str::slug($cliente->codigo ?: $cliente->nombre).'-'.$temporada->codigo
            .($todos ? '' : '-'.implode('-', array_map(fn (string $t): string => Str::slug($t), $tipos)));

        if (($datos['formato'] ?? null) === 'xlsx') {
            $ruta = $excel->generar($cuenta->operacionesPorProductor($cliente, $temporada, [
                ...$filtros, 'productor' => $datos['productor'] ?? null, 'tipos_envase' => $todos ? null : $tipos,
            ]));

            return response()->download($ruta, $nombre.'.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store, private',
            ])->deleteFileAfterSend();
        }

        $estado = $cuenta->estadoCuenta($cliente, $temporada, $filtros['desde'] ?? null, $filtros['hasta'] ?? null, $todos ? null : $tipos);

        return response($pdf->generar($estado), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$nombre.'.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** @return array<string, mixed> */
    private function filtros(Request $request, ServicioTemporadaActiva $temporadaActiva): array
    {
        $filtros = $request->validate([
            'cliente_id' => ['nullable', 'uuid', 'exists:clientes,id'],
            'temporada_id' => ['nullable', 'uuid', 'exists:temporadas,id'],
            'tipo_envase' => ['nullable', Rule::enum(TipoEnvaseRomana::class)],
            'estado_revision' => ['nullable', Rule::enum(EstadoRevisionMovimientoEnvase::class)],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'buscar' => ['nullable', 'string', 'max:100'],
        ]);
        $filtros['temporada_id'] ??= $temporadaActiva->obtener()->id;

        return $filtros;
    }

    /** @param array<string, mixed> $filtros */
    private function periodo(array $filtros): ?string
    {
        $dia = fn (string $fecha): string => CarbonImmutable::parse($fecha)->format('d-m-Y');

        return match (true) {
            ! empty($filtros['desde']) && ! empty($filtros['hasta']) => $dia($filtros['desde']).' a '.$dia($filtros['hasta']),
            ! empty($filtros['desde']) => 'Desde '.$dia($filtros['desde']),
            ! empty($filtros['hasta']) => 'Hasta '.$dia($filtros['hasta']),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function movimiento(MovimientoEnvase $movimiento, bool $corregido = false, bool $cantidadCorregida = false, bool $conVinculos = false): array
    {
        return [
            'id' => $movimiento->id,
            'tipo_movimiento' => $movimiento->tipo_movimiento->value,
            'movimiento' => ServicioCuentaCorrienteEnvases::etiquetaMovimiento($movimiento->tipo_movimiento),
            'tipo_envase' => $movimiento->tipo_envase->value,
            'cantidad' => $movimiento->cantidad,
            'impacto_cuenta' => $movimiento->cantidad * $movimiento->signo_cuenta,
            'impacto_existencia' => $movimiento->cantidad * $movimiento->signo_existencia,
            'propiedad' => $movimiento->propiedad->value,
            'corregido' => $corregido,
            'cantidad_corregida' => $cantidadCorregida,
            'puede_corregir_cantidad' => $corregido === false && $cantidadCorregida === false && $conVinculos === false
                && $movimiento->temporada?->activa
                && $movimiento->recepcion?->tipo_recepcion === TipoRecepcionRomana::SoloEnvases
                && in_array($movimiento->tipo_movimiento, TipoMovimientoEnvase::ingresosSoloEnvases(), true)
                && empty($movimiento->datos['correccion_propiedad']),
            // También asocia el ingreso a la devolución de un productor CSG, o cambia esa asociación.
            'puede_corregir_propiedad' => $corregido === false && $movimiento->recepcion?->estado !== EstadoRecepcionRomana::Anulada
                && $movimiento->temporada?->activa
                && $movimiento->recepcion_romana_id !== null
                && in_array($movimiento->tipo_movimiento, TipoMovimientoEnvase::ingresosSoloEnvases(), true)
                && empty($movimiento->datos['correccion_propiedad']),
            'devolucion' => $movimiento->datos['devolucion'] ?? null,
            'recepcion_romana_id' => $movimiento->recepcion_romana_id,
            'cuenta_corregida' => $movimiento->ajuste_cuenta_acumulado !== null,
            'correccion_cuenta' => $movimiento->datos['correccion_cuenta'] ?? $movimiento->datos['anulacion_recepcion'] ?? null,
            'impacto_cuenta_vigente' => $movimiento->cantidad * $movimiento->signo_cuenta + (int) $movimiento->ajuste_cuenta_acumulado,
            'puede_corregir_saldo_historico' => $movimiento->temporada?->activa && $movimiento->movimiento_origen_id === null
                && $movimiento->recepcion?->estado !== EstadoRecepcionRomana::Anulada
                && in_array($movimiento->tipo_movimiento, [TipoMovimientoEnvase::RecepcionArriendo, TipoMovimientoEnvase::AperturaCuenta, TipoMovimientoEnvase::AjusteCuenta], true)
                && ! $movimiento->correcciones_invalidantes_exists && empty($movimiento->datos['correccion_propiedad'])
                && empty($movimiento->datos['ajuste_recepcion']),
            'correccion_propiedad' => $movimiento->datos['correccion_propiedad'] ?? null,
            'correccion_cantidad' => $movimiento->datos['correccion_cantidad'] ?? null,
            'ajuste_cuenta' => $movimiento->datos['ajuste_cuenta'] ?? null,
            // Ajuste de administración hecho desde Romana sobre una recepción: lado, lo registrado y lo correcto.
            'ajuste_recepcion' => $movimiento->datos['ajuste_recepcion'] ?? null,
            'movimiento_origen_id' => $movimiento->movimiento_origen_id,
            // Guías que se pueden mover a otro productor o cuenta; el diálogo explica si hoy no se puede.
            'puede_reasignar' => $movimiento->temporada?->activa && (in_array($movimiento->tipo_movimiento, [
                TipoMovimientoEnvase::RecepcionFruta, TipoMovimientoEnvase::RecepcionArriendo, TipoMovimientoEnvase::DevolucionEnvases,
                TipoMovimientoEnvase::SalidaRecepcionFruta, TipoMovimientoEnvase::DespachoCliente, TipoMovimientoEnvase::ReasignacionEntrada,
            ], true) || ! empty($movimiento->datos['ajuste_recepcion']['lado'])),
            // Ingresos y salidas de una guía que se pueden repartir entre productores de la cuenta.
            'puede_reasignar_productor' => (bool) $movimiento->temporada?->activa && ServicioReasignacionProductorEnvases::reasignable($movimiento),
            'reasignacion' => isset($movimiento->datos['reasignacion']) ? collect($movimiento->datos['reasignacion'])
                ->only(['id', 'documento_clave', 'deshace', 'motivo', 'usuario', 'registrado_at', 'desde_cuenta', 'hacia_cuenta', 'productor'])->all() : null,
            'puede_revertir_ajuste' => ! $conVinculos && $movimiento->temporada?->activa
                && in_array($movimiento->tipo_movimiento, [TipoMovimientoEnvase::AperturaCuenta, TipoMovimientoEnvase::AjusteCuenta], true),
            'documento_tipo' => $movimiento->documento_tipo,
            'documento_id' => $movimiento->documento_id,
            'numero_documento' => $movimiento->numero_documento,
            'cliente' => $movimiento->cliente ? ['id' => $movimiento->cliente->id, 'codigo' => $movimiento->cliente->codigo, 'nombre' => $movimiento->cliente->nombre] : null,
            'temporada' => $movimiento->temporada ? ['id' => $movimiento->temporada->id, 'codigo' => $movimiento->temporada->codigo, 'nombre' => $movimiento->temporada->nombre] : null,
            'ocurrido_at' => $movimiento->ocurrido_at?->toAtomString(),
            'ingreso_at' => $movimiento->ingreso_at?->toAtomString(),
            'salida_at' => $movimiento->salida_at?->toAtomString(),
            'estado_revision' => $movimiento->estado_revision->value,
            'creado_por' => $movimiento->creadoPor ? ['id' => $movimiento->creadoPor->id, 'nombre' => $movimiento->creadoPor->name] : null,
            'revisiones' => $movimiento->revisiones->sortByDesc('revisado_at')->map(fn (RevisionMovimientoEnvase $revision): array => [
                'estado' => $revision->estado->value,
                'nota' => $revision->nota,
                'revisado_at' => $revision->revisado_at?->toAtomString(),
                'usuario' => $revision->usuario ? ['id' => $revision->usuario->id, 'nombre' => $revision->usuario->name] : null,
            ])->values(),
        ];
    }
}

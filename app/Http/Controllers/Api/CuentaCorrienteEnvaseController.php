<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConceptoEnvasesRomana;
use App\Enums\EstadoGuiaDespachoEnvase;
use App\Enums\EstadoRevisionMovimientoEnvase;
use App\Enums\EstadoValidacionMp;
use App\Enums\TipoEnvaseRomana;
use App\Enums\TipoMovimientoEnvase;
use App\Enums\TipoRecepcionRomana;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\DetalleEnvaseRecepcionRomana;
use App\Models\DetalleGuiaDespachoEnvase;
use App\Models\GuiaDespachoEnvase;
use App\Models\MovimientoEnvase;
use App\Models\RevisionMovimientoEnvase;
use App\Models\Temporada;
use App\Services\Envases\GeneradorEstadoCuentaEnvasesPdf;
use App\Services\Envases\ServicioCorreccionCantidadEnvases;
use App\Services\Envases\ServicioCorreccionPropiedadEnvases;
use App\Services\Envases\ServicioCuentaCorrienteEnvases;
use App\Services\Existencias\GeneradorLibroXlsx;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CuentaCorrienteEnvaseController extends Controller
{
    public function catalogos(): JsonResponse
    {
        Gate::authorize('consultar-cuenta-envases');

        return response()->json([
            'clientes' => Cliente::query()->where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            'temporadas' => Temporada::query()->productivas()->orderByDesc('fecha_inicio')->get(['id', 'codigo', 'nombre', 'activa']),
            'tipos_envase' => TipoEnvaseRomana::catalogo(),
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
            ->with(['cliente:id,codigo,nombre', 'temporada:id,codigo,nombre,activa', 'creadoPor:id,name', 'revisiones.usuario:id,name', 'recepcion:id,tipo_recepcion']);
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
                $recepcion->where('estado_validacion_mp', '!=', EstadoValidacionMp::Validada->value);
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

        return response()->json([
            'data' => $movimientos->map(function (MovimientoEnvase $movimiento) use ($corregidos, $cantidadesCorregidas, $conGuias, $conMovimientos, $guiasEmitidas): array {
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
        $datos = $request->validate([
            'concepto_envases' => ['required', Rule::enum(ConceptoEnvasesRomana::class)],
            'motivo' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $servicio->corregir(
            $movimientoEnvase,
            ConceptoEnvasesRomana::from($datos['concepto_envases']),
            trim($datos['motivo']),
            $request->user(),
        );

        return response()->json(['message' => 'Propiedad corregida. El ingreso original y sus asientos de ajuste siguen disponibles para auditoría.']);
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
        GeneradorLibroXlsx $excel,
    ): Response|BinaryFileResponse {
        Gate::authorize('consultar-cuenta-envases');
        $request->validate([
            'cliente_id' => ['required', 'uuid', 'exists:clientes,id'],
            'formato' => ['nullable', Rule::in(['pdf', 'xlsx'])],
        ]);
        $filtros = $this->filtros($request, $temporadaActiva);
        $cliente = Cliente::query()->findOrFail($filtros['cliente_id']);
        $temporada = Temporada::query()->findOrFail($filtros['temporada_id']);
        $estado = $cuenta->estadoCuenta($cliente, $temporada, $filtros['desde'] ?? null, $filtros['hasta'] ?? null);
        $nombre = 'estado-cuenta-envases-'.Str::slug($cliente->codigo ?: $cliente->nombre).'-'.$temporada->codigo;

        if ($request->input('formato') === 'xlsx') {
            $ruta = $excel->generar('Estado de cuenta de envases', [
                ['clave' => 'fecha', 'titulo' => 'Fecha y hora', 'tipo' => 'fecha_hora'],
                ['clave' => 'documento', 'titulo' => 'Guía N°'],
                ['clave' => 'movimiento', 'titulo' => 'Movimiento'],
                ['clave' => 'tipo_envase', 'titulo' => 'Envase'],
                ['clave' => 'propiedad', 'titulo' => 'Propiedad'],
                ['clave' => 'entrada', 'titulo' => 'Recibidos', 'tipo' => 'numero'],
                ['clave' => 'salida', 'titulo' => 'Entregados', 'tipo' => 'numero'],
                ['clave' => 'saldo', 'titulo' => 'Saldo', 'tipo' => 'numero'],
            ], $estado['movimientos'], [
                'fecha_corte' => now(config('app.operational_timezone'))->format('d-m-Y H:i'),
                'usuario' => $request->user()->name,
                'temporada' => $temporada->codigo.' · '.$temporada->nombre,
                'cliente' => $cliente->codigo.' · '.$cliente->nombre,
                'periodo' => $this->periodo($filtros) ?? 'Toda la temporada',
            ], 'Estado de cuenta');

            return response()->download($ruta, $nombre.'.xlsx')->deleteFileAfterSend();
        }

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
                && in_array($movimiento->tipo_movimiento, [TipoMovimientoEnvase::RecepcionCompra, TipoMovimientoEnvase::RecepcionArriendo], true)
                && empty($movimiento->datos['correccion_propiedad']),
            'puede_corregir_propiedad' => $corregido === false
                && $movimiento->temporada?->activa
                && $movimiento->recepcion_romana_id !== null
                && in_array($movimiento->tipo_movimiento, [TipoMovimientoEnvase::RecepcionCompra, TipoMovimientoEnvase::RecepcionArriendo], true)
                && empty($movimiento->datos['correccion_propiedad']),
            'correccion_propiedad' => $movimiento->datos['correccion_propiedad'] ?? null,
            'correccion_cantidad' => $movimiento->datos['correccion_cantidad'] ?? null,
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

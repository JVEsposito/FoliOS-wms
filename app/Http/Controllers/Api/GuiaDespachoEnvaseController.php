<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoGuiaDespachoEnvase;
use App\Enums\PropiedadEnvase;
use App\Enums\TipoEnvaseRomana;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\GuiaDespachoEnvase;
use App\Services\Envases\GeneradorGuiaDespachoEnvasesPdf;
use App\Services\Envases\ServicioGuiaDespachoEnvases;
use App\Services\Envases\ServicioGuiaEmitidaEnvases;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GuiaDespachoEnvaseController extends Controller
{
    public function catalogos(
        ServicioTemporadaActiva $temporadaActiva,
        ServicioGuiaDespachoEnvases $servicio,
    ): JsonResponse {
        Gate::authorize('consultar-cuenta-envases');
        $temporada = $temporadaActiva->obtener();
        $inventario = $servicio->inventario($temporada);

        return response()->json([
            'temporada' => [
                'id' => $temporada->id,
                'codigo' => $temporada->codigo,
                'nombre' => $temporada->nombre,
            ],
            'clientes' => Cliente::query()
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(['id', 'codigo', 'nombre']),
            'tipos_envase' => array_column(TipoEnvaseRomana::cases(), 'value'),
            'propiedades' => array_column(PropiedadEnvase::cases(), 'value'),
            'origenes' => $inventario['origenes'],
            'inventario' => $inventario['resumen'],
            'disponibilidad' => $inventario['disponibilidad'],
            'incidencias' => $inventario['incidencias'],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function productores(Cliente $cliente): JsonResponse
    {
        Gate::authorize('consultar-cuenta-envases');
        $productores = $cliente->productoresCsg()->wherePivot('activo', true)
            ->orderBy('razon_social')->orderBy('codigo')->get(['productores_csg.id', 'codigo', 'razon_social']);

        return response()->json(['data' => $productores->map(fn ($productor): array => [
            'id' => $productor->id,
            'codigo' => $productor->codigo,
            'nombre' => $productor->razon_social,
        ])])->header('Cache-Control', 'no-store, private');
    }

    public function index(
        Request $request,
        ServicioTemporadaActiva $temporadaActiva,
    ): JsonResponse {
        Gate::authorize('consultar-cuenta-envases');
        $filtros = $request->validate([
            'temporada_id' => ['nullable', 'uuid', 'exists:temporadas,id'],
            'estado' => ['nullable', Rule::enum(EstadoGuiaDespachoEnvase::class)],
            'buscar' => ['nullable', 'string', 'max:100'],
            'cliente_id' => ['nullable', 'uuid', 'exists:clientes,id'],
            'csg' => ['nullable', Rule::in(['con', 'sin'])],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('desde'), 'after_or_equal:desde')],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $temporadaId = $filtros['temporada_id'] ?? $temporadaActiva->obtener()->id;
        $zona = config('app.operational_timezone');
        $guias = GuiaDespachoEnvase::query()
            ->with([
                'cliente',
                'temporada',
                'detalles.movimientoOrigen.cliente',
                'archivoVigente.subidoPor',
                'conciliacionesSalida',
                'creadoPor',
                'confirmadoPor',
                'canceladoPor',
                'anuladoPor',
            ])
            ->where('temporada_id', $temporadaId)
            ->when(
                ! empty($filtros['estado']),
                fn (Builder $consulta) => $consulta->where('estado', $filtros['estado']),
            )
            ->when(! empty($filtros['cliente_id']), fn (Builder $consulta) => $consulta->where('cliente_id', $filtros['cliente_id']))
            ->when(($filtros['csg'] ?? null) === 'con', fn (Builder $consulta) => $consulta->whereNotNull('productor_csg_id'))
            ->when(($filtros['csg'] ?? null) === 'sin', fn (Builder $consulta) => $consulta->whereNull('productor_csg_id'))
            ->when(! empty($filtros['desde']), fn (Builder $consulta) => $consulta->where('salida_at', '>=', CarbonImmutable::parse($filtros['desde'], $zona)->startOfDay()->utc()))
            ->when(! empty($filtros['hasta']), fn (Builder $consulta) => $consulta->where('salida_at', '<', CarbonImmutable::parse($filtros['hasta'], $zona)->addDay()->startOfDay()->utc()))
            ->when(filled($filtros['buscar'] ?? null), function (Builder $consulta) use ($filtros): void {
                $texto = trim($filtros['buscar']);
                $busqueda = '%'.addcslashes($texto, '\\%_').'%';
                $patente = preg_replace('/[\s-]+/', '', mb_strtoupper($texto));
                $consulta->where(function (Builder $buscador) use ($busqueda, $patente): void {
                    foreach (['numero', 'numero_guia_despacho', 'cliente_codigo_snapshot', 'cliente_nombre_snapshot', 'patente_camion'] as $campo) {
                        $buscador->orWhere($campo, 'like', $busqueda);
                    }
                    foreach (['codigo', 'nombre'] as $campo) {
                        $columna = $buscador->getQuery()->getGrammar()->wrap('productor_snapshot->'.$campo);
                        $buscador->orWhereRaw('LOWER('.$columna.') like ?', [mb_strtolower($busqueda)]);
                    }
                    if (preg_match('/^[A-Z0-9]{5,8}$/', $patente)) {
                        $buscador->orWhere('patente_camion', $patente);
                    }
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filtros['per_page'] ?? 25);

        return response()->json([
            'data' => $guias->getCollection()->map(fn (GuiaDespachoEnvase $guia): array => $this->guia($guia)),
            'meta' => [
                'current_page' => $guias->currentPage(),
                'last_page' => $guias->lastPage(),
                'per_page' => $guias->perPage(),
                'total' => $guias->total(),
                'from' => $guias->firstItem(),
                'to' => $guias->lastItem(),
            ],
            'resumen' => ['borradores' => GuiaDespachoEnvase::where('temporada_id', $temporadaId)->where('estado', EstadoGuiaDespachoEnvase::Borrador)->count()],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function show(GuiaDespachoEnvase $guiaDespachoEnvase): JsonResponse
    {
        Gate::authorize('consultar-cuenta-envases');
        $guia = $guiaDespachoEnvase->load([
            'cliente',
            'temporada',
            'detalles.movimientoOrigen.cliente',
            'archivoVigente.subidoPor',
            'conciliacionesSalida',
            'creadoPor',
            'confirmadoPor',
            'canceladoPor',
            'anuladoPor',
            'eventos.usuario',
        ]);

        return response()->json(['data' => $this->guia($guia, true)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, ServicioGuiaDespachoEnvases $servicio): JsonResponse
    {
        Gate::authorize('gestionar-despacho-envases');
        $datos = $this->validarGuia($request, creando: true);
        $guia = $servicio->crear($datos, $request->user());

        return response()->json(['data' => $this->guia($guia)], Response::HTTP_CREATED);
    }

    public function update(
        GuiaDespachoEnvase $guiaDespachoEnvase,
        Request $request,
        ServicioGuiaDespachoEnvases $servicio,
    ): JsonResponse {
        Gate::authorize('gestionar-despacho-envases');
        $datos = $this->validarGuia($request, creando: false);

        return response()->json([
            'data' => $this->guia($servicio->actualizar(
                $guiaDespachoEnvase,
                $datos,
                $request->user(),
            )),
        ]);
    }

    public function confirmar(
        GuiaDespachoEnvase $guiaDespachoEnvase,
        Request $request,
        ServicioGuiaDespachoEnvases $servicio,
    ): JsonResponse {
        Gate::authorize('gestionar-despacho-envases');
        $datos = $request->validate([
            'version' => ['nullable', 'integer', 'min:1'],
            'salida_at' => ['nullable', 'date'],
            'numero_guia_despacho' => ['required', 'string', 'max:30'],
        ], [
            'numero_guia_despacho.required' => 'Ingresa el N° de la guía de despacho emitida en el sistema de facturación.',
        ]);

        return response()->json([
            'data' => $this->guia($servicio->confirmar(
                $guiaDespachoEnvase,
                $request->user(),
                $datos,
            )),
        ]);
    }

    public function cancelar(
        GuiaDespachoEnvase $guiaDespachoEnvase,
        Request $request,
        ServicioGuiaDespachoEnvases $servicio,
    ): JsonResponse {
        Gate::authorize('gestionar-despacho-envases');
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:1000']]);

        return response()->json([
            'data' => $this->guia($servicio->cancelar(
                $guiaDespachoEnvase,
                $datos['motivo'],
                $request->user(),
            )),
        ]);
    }

    public function anular(
        GuiaDespachoEnvase $guiaDespachoEnvase,
        Request $request,
        ServicioGuiaDespachoEnvases $servicio,
    ): JsonResponse {
        Gate::authorize('anular-despacho-envases');
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:1000']]);

        return response()->json([
            'data' => $this->guia($servicio->anular(
                $guiaDespachoEnvase,
                $datos['motivo'],
                $request->user(),
            )),
        ]);
    }

    /** Registra el N° de guía emitida en una salida ya confirmada, o lo corrige con motivo. */
    public function numeroGuia(
        GuiaDespachoEnvase $guiaDespachoEnvase,
        Request $request,
        ServicioGuiaEmitidaEnvases $guiasEmitidas,
    ): JsonResponse {
        Gate::authorize('gestionar-despacho-envases');
        $datos = $request->validate([
            'numero_guia_despacho' => ['required', 'string', 'max:30'],
            'motivo' => ['nullable', 'string', 'max:1000'],
        ]);
        $guia = $guiasEmitidas->registrarNumero(
            $guiaDespachoEnvase,
            $datos['numero_guia_despacho'],
            $datos['motivo'] ?? null,
            $request->user(),
        );

        return response()->json(['data' => $this->guia($this->cargar($guia))]);
    }

    public function corregirProductor(
        GuiaDespachoEnvase $guiaDespachoEnvase,
        Request $request,
        ServicioGuiaDespachoEnvases $servicio,
    ): JsonResponse {
        if (! Gate::allows('corregir-productor-despacho-envases')) {
            Gate::authorize('gestionar-despacho-envases');
        }
        $datos = $request->validate([
            'productor_csg_id' => ['required', 'uuid'],
            'motivo' => ['required', 'string', 'max:1000'],
            'version' => ['required', 'integer', 'min:1'],
        ], [
            'productor_csg_id.required' => 'Selecciona un productor CSG.',
            'productor_csg_id.uuid' => 'Selecciona un productor CSG válido.',
            'motivo.required' => 'Ingresa el motivo de la corrección.',
        ]);

        return response()->json(['data' => $this->guia($servicio->corregirProductor(
            $guiaDespachoEnvase,
            $datos['productor_csg_id'],
            $datos['motivo'],
            (int) $datos['version'],
            $request->user(),
        ))]);
    }

    public function subirArchivoGuia(
        GuiaDespachoEnvase $guiaDespachoEnvase,
        Request $request,
        ServicioGuiaEmitidaEnvases $guiasEmitidas,
    ): JsonResponse {
        Gate::authorize('gestionar-despacho-envases');
        $request->validate([
            'archivo' => [
                'required',
                'file',
                'max:'.intdiv(ServicioGuiaEmitidaEnvases::BYTES_MAXIMOS, 1024),
                'mimetypes:application/pdf,image/jpeg,image/png,image/webp',
            ],
        ], [
            'archivo.max' => 'El archivo de la guía puede pesar hasta 10 MB.',
            'archivo.mimetypes' => 'Sube la guía en PDF, JPG, PNG o WebP.',
        ]);
        $guiasEmitidas->subirArchivo($guiaDespachoEnvase, $request->file('archivo'), $request->user());

        return response()->json(['data' => $this->guia($this->cargar($guiaDespachoEnvase))], Response::HTTP_CREATED);
    }

    public function archivoGuia(
        GuiaDespachoEnvase $guiaDespachoEnvase,
        ServicioGuiaEmitidaEnvases $guiasEmitidas,
    ): StreamedResponse {
        Gate::authorize('consultar-cuenta-envases');

        return $guiasEmitidas->descargarArchivo($guiaDespachoEnvase);
    }

    public function documento(
        GuiaDespachoEnvase $guiaDespachoEnvase,
        GeneradorGuiaDespachoEnvasesPdf $generador,
    ): Response {
        Gate::authorize('consultar-cuenta-envases');
        $pdf = $generador->generar($guiaDespachoEnvase);
        $nombre = 'guia-envases-'.strtolower($guiaDespachoEnvase->numero).'.pdf';

        return response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function comprobanteAnulacion(
        GuiaDespachoEnvase $guiaDespachoEnvase,
        GeneradorGuiaDespachoEnvasesPdf $generador,
    ): Response {
        Gate::authorize('consultar-cuenta-envases');
        $pdf = $generador->generarComprobanteAnulacion($guiaDespachoEnvase);
        $nombre = 'anulacion-'.strtolower($guiaDespachoEnvase->numero).'.pdf';

        return response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function cargar(GuiaDespachoEnvase $guia): GuiaDespachoEnvase
    {
        return $guia->refresh()->load([
            'temporada',
            'cliente',
            'detalles.movimientoOrigen.cliente',
            'archivoVigente.subidoPor',
            'conciliacionesSalida',
            'creadoPor',
            'confirmadoPor',
            'canceladoPor',
            'anuladoPor',
        ]);
    }

    /** @return array<string, mixed> */
    private function validarGuia(Request $request, bool $creando): array
    {
        return $request->validate([
            'operacion_id' => [$creando ? 'required' : 'sometimes', 'uuid'],
            'version' => [$creando ? 'sometimes' : 'required', 'integer', 'min:1'],
            'cliente_id' => [
                'required',
                'uuid',
                Rule::exists('clientes', 'id')->where('activo', true),
            ],
            'salida_at' => ['required', 'date'],
            'productor_csg_id' => ['nullable', 'uuid'],
            'patente_camion' => ['nullable', 'regex:/^[A-Z0-9]{5,8}$/'],
            'rut_conductor' => ['nullable', 'string', 'max:12'],
            'nombre_conductor' => ['nullable', 'string', 'max:150'],
            'observacion' => ['nullable', 'string', 'max:2000'],
            'detalles' => ['required', 'array', 'min:1', 'max:30'],
            'detalles.*.tipo_envase' => ['required', Rule::enum(TipoEnvaseRomana::class)],
            'detalles.*.cantidad' => ['required', 'integer', 'min:1', 'max:100000'],
            'detalles.*.propiedad' => ['required', Rule::enum(PropiedadEnvase::class)],
            'detalles.*.movimiento_origen_id' => [
                'nullable',
                'uuid',
                'exists:movimientos_envases,id',
            ],
        ], [
            'operacion_id.required' => 'No se pudo identificar la operación. Vuelve a intentar guardar el borrador.',
            'operacion_id.uuid' => 'No se pudo identificar la operación. Vuelve a intentar guardar el borrador.',
            'version.required' => 'Vuelve a abrir el borrador para editar su versión actual.',
            'version.integer' => 'Vuelve a abrir el borrador para editar su versión actual.',
            'version.min' => 'Vuelve a abrir el borrador para editar su versión actual.',
            'cliente_id.required' => 'Cliente destino: selecciona un cliente de la lista.',
            'cliente_id.uuid' => 'Cliente destino: selecciona un cliente válido de la lista.',
            'cliente_id.exists' => 'Cliente destino: selecciona un cliente activo de la lista.',
            'productor_csg_id.uuid' => 'Selecciona un productor CSG válido.',
            'salida_at.required' => 'Fecha y hora efectiva de salida: completa la fecha y la hora.',
            'salida_at.date' => 'Fecha y hora efectiva de salida: ingresa una fecha y hora válidas.',
            'patente_camion.regex' => 'Patente: ingresa entre 5 y 8 letras mayúsculas o números, sin espacios ni guiones. Ejemplo: ABCD12.',
            'rut_conductor.string' => 'RUT conductor: ingresa el RUT como texto.',
            'rut_conductor.max' => 'RUT conductor: usa como máximo :max caracteres.',
            'nombre_conductor.string' => 'Conductor: ingresa el nombre como texto.',
            'nombre_conductor.max' => 'Conductor: usa como máximo :max caracteres.',
            'observacion.string' => 'Observación: ingresa la observación como texto.',
            'observacion.max' => 'Observación: usa como máximo :max caracteres.',
            'detalles.required' => 'Envases: ingresa al menos una cantidad mayor que cero.',
            'detalles.array' => 'Envases: revisa las líneas del despacho y vuelve a guardar el borrador.',
            'detalles.min' => 'Envases: ingresa al menos una cantidad mayor que cero.',
            'detalles.max' => 'Envases: puedes incluir como máximo :max líneas en el despacho.',
            'detalles.*.tipo_envase.required' => 'Tipo de envase: selecciona el tipo que vas a despachar.',
            'detalles.*.tipo_envase.enum' => 'Tipo de envase: selecciona un tipo válido del catálogo.',
            'detalles.*.cantidad.required' => 'Cantidad de envases: completa la cantidad que vas a despachar.',
            'detalles.*.cantidad.integer' => 'Cantidad de envases: ingresa un número entero, sin decimales.',
            'detalles.*.cantidad.min' => 'Cantidad de envases: ingresa un número mayor que cero.',
            'detalles.*.cantidad.max' => 'Cantidad de envases: puedes despachar como máximo :max unidades por línea.',
            'detalles.*.propiedad.required' => 'Propiedad del envase: selecciona Propia, Arrendada o Del cliente.',
            'detalles.*.propiedad.enum' => 'Propiedad del envase: selecciona Propia, Arrendada o Del cliente.',
            'detalles.*.movimiento_origen_id.uuid' => 'Movimiento de origen: selecciona un origen válido o la asignación automática FIFO.',
            'detalles.*.movimiento_origen_id.exists' => 'Movimiento de origen: el origen seleccionado ya no existe. Selecciona otro origen o la asignación automática FIFO.',
        ]);
    }

    /** @return array<string, mixed> */
    private function guia(GuiaDespachoEnvase $guia, bool $conEventos = false): array
    {
        $detalles = $guia->detalles->map(fn ($detalle): array => [
            'id' => $detalle->id,
            'tipo_envase' => $detalle->tipo_envase->value,
            'cantidad' => $detalle->cantidad,
            'propiedad' => $detalle->propiedad->value,
            'movimiento_origen_id' => $detalle->movimiento_origen_id,
            'origen' => $detalle->origen_snapshot,
            'origen_documento' => $detalle->movimientoOrigen?->numero_documento,
            'origen_cliente' => $detalle->movimientoOrigen?->cliente ? [
                'id' => $detalle->movimientoOrigen->cliente->id,
                'codigo' => $detalle->movimientoOrigen->cliente->codigo,
                'nombre' => $detalle->movimientoOrigen->cliente->nombre,
            ] : null,
        ])->values();
        $resumen = $detalles
            ->groupBy(fn (array $detalle): string => $detalle['tipo_envase'].'|'.$detalle['propiedad'])
            ->map(function ($lineas, string $clave) use ($guia): array {
                [$tipo, $propiedad] = explode('|', $clave, 2);
                $cantidad = (int) $lineas->sum('cantidad');

                return [
                    'tipo_envase' => $tipo,
                    'propiedad' => $propiedad,
                    'cantidad' => $cantidad,
                    'reservado' => $guia->estado === EstadoGuiaDespachoEnvase::Borrador
                        ? $cantidad
                        : 0,
                    'impacto_cuenta_salida' => -$cantidad,
                    'impacto_existencia_salida' => -$cantidad,
                    'origenes' => $lineas->pluck('origen')->filter()->values(),
                ];
            })
            ->values();

        $conciliaciones = $guia->conciliacionesSalida;
        $conciliacion = $conciliaciones->first();
        $salidaConfirmada = in_array($guia->estado, [EstadoGuiaDespachoEnvase::Confirmada, EstadoGuiaDespachoEnvase::Anulada], true);
        $archivo = $guia->archivoVigente;
        $datos = [
            'id' => $guia->id,
            'numero' => $guia->numero,
            'numero_guia_despacho' => $guia->numero_guia_despacho,
            'falta_numero_guia' => $salidaConfirmada && $guia->numero_guia_despacho === null,
            'archivo_guia' => $archivo ? [
                'nombre' => $archivo->nombre_original,
                'mime' => $archivo->mime,
                'bytes' => $archivo->bytes,
                'subido_at' => $archivo->subido_at?->toAtomString(),
                'subido_por' => $archivo->subidoPor?->name,
            ] : null,
            'puede_registrar_guia' => $salidaConfirmada,
            'productor' => $guia->productorDestino(),
            'puede_editar_productor' => $guia->estado === EstadoGuiaDespachoEnvase::Confirmada
                && (Gate::allows('corregir-productor-despacho-envases')
                    || ($guia->productor_csg_id === null && Gate::allows('gestionar-despacho-envases'))),
            'estado' => $guia->estado->value,
            'temporada' => [
                'id' => $guia->temporada_id,
                'codigo' => $guia->temporada_codigo_snapshot ?: $guia->temporada->codigo,
                'nombre' => $guia->temporada_nombre_snapshot ?: $guia->temporada->nombre,
            ],
            'cliente' => [
                'id' => $guia->cliente_id,
                'codigo' => $guia->cliente_codigo_snapshot ?: $guia->cliente->codigo,
                'nombre' => $guia->cliente_nombre_snapshot ?: $guia->cliente->nombre,
            ],
            'salida_at' => $guia->salida_at?->toAtomString(),
            'patente_camion' => $guia->patente_camion,
            'conductor' => [
                'rut' => $guia->rut_conductor,
                'nombre' => $guia->nombre_conductor,
            ],
            'observacion' => $guia->observacion,
            'version' => $guia->version,
            'detalles' => $detalles,
            'resumen' => $resumen,
            'conciliacion_salida' => $conciliacion ? [
                'conservar' => $conciliacion->datos['conservar'],
                'cantidades' => $conciliacion->datos['cantidades_revisadas'],
                'romana_restaurada' => $conciliaciones->contains(fn ($m) => isset($m->datos['restaura_conciliacion_id'])),
            ] : null,
            'creado_por' => $guia->creadoPor?->name,
            'confirmado_por' => $guia->confirmadoPor?->name,
            'cancelado_por' => $guia->canceladoPor?->name,
            'anulado_por' => $guia->anuladoPor?->name,
            'confirmado_at' => $guia->confirmado_at?->toAtomString(),
            'cancelado_at' => $guia->cancelado_at?->toAtomString(),
            'anulado_at' => $guia->anulado_at?->toAtomString(),
            'motivo_cancelacion' => $guia->motivo_cancelacion,
            'motivo_anulacion' => $guia->motivo_anulacion,
            'documento_hash' => $guia->documento_hash,
            'puede_editar' => $guia->estado === EstadoGuiaDespachoEnvase::Borrador,
            'puede_confirmar' => $guia->estado === EstadoGuiaDespachoEnvase::Borrador,
            'puede_cancelar' => $guia->estado === EstadoGuiaDespachoEnvase::Borrador,
            'puede_anular' => $guia->estado === EstadoGuiaDespachoEnvase::Confirmada,
            'documento_disponible' => true,
            'comprobante_anulacion_disponible' => $guia->estado === EstadoGuiaDespachoEnvase::Anulada,
        ];

        if ($conEventos) {
            $datos['eventos'] = $guia->eventos->map(fn ($evento): array => [
                'id' => $evento->id,
                'tipo' => $evento->tipo,
                'estado_anterior' => $evento->estado_anterior,
                'estado_nuevo' => $evento->estado_nuevo,
                'ocurrido_at' => $evento->ocurrido_at?->toAtomString(),
                'usuario' => $evento->usuario?->name,
                'datos' => $evento->datos,
            ])->values();
        }

        return $datos;
    }
}

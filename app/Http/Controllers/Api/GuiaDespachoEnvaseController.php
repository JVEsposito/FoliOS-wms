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
        ])->header('Cache-Control', 'no-store, private');
    }

    public function index(
        Request $request,
        ServicioTemporadaActiva $temporadaActiva,
    ): JsonResponse {
        Gate::authorize('consultar-cuenta-envases');
        $filtros = $request->validate([
            'temporada_id' => ['nullable', 'uuid', 'exists:temporadas,id'],
            'estado' => ['nullable', Rule::enum(EstadoGuiaDespachoEnvase::class)],
        ]);
        $temporadaId = $filtros['temporada_id'] ?? $temporadaActiva->obtener()->id;
        $guias = GuiaDespachoEnvase::query()
            ->with([
                'cliente',
                'temporada',
                'detalles.movimientoOrigen.cliente',
                'archivoVigente.subidoPor',
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
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $guias->map(fn (GuiaDespachoEnvase $guia): array => $this->guia($guia)),
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

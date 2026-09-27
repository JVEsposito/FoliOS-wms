<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnularRepaletizajeRequest;
use App\Http\Requests\RegistrarRepaletizajeRequest;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\PersonalAccessToken;
use App\Models\Repaletizaje;
use App\Models\RepaletizajeDetalle;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Validacion\ServicioRegistroRepaletizaje;
use App\Services\Validacion\ServicioRepaletizaje;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RepaletizajeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $porPagina = min(100, max(10, $request->integer('per_page', 25)));
        $folio = trim($request->string('folio')->value());
        $temporadaActivaId = app(ServicioTemporadaActiva::class)->buscar()?->id;

        $paginacion = Repaletizaje::query()
            ->whereHas('folioResultante', fn ($folios) => $folios
                ->where('temporada_id', $temporadaActivaId))
            ->with($this->relaciones())
            ->when($folio !== '', function ($consulta) use ($folio): void {
                $consulta->where(function ($subconsulta) use ($folio): void {
                    $subconsulta
                        ->whereHas('folioResultante', fn ($folios) => $folios
                            ->where('numero_folio', 'like', "%{$folio}%"))
                        ->orWhereHas('resultados.folio', fn ($folios) => $folios
                            ->where('numero_folio', 'like', "%{$folio}%"))
                        ->orWhereHas('detalles.folioOrigen', fn ($folios) => $folios
                            ->where('numero_folio', 'like', "%{$folio}%"));
                });
            })
            ->latest('confirmado_at')
            ->paginate($porPagina)
            ->withQueryString();

        return response()->json([
            'data' => collect($paginacion->items())
                ->map(fn (Repaletizaje $repa): array => $this->recurso($repa))
                ->values(),
            'meta' => [
                'current_page' => $paginacion->currentPage(),
                'last_page' => $paginacion->lastPage(),
                'per_page' => $paginacion->perPage(),
                'total' => $paginacion->total(),
            ],
        ]);
    }

    /**
     * Planillas RRPL-01 de un día: una por turno y tarjador, con sus repas
     * vigentes y anuladas.
     */
    public function planillasRegistro(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $fecha = $datos['fecha'] ?? now(config('app.operational_timezone'))->toDateString();

        $planillas = Repaletizaje::query()
            ->whereDate('fecha_operacional', $fecha)
            ->whereHas('folioResultante', fn ($folios) => $folios
                ->where('temporada_id', app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->with('usuario:id,name')
            ->get(['id', 'turno', 'user_id', 'estado', 'fecha_operacional'])
            ->groupBy(fn (Repaletizaje $repa): string => ($repa->turno ?? '').'|'.$repa->user_id)
            ->map(function ($grupo) use ($fecha): array {
                /** @var Repaletizaje $primera */
                $primera = $grupo->first();

                return [
                    'fecha' => $fecha,
                    'turno' => $primera->turno,
                    'tarjador' => [
                        'id' => $primera->user_id,
                        'nombre' => $primera->usuario?->name,
                    ],
                    'repas' => $grupo->count(),
                    'anuladas' => $grupo->where('estado', 'anulado')->count(),
                ];
            })
            ->sortBy(fn (array $planilla): string => ($planilla['turno'] ?? '').'|'.($planilla['tarjador']['nombre'] ?? ''))
            ->values();

        return response()->json(['data' => $planillas, 'fecha' => $fecha])
            ->header('Cache-Control', 'no-store, private');
    }

    public function registro(
        Request $request,
        ServicioRegistroRepaletizaje $registro,
    ): BinaryFileResponse {
        $datos = $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d'],
            'turno' => ['nullable', Rule::in(['A', 'B'])],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        $repas = Repaletizaje::query()
            ->whereDate('fecha_operacional', $datos['fecha'])
            ->whereHas('folioResultante', fn ($folios) => $folios
                ->where('temporada_id', app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->when($datos['turno'] ?? null, fn ($consulta, string $turno) => $consulta->where('turno', $turno))
            ->when($datos['user_id'] ?? null, fn ($consulta, int $usuario) => $consulta->where('user_id', $usuario))
            ->with([...$this->relaciones(), 'resultados'])
            ->get();

        $nombre = sprintf(
            'RRPL-01_%s%s.xlsx',
            str_replace('-', '', $datos['fecha']),
            isset($datos['turno']) ? '_T'.$datos['turno'] : '',
        );

        return response()
            ->download($registro->generar($repas), $nombre, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store, private',
            ])
            ->deleteFileAfterSend();
    }

    public function registroEnBlanco(ServicioRegistroRepaletizaje $registro): BinaryFileResponse
    {
        return response()
            ->download($registro->generarEnBlanco(), 'RRPL-01_en_blanco.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store, private',
            ])
            ->deleteFileAfterSend();
    }

    public function show(
        Repaletizaje $repaletizaje,
        ServicioRepaletizaje $servicio,
    ): JsonResponse {
        return response()->json([
            'data' => $this->recurso($servicio->cargar($repaletizaje)),
        ]);
    }

    public function buscarFolio(string $numeroFolio): JsonResponse
    {
        $numero = mb_strtoupper(trim($numeroFolio));
        $folio = Folio::query()
            ->where('numero_folio', $numero)
            ->whereHas('temporada', fn ($consulta) => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->with([
                'ubicacionActual.camara:id,codigo,nombre',
                'ubicacionActual.posicion:id,etiqueta',
                'validacionPallet:id,folio_id,generado_dispositivo_at,recibido_servidor_at',
            ])
            ->first();

        if (! $folio) {
            return response()->json([
                'existe' => false,
                'numero_folio' => $numero,
            ]);
        }

        return response()->json([
            'existe' => true,
            'id' => $folio->id,
            'numero_folio' => $folio->numero_folio,
            'tipo_bulto' => $folio->tipo_bulto?->value,
            'cantidad_cajas' => (int) ($folio->datos_externos['cantidad_cajas'] ?? 0),
            'activo' => $folio->activo,
            'estado_operacional' => $folio->estado_operacional?->value,
            'condicion_termica' => $folio->condicion_termica?->value,
            'cliente' => $folio->exportadora,
            'especie' => $folio->datos_externos['especie'] ?? null,
            'marca' => $folio->marca,
            'variedad' => $folio->variedad,
            'calibre' => $folio->calibre,
            'envase' => $folio->datos_externos['envase'] ?? null,
            'categoria' => $folio->datos_externos['categoria'] ?? null,
            'csg' => $folio->datos_externos['csg'] ?? null,
            'predio' => $folio->datos_externos['predio'] ?? null,
            'cuartel' => $folio->datos_externos['cuartel'] ?? null,
            'composicion' => $this->composicionFolio($folio),
            'ubicacion' => $folio->ubicacionActual ? [
                'camara' => $folio->ubicacionActual->camara?->only([
                    'id',
                    'codigo',
                    'nombre',
                ]),
                'posicion' => $folio->ubicacionActual->posicion?->only([
                    'id',
                    'etiqueta',
                ]),
            ] : null,
        ]);
    }

    public function store(
        RegistrarRepaletizajeRequest $request,
        ServicioRepaletizaje $servicio,
    ): JsonResponse {
        $usuario = $request->user();
        $token = $usuario->currentAccessToken();
        $dispositivo = $token instanceof PersonalAccessToken && $token->dispositivo_id
            ? Dispositivo::query()->find($token->dispositivo_id)
            : null;
        $repa = $servicio->registrar(
            $request->validated(),
            $usuario,
            $dispositivo,
        );

        return response()->json(['data' => $this->recurso($repa)]);
    }

    public function anular(
        AnularRepaletizajeRequest $request,
        Repaletizaje $repaletizaje,
        ServicioRepaletizaje $servicio,
    ): JsonResponse {
        $repa = $servicio->anular(
            $repaletizaje,
            $request->validated('operacion_id'),
            $request->validated('motivo'),
            $request->user(),
        );

        return response()->json(['data' => $this->recurso($repa)]);
    }

    /** @return array<int, string> */
    private function relaciones(): array
    {
        return [
            'folioResultante.validacionPallet:id,folio_id,generado_dispositivo_at,recibido_servidor_at',
            'folioConservado',
            'resultados.folio.validacionPallet:id,folio_id,generado_dispositivo_at,recibido_servidor_at',
            'detalles.folioOrigen',
            'usuario:id,name',
            'dispositivo:id,codigo,nombre',
            'anuladoPor:id,name',
        ];
    }

    /** @return array<string, mixed> */
    private function recurso(Repaletizaje $repa): array
    {
        return [
            'id' => $repa->id,
            'codigo' => $repa->codigo,
            'modalidad' => $repa->modalidad ?? 'consolidacion',
            'tipo_resultado' => $repa->tipo_resultado,
            'estrategia_folio' => $repa->estrategia_folio,
            'cantidad_objetivo' => $repa->cantidad_objetivo,
            'cantidad_resultante' => $repa->cantidad_resultante,
            'condicion_termica' => $repa->condicion_termica,
            'estado' => $repa->estado,
            'campos_mix' => $repa->campos_mix,
            'advertencias' => $repa->snapshot['advertencias'] ?? [],
            'folio_resultante' => $repa->folioResultante ? [
                'id' => $repa->folioResultante->id,
                'numero_folio' => $repa->folioResultante->numero_folio,
                'tipo_bulto' => $repa->folioResultante->tipo_bulto?->value,
                'cantidad_cajas' => (int) (
                    $repa->folioResultante->datos_externos['cantidad_cajas'] ?? 0
                ),
                'estado_operacional' => $repa->folioResultante->estado_operacional?->value,
                'condicion_termica' => $repa->folioResultante->condicion_termica?->value,
                'cliente' => $repa->folioResultante->exportadora,
                'especie' => $repa->folioResultante->datos_externos['especie'] ?? null,
                'marca' => $repa->folioResultante->marca,
                'variedad' => $repa->folioResultante->variedad,
                'calibre' => $repa->folioResultante->calibre,
                'csg' => $repa->folioResultante->datos_externos['csg'] ?? null,
                'predio' => $repa->folioResultante->datos_externos['predio'] ?? null,
                'fecha_embalaje' => $repa->folioResultante->datos_externos['fecha_embalaje'] ?? null,
                'fechas_embalaje' => $repa->folioResultante->datos_externos['fechas_embalaje'] ?? [],
                'composicion' => $this->composicionFolio($repa->folioResultante),
            ] : null,
            'resultados' => $repa->resultados->map(fn ($resultado): array => [
                'id' => $resultado->id,
                'orden' => $resultado->orden,
                'tipo_resultado' => $resultado->tipo_resultado,
                'cantidad_objetivo' => $resultado->cantidad_objetivo,
                'cantidad_resultante' => $resultado->cantidad_resultante,
                'hereda_ubicacion' => $resultado->hereda_ubicacion,
                'folio' => $resultado->folio ? [
                    'id' => $resultado->folio->id,
                    'numero_folio' => $resultado->folio->numero_folio,
                    'tipo_bulto' => $resultado->folio->tipo_bulto?->value,
                    'cantidad_cajas' => (int) ($resultado->folio->datos_externos['cantidad_cajas'] ?? 0),
                    'composicion' => $this->composicionFolio($resultado->folio),
                ] : null,
            ])->values(),
            'origenes' => $repa->detalles->map(
                fn (RepaletizajeDetalle $detalle): array => [
                    'id' => $detalle->id,
                    'orden' => $detalle->orden,
                    'es_folio_conservado' => $detalle->es_folio_conservado,
                    'folio' => [
                        'id' => $detalle->folioOrigen->id,
                        'numero_folio' => $detalle->folioOrigen->numero_folio,
                    ],
                    'cajas_antes' => $detalle->cajas_antes,
                    'cajas_aportadas' => $detalle->cajas_aportadas,
                    'cajas_despues' => $detalle->cajas_despues,
                    'tipo_bulto_antes' => $detalle->tipo_bulto_antes,
                    'tipo_bulto_despues' => $detalle->tipo_bulto_despues,
                    'estado_antes' => $detalle->estado_antes,
                    'estado_despues' => $detalle->estado_despues,
                    'especificaciones' => $detalle->snapshot_antes['especificaciones'] ?? [],
                    'composicion_antes' => $detalle->snapshot_antes['atributos']['datos_externos']['composicion'] ?? [],
                    'composicion_despues' => $detalle->snapshot_despues['atributos']['datos_externos']['composicion'] ?? [],
                ],
            )->values(),
            'operador' => $repa->usuario ? [
                'id' => $repa->usuario->id,
                'nombre' => $repa->usuario->name,
            ] : null,
            'dispositivo' => $repa->dispositivo ? [
                'id' => $repa->dispositivo->id,
                'codigo' => $repa->dispositivo->codigo,
                'nombre' => $repa->dispositivo->nombre,
            ] : null,
            'observacion' => $repa->observacion,
            'turno' => $repa->turno,
            'fecha_operacional' => $repa->fecha_operacional?->toDateString(),
            'confirmado_at' => $repa->confirmado_at?->toAtomString(),
            'anulado_at' => $repa->anulado_at?->toAtomString(),
            'motivo_anulacion' => $repa->motivo_anulacion,
            'puede_anular' => $repa->estado === 'confirmado',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function composicionFolio(Folio $folio): array
    {
        $datos = $folio->datos_externos ?? [];
        $fechaPredeterminada = filled($datos['fecha_embalaje'] ?? null)
            ? (string) $datos['fecha_embalaje']
            : $this->fechaValidacionFolio($folio);
        $lineas = collect($datos['composicion'] ?? [])
            ->filter(fn (mixed $linea): bool => is_array($linea)
                && array_key_exists('cantidad_cajas', $linea)
                && array_key_exists('csg', $linea))
            ->map(function (array $linea) use ($fechaPredeterminada): array {
                if (! filled($linea['fecha_embalaje'] ?? null)) {
                    $linea['fecha_embalaje'] = $fechaPredeterminada;
                }

                return $linea;
            });

        if ($lineas->isEmpty() && (int) ($datos['cantidad_cajas'] ?? 0) > 0) {
            $lineas->push([
                'origen_validacion_id' => null,
                'csg' => $datos['csg'] ?? 'SIN CSG',
                'predio' => $datos['predio'] ?? null,
                'fecha_embalaje' => $fechaPredeterminada,
                'cantidad_cajas' => (int) $datos['cantidad_cajas'],
            ]);
        }

        return $lineas->map(function (array $linea): array {
            $linea['clave'] = ServicioRepaletizaje::claveComposicion($linea);
            $linea['cantidad_cajas'] = (int) $linea['cantidad_cajas'];

            return $linea;
        })->values()->all();
    }

    private function fechaValidacionFolio(Folio $folio): ?string
    {
        $folio->loadMissing(
            'validacionPallet:id,folio_id,generado_dispositivo_at,recibido_servidor_at',
        );
        $fecha = $folio->validacionPallet?->generado_dispositivo_at
            ?? $folio->validacionPallet?->recibido_servidor_at
            ?? $folio->fecha_ingreso;

        return $fecha?->setTimezone(config('app.operational_timezone'))->toDateString();
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoRecepcionRomana;
use App\Enums\EstadoValidacionMp;
use App\Enums\MotivoSegregacionMp;
use App\Enums\TipoEnvaseRomana;
use App\Enums\TipoRecepcionRomana;
use App\Http\Controllers\Controller;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Models\RecepcionRomana;
use App\Models\ValidacionMp;
use App\Models\VariedadValidacion;
use App\Services\Romana\ServicioGuiasRecepcionRomana;
use App\Services\Romana\ServicioInspeccionEnvases;
use App\Services\Romana\ServicioRepartoEnvases;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\ValidacionMp\ServicioValidacionMp;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ValidacionMpController extends Controller
{
    public function pendientes(Request $request): JsonResponse
    {
        $recepciones = RecepcionRomana::query()->where('estado', '!=', EstadoRecepcionRomana::Anulada->value)
            ->with([
                'detallesEnvases', 'guiasRecepcion', 'validacionTomadaPor',
                'validacionesMp.recepcion', 'validacionesMp.temporada', 'validacionesMp.validador',
                'validacionesMp.dispositivo', 'validacionesMp.segmentos.envases', 'validacionesMp.segmentos.guiaRecepcion',
            ])
            ->whereHas('temporada', fn (Builder $temporada): Builder => $temporada
                ->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->where(function (Builder $consulta) use ($request): void {
                $consulta->where('estado_validacion_mp', EstadoValidacionMp::Pendiente->value)
                    ->orWhere(function (Builder $propias) use ($request): void {
                        $propias->where('estado_validacion_mp', EstadoValidacionMp::EnCurso->value)
                            ->where('validacion_tomada_por_user_id', $request->user()->id);
                    });
            })
            ->orderBy('ingreso_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $recepciones->map(fn (RecepcionRomana $recepcion): array => $this->recepcion($recepcion)),
            'sincronizado_at' => now()->toAtomString(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function historico(Request $request): JsonResponse
    {
        $datos = $request->validate(['fecha' => ['nullable', 'date_format:Y-m-d']]);
        $fecha = $datos['fecha'] ?? now(config('app.operational_timezone'))->toDateString();
        $inicio = CarbonImmutable::parse($fecha, config('app.operational_timezone'))->startOfDay();
        $fin = $inicio->addDay();
        $temporadaId = app(ServicioTemporadaActiva::class)->buscar()?->id;

        $validaciones = ValidacionMp::query()
            ->where('temporada_id', $temporadaId)
            ->where('estado', EstadoValidacionMp::Validada->value)
            ->where('validada_at', '>=', $inicio->utc())
            ->where('validada_at', '<', $fin->utc())
            ->with(['recepcion.detallesEnvases', 'recepcion.guiasRecepcion', 'validador', 'dispositivo', 'segmentos.envases'])
            ->orderByDesc('validada_at')
            ->get();

        return response()->json([
            'fecha' => $fecha,
            'data' => $validaciones->map(fn (ValidacionMp $validacion): array => [
                'id' => $validacion->id,
                'fecha' => $validacion->validada_at?->toAtomString(),
                'numero_recepcion' => $validacion->recepcion->numero_recepcion,
                'cliente' => $validacion->recepcion->cliente_nombre_snapshot,
                'estado' => $validacion->estado->value,
                'segmentos' => $validacion->segmentos->map(fn ($segmento): array => [
                    'csg' => $segmento->csg_snapshot,
                    'variedad' => $segmento->variedad_snapshot,
                    'envases' => $segmento->envases->map(fn ($envase): array => [
                        'tipo_envase' => $envase->tipo_envase->value,
                        'cantidad' => $envase->cantidad,
                        'cantidad_vacia' => $envase->cantidad_vacia,
                    ])->values()->all(),
                ])->values()->all(),
            ])->values(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function buscar(string $numeroRecepcion): JsonResponse
    {
        $recepcion = RecepcionRomana::query()->where('estado', '!=', EstadoRecepcionRomana::Anulada->value)
            ->where('numero_recepcion', mb_strtoupper(trim($numeroRecepcion)))
            ->whereHas('temporada', fn (Builder $temporada): Builder => $temporada
                ->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->with([
                'detallesEnvases', 'guiasRecepcion', 'validacionTomadaPor',
                'validacionesMp.recepcion', 'validacionesMp.temporada', 'validacionesMp.validador',
                'validacionesMp.dispositivo', 'validacionesMp.segmentos.envases', 'validacionesMp.segmentos.guiaRecepcion',
            ])
            ->firstOrFail();

        return response()->json(['data' => $this->recepcion($recepcion)]);
    }

    public function catalogos(RecepcionRomana $recepcion): JsonResponse
    {
        $this->asegurarTemporadaActiva($recepcion->temporada_id);

        return response()->json([
            'temporada' => ['id' => $recepcion->temporada_id, 'codigo' => $recepcion->temporada_codigo_snapshot, 'nombre' => $recepcion->temporada_nombre_snapshot],
            'csg' => CsgValidacion::query()
                ->where('temporada_id', $recepcion->temporada_id)
                ->where('activo', true)
                ->disponibleParaCliente($recepcion->cliente_id)
                ->with('variedades:id')
                ->orderBy('codigo')
                ->get()
                ->map(fn (CsgValidacion $csg): array => [
                    'id' => $csg->id,
                    'codigo' => $csg->codigo,
                    'predio' => $csg->predio,
                    'variedad_ids' => $csg->variedades->pluck('id')->values(),
                ]),
            'especies' => EspecieValidacion::query()->where('temporada_id', $recepcion->temporada_id)
                ->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'variedades' => VariedadValidacion::query()->where('activo', true)
                ->where('especie_validacion_id', $recepcion->especie_validacion_id)
                ->with('especie:id,nombre')->orderBy('nombre')->get()->map(fn (VariedadValidacion $variedad): array => [
                    'id' => $variedad->id, 'nombre' => $variedad->nombre, 'especie' => $variedad->especie?->nombre,
                ]),
            'motivos' => array_column(MotivoSegregacionMp::cases(), 'value'),
            // Referencia para sugerir los bins de planta que faltan; no limita lo que se registra.
            'totes_por_bin' => app(ServicioRepartoEnvases::class)->configuracion($recepcion->especie_validacion_id)['totes_por_bin'],
        ]);
    }

    public function tomar(Request $request, RecepcionRomana $recepcion, ServicioValidacionMp $servicio): JsonResponse
    {
        $this->asegurarTemporadaActiva($recepcion->temporada_id);
        $datos = $request->validate(['operacion_id' => ['required', 'uuid']]);
        $token = $request->user()->currentAccessToken();
        $validacion = $servicio->tomar($recepcion, $datos['operacion_id'], $request->user(), $token?->dispositivo_id);

        return response()->json(['data' => $this->validacion($validacion)]);
    }

    public function completarEspecie(Request $request, RecepcionRomana $recepcion, ServicioValidacionMp $servicio): JsonResponse
    {
        $this->asegurarTemporadaActiva($recepcion->temporada_id);
        $datos = $request->validate([
            'operacion_id' => ['required', 'uuid'],
            'version_conocida' => ['required', 'integer', 'min:1'],
            'especie_validacion_id' => ['required', 'uuid'],
        ]);
        $actualizada = $servicio->completarEspecie($recepcion, $datos['especie_validacion_id'], $datos['version_conocida'], $datos['operacion_id'], $request->user());
        $actualizada->load(['detallesEnvases', 'guiasRecepcion', 'validacionTomadaPor', 'validacionesMp.recepcion', 'validacionesMp.temporada',
            'validacionesMp.validador', 'validacionesMp.dispositivo', 'validacionesMp.segmentos.envases', 'validacionesMp.segmentos.guiaRecepcion']);

        return response()->json(['data' => $this->recepcion($actualizada)])->header('Cache-Control', 'no-store, private');
    }

    public function confirmar(Request $request, ValidacionMp $validacionMp, ServicioValidacionMp $servicio): JsonResponse
    {
        $this->asegurarTemporadaActiva($validacionMp->temporada_id);
        $sinSegregacion = ! $request->boolean('requiere_segregacion')
            && $validacionMp->recepcion->tipo_recepcion->contieneFruta();
        $datos = $request->validate([
            'operacion_id' => ['required', 'uuid'],
            'envases' => ['required', 'array', 'min:1', 'max:'.count(TipoEnvaseRomana::cases())],
            'envases.*.tipo_envase' => ['required', 'distinct', Rule::enum(TipoEnvaseRomana::class)],
            'envases.*.cantidad_validada' => ['required', 'integer', 'min:0', 'max:100000'],
            'envases.*.cantidad_vacia' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'tarjas_verificadas' => ['nullable', 'boolean'],
            'requiere_segregacion' => ['nullable', 'boolean'],
            'segmentos' => ['nullable', 'array', 'max:100'],
            'segmentos.*.guia_recepcion_romana_id' => ['sometimes', 'nullable', 'uuid'],
            'segmentos.*.motivos' => ['required_with:segmentos', 'array', 'max:4'],
            'segmentos.*.motivos.*' => [Rule::enum(MotivoSegregacionMp::class)],
            'csg_validacion_id' => [Rule::requiredIf($sinSegregacion), 'nullable', 'uuid'],
            'variedad_validacion_id' => [Rule::requiredIf($sinSegregacion), 'nullable', 'uuid'],
            'segmentos.*.csg_validacion_id' => ['required_with:segmentos', 'uuid'],
            'segmentos.*.cuartel' => ['nullable', 'string', 'max:100'],
            'segmentos.*.variedad_validacion_id' => ['required_with:segmentos', 'uuid'],
            'segmentos.*.envases' => ['required_with:segmentos', 'array', 'min:1', 'max:'.count(TipoEnvaseRomana::cases())],
            'segmentos.*.envases.*.tipo_envase' => ['required', Rule::enum(TipoEnvaseRomana::class)],
            'segmentos.*.envases.*.cantidad' => ['required', 'integer', 'min:0', 'max:100000'],
            'segmentos.*.envases.*.cantidad_vacia' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'segmentos.*.observacion' => ['nullable', 'string', 'max:1000'],
            'segmentos.*.bins_prestados_planta' => ['nullable', 'integer', 'min:0', 'max:999'],
            'bins_prestados_planta' => ['nullable', 'integer', 'min:0', 'max:999'],
            'observacion' => ['nullable', 'string', 'max:2000'],
            ...ServicioInspeccionEnvases::reglas(true),
        ], [
            'required' => 'Completa :attribute.',
            'required_with' => 'Completa :attribute.',
            'uuid' => 'Selecciona un valor válido para :attribute.',
            'array' => 'El formato de :attribute no es válido.',
            'integer' => 'Ingresa un número entero en :attribute.',
            'boolean' => 'Indica Sí o No en :attribute.',
            'string' => 'Ingresa texto en :attribute.',
            'enum' => 'Selecciona una opción válida para :attribute.',
            'distinct' => 'No repitas valores en :attribute.',
            'min.numeric' => 'El valor de :attribute debe ser al menos :min.',
            'max.numeric' => 'El valor de :attribute no puede superar :max.',
            'min.array' => 'Agrega al menos :min elemento(s) en :attribute.',
            'max.array' => 'No agregues más de :max elementos en :attribute.',
            'max.string' => 'El texto de :attribute no puede superar :max caracteres.',
            'segmentos.*.guia_recepcion_romana_id.uuid' => 'Segmento :position: selecciona una guía de esta recepción.',
            'segmentos.*.motivos.required_with' => 'Segmento :position: selecciona al menos un motivo de segregación.',
            'segmentos.*.csg_validacion_id.required_with' => 'Segmento :position: selecciona el CSG.',
            'segmentos.*.variedad_validacion_id.required_with' => 'Segmento :position: selecciona la variedad.',
            'segmentos.*.envases.required_with' => 'Segmento :position: indica el reparto de envases.',
            'inspeccion_envases.required' => 'Completa la inspección de recepción RC-02 antes de confirmar.',
            'inspeccion_envases.items.required_with' => 'RC-02: completa la inspección de los tipos de envase recibidos.',
            'inspeccion_envases.coincide_especie_variedad.required_with' => 'RC-02: responde Sí o No en «Coincide especie y variedad».',
            'inspeccion_envases.coincide_cantidad_bins.required_with' => 'RC-02: responde Sí o No en «Coincide cantidad de bins».',
            'inspeccion_envases.bins_bien_etiquetados.required_with' => 'RC-02: responde Sí o No en «Bins bien etiquetados».',
        ], [
            'operacion_id' => 'la identificación de la operación',
            'envases' => 'el conteo de envases',
            'envases.*.tipo_envase' => 'el tipo de envase',
            'envases.*.cantidad_validada' => 'la cantidad contada en piso',
            'envases.*.cantidad_vacia' => 'los envases vacíos',
            'tarjas_verificadas' => 'el chequeo de tarjas',
            'requiere_segregacion' => 'la opción de segregación',
            'csg_validacion_id' => 'el CSG de la recepción',
            'variedad_validacion_id' => 'la variedad de la recepción',
            'segmentos' => 'los segmentos de la segregación',
            'segmentos.*.motivos' => 'los motivos de segregación',
            'segmentos.*.motivos.*' => 'el motivo de segregación',
            'segmentos.*.csg_validacion_id' => 'el CSG del segmento',
            'segmentos.*.variedad_validacion_id' => 'la variedad del segmento',
            'segmentos.*.cuartel' => 'el cuartel del segmento',
            'segmentos.*.envases' => 'el reparto de envases del segmento',
            'segmentos.*.envases.*.tipo_envase' => 'el tipo de envase del segmento',
            'segmentos.*.envases.*.cantidad' => 'la cantidad de envases del segmento',
            'segmentos.*.envases.*.cantidad_vacia' => 'los envases vacíos del segmento',
            'segmentos.*.bins_prestados_planta' => 'los bins de planta del segmento',
            'segmentos.*.observacion' => 'la observación del segmento',
            'bins_prestados_planta' => 'los bins de planta agregados',
            'observacion' => 'la observación de la recepción',
            'inspeccion_envases' => 'la inspección RC-02',
            'inspeccion_envases.items' => 'los envases inspeccionados en RC-02',
            'inspeccion_envases.items.*.tipo_envase' => 'el tipo de envase inspeccionado en RC-02',
            'inspeccion_envases.items.*.limpieza' => 'la limpieza del envase en RC-02',
            'inspeccion_envases.items.*.condicion' => 'la condición del envase en RC-02',
            'inspeccion_envases.items.*.nota' => 'la nota del envase en RC-02',
            'inspeccion_envases.coincide_especie_variedad' => 'la coincidencia de especie y variedad en RC-02',
            'inspeccion_envases.coincide_cantidad_bins' => 'la coincidencia de cantidad de bins en RC-02',
            'inspeccion_envases.bins_bien_etiquetados' => 'el etiquetado de bins en RC-02',
            'inspeccion_envases.observacion' => 'la observación de RC-02',
        ]);
        $validacion = $servicio->confirmar($validacionMp, $datos, $request->user());

        return response()->json(['data' => $this->validacion($validacion)]);
    }

    private function asegurarTemporadaActiva(string $temporadaId): void
    {
        abort_unless(
            $temporadaId === app(ServicioTemporadaActiva::class)->buscar()?->id,
            404,
        );
    }

    /** @return array<string, mixed> */
    private function recepcion(RecepcionRomana $recepcion): array
    {
        return [
            'id' => $recepcion->id,
            'numero_recepcion' => $recepcion->numero_recepcion,
            'estado_romana' => $recepcion->estado->value,
            'estado_validacion_mp' => $recepcion->estado_validacion_mp->value,
            'tipo_recepcion' => $recepcion->tipo_recepcion->value,
            'especie_validacion_id' => $recepcion->especie_validacion_id,
            'version' => $recepcion->version,
            'concepto_envases' => $recepcion->concepto_envases?->value,
            'temporada' => ['id' => $recepcion->temporada_id, 'codigo' => $recepcion->temporada_codigo_snapshot, 'nombre' => $recepcion->temporada_nombre_snapshot],
            'cliente' => ['id' => $recepcion->cliente_id, 'codigo' => $recepcion->cliente_codigo_snapshot, 'nombre' => $recepcion->cliente_nombre_snapshot],
            'numero_guia_despacho' => $recepcion->numero_guia_despacho,
            'guias' => app(ServicioGuiasRecepcionRomana::class)->datos($recepcion),
            'patente_camion' => $recepcion->patente_camion,
            'conductor' => ['rut' => $recepcion->rut_conductor, 'nombre' => $recepcion->nombre_conductor],
            'ingreso_at' => $recepcion->ingreso_at?->toAtomString(),
            'pesaje_envases' => $recepcion->tipo_recepcion === TipoRecepcionRomana::FrutaPesajeEnvases ? [
                'tipo_envase' => $recepcion->tipo_envase_pesaje?->value,
                'tara_unitaria' => $recepcion->tara_unitaria_envase !== null
                    ? (float) $recepcion->tara_unitaria_envase
                    : null,
                'cantidad_declarada' => $recepcion->cantidad_envases_declarados,
                'cantidad_pesada' => $recepcion->cantidad_envases_pesados,
                'tipos' => $recepcion->avancePesajeEnvasesPorTipo(),
                'peso_neto' => $recepcion->peso_neto !== null ? (float) $recepcion->peso_neto : null,
                'peso_neto_por_envase' => $recepcion->peso_neto_por_envase !== null
                    ? (float) $recepcion->peso_neto_por_envase
                    : null,
            ] : null,
            'envases' => $recepcion->detallesEnvases->map(fn ($detalle): array => [
                'tipo_envase' => $detalle->tipo_envase->value,
                'propiedad' => $recepcion->propiedadEnvase($detalle->tipo_envase->value)->value,
                'cantidad_declarada' => $detalle->cantidad_declarada,
                'cantidad_validada' => $detalle->cantidad_validada,
                'cantidad_vacia' => $detalle->cantidad_vacia,
                'diferencia' => $detalle->cantidad_validada === null ? null : $detalle->cantidad_validada - $detalle->cantidad_declarada,
                'contiene_fruta' => $detalle->tipo_envase->contieneFruta(),
            ])->values(),
            'tomada_por' => $recepcion->validacionTomadaPor ? ['id' => $recepcion->validacionTomadaPor->id, 'nombre' => $recepcion->validacionTomadaPor->name] : null,
            'validacion' => $recepcion->validacionesMp->first() ? $this->validacion($recepcion->validacionesMp->first()) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function validacion(ValidacionMp $validacion): array
    {
        return [
            'id' => $validacion->id,
            'estado' => $validacion->estado->value,
            'numero_recepcion' => $validacion->recepcion->numero_recepcion,
            'temporada' => ['id' => $validacion->temporada_id, 'codigo' => $validacion->recepcion->temporada_codigo_snapshot],
            'validador' => ['id' => $validacion->validador->id, 'nombre' => $validacion->validador->name],
            'dispositivo' => $validacion->dispositivo ? ['id' => $validacion->dispositivo->id, 'codigo' => $validacion->dispositivo->codigo] : null,
            'tarjas_verificadas' => $validacion->tarjas_verificadas,
            'requiere_segregacion' => $validacion->requiere_segregacion,
            'bins_prestados_planta' => $validacion->bins_prestados_planta,
            'tomada_at' => $validacion->tomada_at?->toAtomString(),
            'validada_at' => $validacion->validada_at?->toAtomString(),
            'observacion' => $validacion->observacion,
            'segmentos' => $validacion->segmentos->map(fn ($segmento): array => [
                'id' => $segmento->id,
                'secuencia' => $segmento->secuencia,
                'guia_recepcion_romana_id' => $segmento->guia_recepcion_romana_id,
                'numero_guia_despacho' => $segmento->guiaRecepcion?->numero_guia_despacho,
                'motivos' => $segmento->motivos,
                'csg' => $segmento->csg_snapshot,
                'cuartel' => $segmento->cuartel,
                'variedad' => $segmento->variedad_snapshot,
                'estado' => $segmento->estado,
                'bins_prestados_planta' => $segmento->bins_prestados_planta,
                'envases' => $segmento->envases->map(fn ($envase): array => ['tipo_envase' => $envase->tipo_envase->value, 'cantidad' => $envase->cantidad,
                    'cantidad_vacia' => $envase->cantidad_vacia])->values(),
            ])->values(),
        ];
    }
}

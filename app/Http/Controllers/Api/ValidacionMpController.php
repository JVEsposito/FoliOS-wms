<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoValidacionMp;
use App\Enums\MotivoSegregacionMp;
use App\Enums\TipoEnvaseRomana;
use App\Enums\TipoRecepcionRomana;
use App\Http\Controllers\Controller;
use App\Models\CsgValidacion;
use App\Models\RecepcionRomana;
use App\Models\ValidacionMp;
use App\Models\VariedadValidacion;
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
        $recepciones = RecepcionRomana::query()
            ->with([
                'detallesEnvases', 'validacionTomadaPor',
                'validacionesMp.recepcion', 'validacionesMp.temporada', 'validacionesMp.validador',
                'validacionesMp.dispositivo', 'validacionesMp.segmentos.envases',
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
            ->with(['recepcion.detallesEnvases', 'validador', 'dispositivo', 'segmentos.envases'])
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
                    ])->values()->all(),
                ])->values()->all(),
            ])->values(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function buscar(string $numeroRecepcion): JsonResponse
    {
        $recepcion = RecepcionRomana::query()
            ->where('numero_recepcion', mb_strtoupper(trim($numeroRecepcion)))
            ->whereHas('temporada', fn (Builder $temporada): Builder => $temporada
                ->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->with([
                'detallesEnvases', 'validacionTomadaPor',
                'validacionesMp.recepcion', 'validacionesMp.temporada', 'validacionesMp.validador',
                'validacionesMp.dispositivo', 'validacionesMp.segmentos.envases',
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
            'variedades' => VariedadValidacion::query()->where('activo', true)
                ->where('especie_validacion_id', $recepcion->especie_validacion_id)
                ->with('especie:id,nombre')->orderBy('nombre')->get()->map(fn (VariedadValidacion $variedad): array => [
                    'id' => $variedad->id, 'nombre' => $variedad->nombre, 'especie' => $variedad->especie?->nombre,
                ]),
            'motivos' => array_column(MotivoSegregacionMp::cases(), 'value'),
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

    public function confirmar(Request $request, ValidacionMp $validacionMp, ServicioValidacionMp $servicio): JsonResponse
    {
        $this->asegurarTemporadaActiva($validacionMp->temporada_id);
        $sinSegregacion = ! $request->boolean('requiere_segregacion')
            && $validacionMp->recepcion->tipo_recepcion->contieneFruta();
        $datos = $request->validate([
            'operacion_id' => ['required', 'uuid'],
            'envases' => ['required', 'array', 'min:1', 'max:3'],
            'envases.*.tipo_envase' => ['required', 'distinct', Rule::enum(TipoEnvaseRomana::class)],
            'envases.*.cantidad_validada' => ['required', 'integer', 'min:0', 'max:100000'],
            'tarjas_verificadas' => ['nullable', 'boolean'],
            'requiere_segregacion' => ['nullable', 'boolean'],
            'segmentos' => ['nullable', 'array', 'max:100'],
            'segmentos.*.motivos' => ['required_with:segmentos', 'array', 'max:3'],
            'segmentos.*.motivos.*' => [Rule::enum(MotivoSegregacionMp::class)],
            'csg_validacion_id' => [Rule::requiredIf($sinSegregacion), 'nullable', 'uuid'],
            'variedad_validacion_id' => [Rule::requiredIf($sinSegregacion), 'nullable', 'uuid'],
            'segmentos.*.csg_validacion_id' => ['required_with:segmentos', 'uuid'],
            'segmentos.*.cuartel' => ['nullable', 'string', 'max:100'],
            'segmentos.*.variedad_validacion_id' => ['required_with:segmentos', 'uuid'],
            'segmentos.*.envases' => ['required_with:segmentos', 'array', 'min:1', 'max:3'],
            'segmentos.*.envases.*.tipo_envase' => ['required', Rule::enum(TipoEnvaseRomana::class)],
            'segmentos.*.envases.*.cantidad' => ['required', 'integer', 'min:0', 'max:100000'],
            'segmentos.*.observacion' => ['nullable', 'string', 'max:1000'],
            'observacion' => ['nullable', 'string', 'max:2000'],
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
            'concepto_envases' => $recepcion->concepto_envases?->value,
            'temporada' => ['id' => $recepcion->temporada_id, 'codigo' => $recepcion->temporada_codigo_snapshot, 'nombre' => $recepcion->temporada_nombre_snapshot],
            'cliente' => ['id' => $recepcion->cliente_id, 'codigo' => $recepcion->cliente_codigo_snapshot, 'nombre' => $recepcion->cliente_nombre_snapshot],
            'numero_guia_despacho' => $recepcion->numero_guia_despacho,
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
                'peso_neto' => $recepcion->peso_neto !== null ? (float) $recepcion->peso_neto : null,
                'peso_neto_por_envase' => $recepcion->peso_neto_por_envase !== null
                    ? (float) $recepcion->peso_neto_por_envase
                    : null,
            ] : null,
            'envases' => $recepcion->detallesEnvases->map(fn ($detalle): array => [
                'tipo_envase' => $detalle->tipo_envase->value,
                'cantidad_declarada' => $detalle->cantidad_declarada,
                'cantidad_validada' => $detalle->cantidad_validada,
                'diferencia' => $detalle->cantidad_validada === null ? null : $detalle->cantidad_validada - $detalle->cantidad_declarada,
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
            'tomada_at' => $validacion->tomada_at?->toAtomString(),
            'validada_at' => $validacion->validada_at?->toAtomString(),
            'observacion' => $validacion->observacion,
            'segmentos' => $validacion->segmentos->map(fn ($segmento): array => [
                'id' => $segmento->id,
                'secuencia' => $segmento->secuencia,
                'motivos' => $segmento->motivos,
                'csg' => $segmento->csg_snapshot,
                'cuartel' => $segmento->cuartel,
                'variedad' => $segmento->variedad_snapshot,
                'estado' => $segmento->estado,
                'envases' => $segmento->envases->map(fn ($envase): array => ['tipo_envase' => $envase->tipo_envase->value, 'cantidad' => $envase->cantidad])->values(),
            ])->values(),
        ];
    }
}

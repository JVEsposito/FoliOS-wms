<?php

namespace App\Http\Resources;

use App\Enums\EstadoCarga;
use App\Enums\EstadoCargaFolio;
use App\Services\Cargas\CalculadorConcentracionCarga;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class CargaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $asignacionesActuales = $this->resource->relationLoaded('asignacionesActuales')
            ? $this->asignacionesActuales
            : collect();
        $asignaciones = $this->asignacionesVisibles($asignacionesActuales);
        $folios = $this->transformarFolios($asignaciones);
        $concentracion = app(CalculadorConcentracionCarga::class)
            ->calcular($asignaciones);

        return [
            'id' => $this->id,
            'temporada' => $this->whenLoaded('temporada', fn () => $this->temporada ? [
                'id' => $this->temporada->id,
                'codigo' => $this->temporada->codigo,
                'nombre' => $this->temporada->nombre,
                'activa' => $this->temporada->activa,
            ] : null),
            'codigo' => $this->codigo,
            'numero_orden_externa' => $this->numero_orden_externa,
            'estado' => $this->estado->value,
            'modalidad_salida' => $this->modalidad_salida->value,
            'prioridad' => $this->prioridad->value,
            'version' => $this->version,
            'observacion' => $this->observacion,
            'camara_objetivo' => $this->whenLoaded('camaraObjetivo', fn () => $this->camaraObjetivo ? [
                'id' => $this->camaraObjetivo->id,
                'codigo' => $this->camaraObjetivo->codigo,
                'nombre' => $this->camaraObjetivo->nombre,
            ] : null),
            'anden_previsto' => $this->whenLoaded('andenPrevisto', fn () => $this->andenPrevisto ? [
                'id' => $this->andenPrevisto->id,
                'codigo' => $this->andenPrevisto->codigo,
                'nombre' => $this->andenPrevisto->nombre,
            ] : null),
            'camion_en_anden' => $this->whenLoaded(
                'presenciaAndenActiva',
                fn () => $this->presenciaAndenActiva ? [
                    'id' => $this->presenciaAndenActiva->id,
                    'patente' => $this->presenciaAndenActiva->patente,
                    'conductor' => $this->presenciaAndenActiva->conductor,
                    'observacion' => $this->presenciaAndenActiva->observacion_ingreso,
                    'ingresada_at' => $this->presenciaAndenActiva->ingresada_at?->toAtomString(),
                    'anden' => $this->presenciaAndenActiva->relationLoaded('anden') ? [
                        'id' => $this->presenciaAndenActiva->anden->id,
                        'codigo' => $this->presenciaAndenActiva->anden->codigo,
                        'nombre' => $this->presenciaAndenActiva->anden->nombre,
                    ] : null,
                    'registrada_por' => $this->presenciaAndenActiva->relationLoaded('ingresadaPor') ? [
                        'id' => $this->presenciaAndenActiva->ingresadaPor->id,
                        'nombre' => $this->presenciaAndenActiva->ingresadaPor->name,
                    ] : null,
                ] : null,
            ),
            'embarque' => $this->whenLoaded('embarque', fn () => $this->embarque ? [
                'id' => $this->embarque->id,
                'codigo' => $this->embarque->codigo,
                'numeros_externos' => $this->embarque->relationLoaded('instructivos')
                    ? $this->embarque->instructivos->pluck('numero_externo')
                        ->filter()->unique()->values()
                    : [],
                'fecha_programada' => $this->embarque->fecha_programada?->toDateString(),
                'hora_programada' => substr((string) $this->embarque->hora_programada, 0, 5),
                'modalidad' => $this->embarque->modalidad->value,
                'estado' => $this->embarque->estado->value,
            ] : null),
            'total_folios' => $folios->count(),
            'folios' => $folios,
            'distribucion' => $this->distribucion($folios),
            'progreso' => $concentracion,
            'incidencias_abiertas' => (int) ($this->incidencias_abiertas ?? 0),
            'tareas' => $this->whenLoaded('tareas', fn () => $this->tareas->map(fn ($tarea): array => [
                'id' => $tarea->id,
                'estado' => $tarea->estado->value,
                'camara_origen' => $tarea->relationLoaded('camaraOrigen') ? [
                    'id' => $tarea->camaraOrigen->id,
                    'codigo' => $tarea->camaraOrigen->codigo,
                    'nombre' => $tarea->camaraOrigen->nombre,
                ] : null,
                'responsable' => $tarea->relationLoaded('responsable') && $tarea->responsable ? [
                    'id' => $tarea->responsable->id,
                    'nombre' => $tarea->responsable->name,
                ] : null,
                'asumida_at' => $tarea->asumida_at?->toAtomString(),
                'completada_at' => $tarea->completada_at?->toAtomString(),
            ])->values()),
            'creada_por' => $this->whenLoaded('creadaPor', fn () => [
                'id' => $this->creadaPor?->id,
                'nombre' => $this->creadaPor?->name,
            ]),
            'actualizada_por' => $this->whenLoaded('actualizadaPor', fn () => [
                'id' => $this->actualizadaPor?->id,
                'nombre' => $this->actualizadaPor?->name,
            ]),
            'publicada_por' => $this->whenLoaded('publicadaPor', fn () => $this->publicadaPor ? [
                'id' => $this->publicadaPor->id,
                'nombre' => $this->publicadaPor->name,
            ] : null),
            'cancelada_por' => $this->whenLoaded('canceladaPor', fn () => $this->canceladaPor ? [
                'id' => $this->canceladaPor->id,
                'nombre' => $this->canceladaPor->name,
            ] : null),
            'publicada_at' => $this->publicada_at?->toAtomString(),
            'cancelada_at' => $this->cancelada_at?->toAtomString(),
            'cierre' => $this->cerrada_at ? [
                'patente' => $this->patente,
                'conductor' => $this->conductor,
                'observacion' => $this->observacion_cierre,
                'cerrada_at' => $this->cerrada_at->toAtomString(),
                'registrada_at' => $this->cierre_registrado_at?->toAtomString(),
                'cerrada_por' => $this->whenLoaded('cerradaPor', fn () => [
                    'id' => $this->cerradaPor?->id,
                    'nombre' => $this->cerradaPor?->name,
                ]),
            ] : null,
            'created_at' => $this->created_at?->toAtomString(),
            'updated_at' => $this->updated_at?->toAtomString(),
        ];
    }

    /**
     * @param  Collection<int, mixed>  $actuales
     * @return Collection<int, mixed>
     */
    private function asignacionesVisibles(Collection $actuales): Collection
    {
        if ($actuales->isNotEmpty() || $this->estado !== EstadoCarga::Cerrada) {
            return $actuales;
        }

        if (! $this->resource->relationLoaded('asignacionesHistoricas')) {
            return collect();
        }

        return $this->asignacionesHistoricas
            ->filter(fn ($asignacion): bool => in_array($asignacion->estado, [EstadoCargaFolio::EnAnden, EstadoCargaFolio::Despachado], true))
            ->values();
    }

    /**
     * @param  Collection<int, mixed>  $asignaciones
     * @return Collection<int, array<string, mixed>>
     */
    private function transformarFolios(Collection $asignaciones): Collection
    {
        return $asignaciones
            ->map(function ($asignacion): array {
                $folio = $asignacion->folio;
                $ubicacion = $folio?->ubicacionActual;
                $posicion = $ubicacion?->posicion;
                $camara = $posicion?->camara;
                $prefrio = $folio && $folio->relationLoaded('procesosPrefrio')
                    ? $folio->procesosPrefrio->sortByDesc('created_at')->first(
                        fn ($item): bool => in_array($item->estado->value, ['en_proceso', 'aprobado'], true),
                    )
                    : null;

                return [
                    'asignacion_id' => $asignacion->id,
                    'id' => $folio?->id,
                    'numero_folio' => $folio?->numero_folio,
                    'tipo_bulto' => $folio?->tipo_bulto?->value,
                    'estado_operacional' => $folio?->estado_operacional?->value,
                    'estado_carga' => $asignacion->estado->value,
                    'prefrio' => $prefrio?->proceso?->tunel ? [
                        'tunel' => $prefrio->proceso->tunel->codigo,
                        'estado' => $prefrio->estado->value,
                    ] : null,
                    'anden' => $asignacion->relationLoaded('anden') && $asignacion->anden ? [
                        'id' => $asignacion->anden->id,
                        'codigo' => $asignacion->anden->codigo,
                        'nombre' => $asignacion->anden->nombre,
                    ] : null,
                    'asignado_at' => $asignacion->asignado_at?->toAtomString(),
                    'ubicacion' => $posicion && $camara ? [
                        'camara' => [
                            'id' => $camara->id,
                            'codigo' => $camara->codigo,
                            'nombre' => $camara->nombre,
                        ],
                        'posicion' => [
                            'id' => $posicion->id,
                            'banda' => $posicion->banda,
                            'posicion' => $posicion->posicion,
                            'nivel' => $posicion->nivel,
                            'etiqueta' => $posicion->etiqueta,
                        ],
                    ] : null,
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $folios
     * @return Collection<int, array<string, mixed>>
     */
    private function distribucion(Collection $folios): Collection
    {
        return $folios
            ->filter(fn (array $folio): bool => $folio['ubicacion'] !== null)
            ->groupBy(fn (array $folio): string => $folio['ubicacion']['camara']['id'])
            ->map(function (Collection $grupo): array {
                $primero = $grupo->first();

                return [
                    'camara' => $primero['ubicacion']['camara'],
                    'cantidad' => $grupo->count(),
                ];
            })
            ->values();
    }
}

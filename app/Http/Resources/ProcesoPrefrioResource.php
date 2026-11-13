<?php

namespace App\Http\Resources;

use App\Services\Prefrio\RegistroPrefrioR41;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProcesoPrefrioResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'temporada' => $this->whenLoaded('temporada', fn () => $this->temporada ? [
                'id' => $this->temporada->id,
                'codigo' => $this->temporada->codigo,
                'nombre' => $this->temporada->nombre,
                'activa' => $this->temporada->activa,
            ] : null),
            'codigo' => $this->codigo,
            'tunel' => $this->whenLoaded('tunel', fn () => [
                'id' => $this->tunel->id,
                'codigo' => $this->tunel->codigo,
                'nombre' => $this->tunel->nombre,
                'capacidad_posiciones' => $this->tunel->capacidad_posiciones,
                'setpoint_habitual' => $this->tunel->setpoint_habitual !== null
                    ? (float) $this->tunel->setpoint_habitual
                    : null,
                'estado_administrativo' => $this->tunel->estado_administrativo->value,
                'estado_tecnico' => $this->tunel->estado_tecnico->value,
                'version_configuracion' => $this->tunel->version_configuracion,
            ]),
            'estado' => $this->estado->value,
            'setpoint' => (float) $this->setpoint,
            'duracion_objetivo_minutos' => $this->duracion_objetivo_minutos,
            'formato_referencia' => $this->formato_referencia,
            'version' => $this->version,
            'observacion' => $this->observacion,
            'iniciado_at' => $this->iniciado_at?->toAtomString(),
            'pendiente_verificacion_at' => $this->pendiente_verificacion_at?->toAtomString(),
            'finalizado_at' => $this->finalizado_at?->toAtomString(),
            'folios' => $this->whenLoaded('folios', fn () => $this->folios->map(fn ($asignacion) => [
                'id' => $asignacion->id,
                'estado' => $asignacion->estado->value,
                'temperatura_inicial' => $asignacion->temperatura_inicial !== null
                    ? (float) $asignacion->temperatura_inicial
                    : null,
                'temperatura_final' => $asignacion->temperatura_final !== null
                    ? (float) $asignacion->temperatura_final
                    : null,
                'cargado_at' => $asignacion->cargado_at?->toAtomString(),
                'finalizado_parcial_at' => $asignacion->finalizado_parcial_at?->toAtomString(),
                'en_tunel' => $asignacion->ocupaPosicion()
                    && ($this->estado->esActivo() || $asignacion->finalizado_parcial_at !== null),
                'retirado_at' => $asignacion->retirado_at?->toAtomString(),
                'motivo_resultado' => $asignacion->motivo_resultado,
                'observacion' => $asignacion->observacion,
                'posicion' => $asignacion->relationLoaded('posicion') ? [
                    'id' => $asignacion->posicion->id,
                    'numero' => $asignacion->posicion->numero,
                    'etiqueta' => $asignacion->posicion->etiqueta,
                    'activa' => $asignacion->posicion->activa,
                ] : null,
                'folio' => $asignacion->relationLoaded('folio') ? [
                    'id' => $asignacion->folio->id,
                    'numero_folio' => $asignacion->folio->numero_folio,
                    'tipo_bulto' => $asignacion->folio->tipo_bulto->value,
                    'estado_operacional' => $asignacion->folio->estado_operacional->value,
                    'condicion_termica' => $asignacion->folio->condicion_termica?->value,
                    'habilitacion_almacenamiento' => $asignacion->folio->habilitacion_almacenamiento?->value,
                    'variedad' => $asignacion->folio->variedad,
                    'calibre' => $asignacion->folio->calibre,
                    'marca' => $asignacion->folio->marca,
                    'exportadora' => $asignacion->folio->exportadora,
                    'cantidad_cajas' => isset($asignacion->folio->datos_externos['cantidad_cajas'])
                        ? (int) $asignacion->folio->datos_externos['cantidad_cajas']
                        : null,
                ] : null,
                'cargado_por' => $asignacion->relationLoaded('cargadoPor') ? [
                    'id' => $asignacion->cargadoPor->id,
                    'nombre' => $asignacion->cargadoPor->name,
                ] : null,
                'retirado_por' => $asignacion->relationLoaded('retiradoPor') && $asignacion->retiradoPor ? [
                    'id' => $asignacion->retiradoPor->id,
                    'nombre' => $asignacion->retiradoPor->name,
                ] : null,
            ])->values()),
            'eventos' => $this->whenLoaded('eventos', fn () => $this->eventos->map(fn ($evento) => [
                'id' => $evento->id,
                'operacion_id' => $evento->operacion_id,
                'tipo' => $evento->tipo->value,
                'ocurrido_at' => $evento->ocurrido_at?->toAtomString(),
                'datos' => $evento->datos,
                'observacion' => $evento->observacion,
                'usuario' => $evento->relationLoaded('usuario') ? [
                    'id' => $evento->usuario->id,
                    'nombre' => $evento->usuario->name,
                ] : null,
                'dispositivo' => $evento->relationLoaded('dispositivo') && $evento->dispositivo ? [
                    'id' => $evento->dispositivo->id,
                    'codigo' => $evento->dispositivo->codigo,
                    'nombre' => $evento->dispositivo->nombre,
                ] : null,
            ])->values()),
            // POEMP R-41: próxima lectura horaria, si ya hay lectura final y datos de la planilla.
            'registro_r41' => $this->whenLoaded('eventos', fn () => RegistroPrefrioR41::estado($this->resource, $this->eventos)),
            'creado_por' => $this->whenLoaded('creadoPor', fn () => [
                'id' => $this->creadoPor->id,
                'nombre' => $this->creadoPor->name,
            ]),
            'iniciado_por' => $this->whenLoaded('iniciadoPor', fn () => $this->iniciadoPor ? [
                'id' => $this->iniciadoPor->id,
                'nombre' => $this->iniciadoPor->name,
            ] : null),
            'finalizado_por' => $this->whenLoaded('finalizadoPor', fn () => $this->finalizadoPor ? [
                'id' => $this->finalizadoPor->id,
                'nombre' => $this->finalizadoPor->name,
            ] : null),
            'created_at' => $this->created_at?->toAtomString(),
            'updated_at' => $this->updated_at?->toAtomString(),
        ];
    }
}

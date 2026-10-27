<?php

namespace App\Http\Resources;

use App\Enums\EstadoValidacionPallet;
use App\Enums\ResultadoValidacionPallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ValidacionPalletResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'operacion_id' => $this->operacion_id,
            'numero_folio' => $this->numero_folio,
            'numero_intento' => $this->numero_intento,
            'tipo_bulto' => $this->tipo_bulto,
            'cantidad_cajas' => $this->cantidad_cajas,
            'linea_proceso' => $this->linea_proceso,
            'turno' => $this->turno,
            'temporada_id' => $this->temporada_id,
            'articulo_validacion_id' => $this->articulo_validacion_id,
            'origen_validacion_id' => $this->origen_validacion_id,
            'categoria_validacion_id' => $this->categoria_validacion_id,
            'resultado' => $this->resultado->value,
            'estado' => $this->estado->value,
            'motivo' => $this->motivo?->value,
            'observacion' => $this->observacion,
            'catalogo' => [
                'version_dispositivo' => $this->catalogo_version_dispositivo,
                'version_servidor' => $this->catalogo_version_servidor,
                'desactualizado' => $this->catalogo_version_dispositivo !== $this->catalogo_version_servidor,
                'temporada' => $this->snapshot['temporada'] ?? null,
                'articulo' => $this->snapshot['articulo'] ?? null,
                'origen' => $this->snapshot['origen'] ?? null,
                'composicion' => $this->snapshot['composicion'] ?? null,
                'fecha_embalaje' => $this->snapshot['fecha_embalaje'] ?? null,
                'categoria' => $this->snapshot['categoria'] ?? null,
            ],
            // La PDA partió con los datos de planta: huella de la copia y campos que corrigió el validador.
            'precarga_origen' => $this->snapshot['precarga_origen'] ?? null,
            'folio' => $this->whenLoaded('folio', fn () => $this->folio ? [
                'id' => $this->folio->id,
                'numero_folio' => $this->folio->numero_folio,
                'estado_operacional' => $this->folio->estado_operacional->value,
                'condicion_termica' => $this->folio->condicion_termica->value,
                'activo' => $this->folio->activo,
            ] : null),
            'puede_corregir' => $request->user()?->can('corregir-validaciones-pallet') === true
                && $this->estado === EstadoValidacionPallet::Aceptada
                && $this->resultado === ResultadoValidacionPallet::Aprobado
                && $this->relationLoaded('folio')
                && $this->folio !== null,
            'correcciones' => $this->whenLoaded(
                'correcciones',
                fn () => $this->correcciones->map(fn ($correccion): array => [
                    'id' => $correccion->id,
                    'motivo' => $correccion->motivo,
                    'corregido_at' => $correccion->corregido_at?->toAtomString(),
                    'corregido_por' => $correccion->relationLoaded('corregidoPor')
                        ? [
                            'id' => $correccion->corregidoPor?->id,
                            'nombre' => $correccion->corregidoPor?->name,
                        ]
                        : null,
                ])->values(),
            ),
            'usuario' => $this->whenLoaded('usuario', fn () => [
                'id' => $this->usuario->id,
                'nombre' => $this->usuario->name,
            ]),
            'dispositivo' => $this->whenLoaded('dispositivo', fn () => [
                'id' => $this->dispositivo->id,
                'codigo' => $this->dispositivo->codigo,
                'nombre' => $this->dispositivo->nombre,
            ]),
            'conflicto_con' => $this->whenLoaded('conflictoCon', fn () => $this->conflictoCon ? [
                'id' => $this->conflictoCon->id,
                'numero_folio' => $this->conflictoCon->numero_folio,
                'numero_intento' => $this->conflictoCon->numero_intento,
                'resultado' => $this->conflictoCon->resultado->value,
            ] : null),
            'generado_dispositivo_at' => $this->generado_dispositivo_at?->toAtomString(),
            'recibido_servidor_at' => $this->recibido_servidor_at?->toAtomString(),
        ];
    }
}

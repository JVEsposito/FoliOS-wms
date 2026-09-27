<?php

namespace App\Http\Resources;

use App\Enums\EstadoCarga;
use App\Enums\TipoBulto;
use App\Models\Folio;
use App\Models\UbicacionActual;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PosicionPlanoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $ubicaciones = $this->resource->relationLoaded('ubicacionesActuales')
            ? $this->ubicacionesActuales
            : collect();

        if ($ubicaciones->isEmpty() && $this->resource->relationLoaded('ubicacionActual') && $this->ubicacionActual) {
            $ubicaciones = collect([$this->ubicacionActual]);
        }

        $folios = $ubicaciones
            ->map(fn (UbicacionActual $ubicacion): ?array => $this->serializarFolio($ubicacion))
            ->filter()
            ->values();
        $reserva = $this->resource->relationLoaded('reservaTareaActiva')
            ? $this->reservaTareaActiva
            : null;

        return [
            'id' => $this->id,
            'banda' => $this->banda,
            'posicion' => $this->posicion,
            'nivel' => $this->nivel,
            'etiqueta' => $this->etiqueta,
            'estado' => $this->estado->value,
            'ocupada' => $folios->isNotEmpty(),
            'reservada' => $reserva !== null,
            'reserva_operacional' => $reserva ? [
                'id' => $reserva->id,
                'tarea_movimiento_id' => $reserva->tarea_movimiento_id,
                'prioridad' => $reserva->tareaMovimiento?->prioridad?->value,
                'responsable' => $reserva->usuario ? [
                    'id' => $reserva->usuario->id,
                    'nombre' => $reserva->usuario->name,
                ] : null,
                'dispositivo' => $reserva->dispositivo ? [
                    'id' => $reserva->dispositivo->id,
                    'nombre' => $reserva->dispositivo->nombre,
                ] : null,
                'vence_at' => $reserva->vence_at?->toAtomString(),
            ] : null,
            // Se conserva `folio` durante la transición para clientes anteriores de la API.
            'folio' => $folios->first(),
            'folios' => $folios->all(),
        ];
    }

    /**
     * Un folio PT de otra temporada que todavía figura en la cámara es un
     * registro que nadie cerró: la fruta ya no está, pero ocupa la posición
     * hasta que se regularice en Cierre de temporada.
     *
     * @return array{temporada: array{id: string, codigo: string|null, tipo: string|null}}|null
     */
    public static function registroSinCerrar(Folio $folio, Request $request): ?array
    {
        if ($folio->tipo_bulto === TipoBulto::Material || $folio->temporada_id === null) {
            return null;
        }

        if (! $request->attributes->has('plano.temporada_activa_id')) {
            $request->attributes->set(
                'plano.temporada_activa_id',
                app(ServicioTemporadaActiva::class)->buscar()?->id,
            );
        }
        $activa = $request->attributes->get('plano.temporada_activa_id');

        if ($activa === null || (string) $folio->temporada_id === (string) $activa) {
            return null;
        }

        $temporada = $folio->relationLoaded('temporada') ? $folio->temporada : null;

        return [
            'temporada' => [
                'id' => (string) $folio->temporada_id,
                'codigo' => $temporada?->codigo,
                'tipo' => $temporada?->tipo?->value,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializarFolio(UbicacionActual $ubicacion): ?array
    {
        $folio = $ubicacion->folio;

        if (! $folio) {
            return null;
        }

        $asignacionCarga = $folio->relationLoaded('asignacionCargaActual')
            ? $folio->asignacionCargaActual
            : null;
        $carga = $asignacionCarga?->relationLoaded('carga')
            ? $asignacionCarga->carga
            : null;

        if ($carga && ! in_array($carga->estado, EstadoCarga::visiblesEnOperacion(), true)) {
            $carga = null;
        }

        return [
            'id' => $folio->id,
            'numero_folio' => $folio->numero_folio,
            'tipo_bulto' => $folio->tipo_bulto->value,
            'estado_operacional' => $folio->estado_operacional->value,
            'condicion_sag' => $folio->condicionSag ? [
                'id' => $folio->condicionSag->id,
                'codigo' => $folio->condicionSag->codigo,
                'nombre' => $folio->condicionSag->nombre,
            ] : null,
            'fecha_ingreso' => $folio->fecha_ingreso?->toAtomString(),
            'variedad' => $folio->variedad,
            'calibre' => $folio->calibre,
            'marca' => $folio->marca,
            'exportadora' => $folio->exportadora,
            'material' => $folio->relationLoaded('material') && $folio->material ? [
                'item' => [
                    'id' => $folio->material->item->id,
                    'cliente' => [
                        'id' => $folio->material->item->cliente->id,
                        'temporada' => [
                            'id' => $folio->material->item->cliente->temporada->id,
                            'codigo' => $folio->material->item->cliente->temporada->codigo,
                            'nombre' => $folio->material->item->cliente->temporada->nombre,
                            'activa' => $folio->material->item->cliente->temporada->activa,
                        ],
                        'codigo' => $folio->material->item->cliente->codigo,
                        'nombre' => $folio->material->item->cliente->nombre,
                        'activo' => $folio->material->item->cliente->activo,
                    ],
                    'codigo' => $folio->material->item->codigo,
                    'nombre' => $folio->material->item->nombre,
                    'categoria' => $folio->material->item->categoria,
                ],
                'cantidad_inicial' => $folio->material->cantidad_inicial,
                'cantidad_actual' => $folio->material->cantidad_actual,
                'cantidad_reservada' => $folio->material->cantidad_reservada,
                'cantidad_disponible' => number_format(
                    max(0, (float) $folio->material->cantidad_actual - (float) $folio->material->cantidad_reservada),
                    3,
                    '.',
                    '',
                ),
                'unidad_medida' => $folio->material->unidad_medida,
                'lote' => $folio->material->lote,
                'proveedor' => $folio->material->proveedor,
                'observacion' => $folio->material->observacion,
            ] : null,
            'ubicado_at' => $ubicacion->ubicado_at?->toAtomString(),
            'registro_sin_cerrar' => self::registroSinCerrar($folio, request()),
            'carga_actual' => $carga ? [
                'id' => $carga->id,
                'codigo' => $carga->codigo,
                'estado' => $carga->estado->value,
                'prioridad' => $carga->prioridad->value,
                'version' => $carga->version,
            ] : null,
        ];
    }
}

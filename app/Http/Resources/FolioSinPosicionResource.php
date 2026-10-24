<?php

namespace App\Http\Resources;

use App\Enums\EstadoCarga;
use App\Services\Materiales\StockMaterialEnPlano;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FolioSinPosicionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $folio = $this->folio;
        $asignacionCarga = $folio?->relationLoaded('asignacionCargaActual')
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
            'registro_sin_cerrar' => PosicionPlanoResource::registroSinCerrar($folio, $request),
            'material' => $folio->material ? [
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
                'cantidad_actual' => app(StockMaterialEnPlano::class)->cantidades($folio->id, $this->camara_id, null)['actual'],
                'cantidad_reservada' => app(StockMaterialEnPlano::class)->cantidades($folio->id, $this->camara_id, null)['reservada'],
                'cantidad_disponible' => number_format(
                    max(0, (float) app(StockMaterialEnPlano::class)->cantidades($folio->id, $this->camara_id, null)['actual'] - (float) app(StockMaterialEnPlano::class)->cantidades($folio->id, $this->camara_id, null)['reservada']),
                    3,
                    '.',
                    '',
                ),
                'unidad_medida' => $folio->material->unidad_medida,
                'lote' => $folio->material->lote,
                'proveedor' => $folio->material->proveedor,
                'observacion' => $folio->material->observacion,
            ] : null,
            'ubicado_at' => $this->ubicado_at?->toAtomString(),
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

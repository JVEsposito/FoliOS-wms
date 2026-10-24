<?php

namespace App\Services\Materiales;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** La cámara muestra su existencia física, no lo entregado a otros almacenes. */
class StockMaterialEnPlano
{
    private array $camaras = [];

    private ?Request $solicitud = null;

    public function cantidades(string $folioId, string $camaraId, ?string $posicionId): array
    {
        if ($this->solicitud !== request()) {
            $this->solicitud = request();
            $this->camaras = [];
        }
        if (! isset($this->camaras[$camaraId])) {
            $this->camaras[$camaraId] = DB::table('saldos_materiales_almacenes as s')->join('destinos_materiales as a', 'a.id', '=', 's.almacen_material_id')
                ->where('s.camara_id', $camaraId)->where('a.tipo', 'fisica')->where('s.cantidad_actual', '>', 0)
                ->selectRaw('s.folio_id, s.posicion_id, SUM(s.cantidad_actual) as actual, SUM(s.cantidad_reservada) as reservada')
                ->groupBy('s.folio_id', 's.posicion_id')->get()->keyBy(fn ($r) => $r->folio_id.':'.($r->posicion_id ?? ''))->all();
        }
        $fila = $this->camaras[$camaraId][$folioId.':'.($posicionId ?? '')] ?? null;

        return ['actual' => number_format((float) ($fila?->actual ?? 0), 3, '.', ''),
            'reservada' => number_format((float) ($fila?->reservada ?? 0), 3, '.', '')];
    }
}

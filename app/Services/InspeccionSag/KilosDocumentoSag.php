<?php

namespace App\Services\InspeccionSag;

use App\Models\ArticuloValidacion;
use App\Models\CombinacionValidacion;
use App\Models\EnvaseValidacion;
use App\Models\Folio;

class KilosDocumentoSag
{
    private array $envases = [];

    public function calcular(Folio $folio): ?float
    {
        $datos = $folio->datos_externos ?? [];
        $lineas = $datos['composicion'] ?? [];
        $lineas = $lineas ?: [$datos];
        $cajas = 0;
        $peso = 0;
        foreach ($lineas as $linea) {
            $cantidad = (int) ($linea['cantidad_cajas'] ?? 0);
            $envase = $linea['envase_validacion_id'] ?? $datos['envase_validacion_id'] ?? null;
            if (! $envase) {
                $articulo = $linea['articulo_validacion_id'] ?? $datos['articulo_validacion_id'] ?? null;
                $combinacion = $linea['combinacion_validacion_id'] ?? $datos['combinacion_validacion_id'] ?? null;
                $articulo ??= $combinacion ? CombinacionValidacion::whereKey($combinacion)->value('articulo_validacion_id') : null;
                $envase = $articulo ? ArticuloValidacion::whereKey($articulo)->value('envase_validacion_id') : null;
            }
            if (! $envase || $cantidad <= 0) {
                return null;
            }
            if (! array_key_exists($envase, $this->envases)) {
                $this->envases[$envase] = EnvaseValidacion::whereKey($envase)->value('kilos_netos_por_caja');
            }
            $unitario = (float) $this->envases[$envase];
            if ($unitario <= 0) {
                return null;
            }
            $cajas += $cantidad;
            $peso += (int) round($unitario * 10000) * $cantidad;
        }

        return $cajas === (int) ($datos['cantidad_cajas'] ?? 0) ? $peso / 10000 : null;
    }
}

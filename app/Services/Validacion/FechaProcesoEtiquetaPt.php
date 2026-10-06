<?php

namespace App\Services\Validacion;

use App\Models\Folio;

class FechaProcesoEtiquetaPt
{
    public function deFolio(Folio $folio): ?string
    {
        if ($folio->origen_sistema === 'repaletizaje') {
            return $folio->fecha_proceso_pt?->toDateString();
        }

        return $this->deDatos($folio->datos_externos ?? [])
            ?? $this->deDatos($folio->validacionPallet()->first(['snapshot'])?->snapshot ?? []);
    }

    public function deDatos(array $datos): ?string
    {
        $fecha = $datos['fecha_proceso'] ?? $datos['fecha_embalaje'] ?? null;
        if ($fecha) {
            return $this->fecha($fecha);
        }
        $fechas = array_map(fn ($linea) => $this->fecha($linea['fecha_proceso'] ?? $linea['fecha_embalaje'] ?? null), $datos['composicion'] ?? []);

        return $this->masAntigua($fechas);
    }

    public function masAntigua(array $fechas): ?string
    {
        // Una fecha desconocida impide asegurar cuál es la más antigua.
        return $fechas === [] || in_array(null, $fechas, true) ? null : min($fechas);
    }

    public function fecha(mixed $valor): ?string
    {
        if (! is_string($valor) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $valor, $partes)) {
            return null;
        }

        return checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1]) ? $valor : null;
    }
}

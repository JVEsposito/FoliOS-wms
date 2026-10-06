<?php

namespace App\Services\MateriaPrima;

use Illuminate\Validation\ValidationException;

class ControlCloroHidrocooler
{
    public function rango(): ?array
    {
        $min = config('hidrocooler.cloro_min_ppm');
        $max = config('hidrocooler.cloro_max_ppm');
        if (blank($min) && blank($max)) {
            return null;
        }
        if (! is_numeric($min) || ! is_numeric($max) || $min < 0 || $max > 500 || $min > $max) {
            throw ValidationException::withMessages(['cloro_libre_ppm' => 'La configuración del rango SOP de cloro está incompleta o es inválida. Solicita su revisión.']);
        }

        return ['min' => (float) $min, 'max' => (float) $max];
    }

    public function validar(array $datos): ?array
    {
        $rango = $this->rango();
        if ($rango === null) {
            return null;
        }
        $inicial = (float) $datos['cloro_libre_ppm'];
        $correccion = $datos['correccion_cloro_ppm'] ?? null;
        if (($inicial < $rango['min'] || $inicial > $rango['max']) && $correccion === null) {
            throw ValidationException::withMessages(['correccion_cloro_ppm' => 'Registra la lectura después de corregir el cloro fuera del rango SOP.']);
        }
        if ($correccion !== null && ($correccion < $rango['min'] || $correccion > $rango['max'])) {
            throw ValidationException::withMessages(['correccion_cloro_ppm' => 'La lectura después de corregir debe estar dentro del rango SOP.']);
        }

        return $rango;
    }
}

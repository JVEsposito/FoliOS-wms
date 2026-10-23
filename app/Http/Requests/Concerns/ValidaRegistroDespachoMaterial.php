<?php

namespace App\Http\Requests\Concerns;

trait ValidaRegistroDespachoMaterial
{
    private function reglasRegistro(): array
    {
        return [
            'registro' => ['sometimes', 'array:fecha_despacho,provisional,numero_documento,fecha_documento,motivo,motivo_autorizacion,aceptar_fuera_plazo'],
            'registro.fecha_despacho' => ['sometimes', 'date'],
            'registro.provisional' => ['sometimes', 'boolean'],
            'registro.numero_documento' => ['nullable', 'string', 'max:50'],
            'registro.fecha_documento' => ['nullable', 'date_format:Y-m-d'],
            'registro.motivo' => ['nullable', 'string', 'min:5', 'max:1000'],
            'registro.motivo_autorizacion' => ['nullable', 'string', 'min:5', 'max:1000'],
            'registro.aceptar_fuera_plazo' => ['sometimes', 'boolean'],
        ];
    }
}

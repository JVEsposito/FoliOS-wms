<?php

namespace App\Http\Requests;

use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use Illuminate\Foundation\Http\FormRequest;

class CerrarDespachoFrigorificoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()
            && app(AlcanceOperacionalUsuario::class)
                ->puedeCerrarDespachoFrigorifico($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $campos = ['numero_guia', 'orden_embarque', 'booking', 'sps', 'dus', 'sello_sag', 'contenedor', 'destino', 'puerto_embarque'];
        $reglas = ['datos_embarque' => ['nullable', 'array:'.implode(',', $campos)]];
        foreach ($campos as $campo) {
            $reglas['datos_embarque.'.$campo] = ['nullable', 'string', $campo === 'destino' ? 'max:250' : 'max:150'];
        }

        return [
            ...$reglas,
            'operacion_id' => ['required', 'uuid'],
            'patente' => ['required', 'string', 'max:20'],
            'conductor' => ['required', 'string', 'max:150'],
            'ocurrido_at' => ['nullable', 'date', 'before_or_equal:now'],
            'observacion' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

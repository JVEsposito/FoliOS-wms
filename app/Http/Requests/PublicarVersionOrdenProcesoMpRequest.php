<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PublicarVersionOrdenProcesoMpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar-ordenes-proceso') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'version' => ['required', 'integer', 'min:1'],
            'confirmar_reduccion' => ['sometimes', 'boolean'],
            'motivo_cambio' => ['required', 'string', 'min:3', 'max:500'],
            ...CrearOrdenProcesoMpRequest::reglasPlan(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo_cambio.required' => 'Indica el motivo del cambio para publicar una nueva versión.',
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AsignarCamaraLoteMateriaPrimaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar-lotes-materia-prima') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'camara_id' => ['required', 'uuid', 'exists:camaras,id'],
            'observacion' => ['nullable', 'string', 'max:2000'],
            // Hora real del ingreso a cámara cuando la digitación se hace después (hora de planta).
            'ingresado_at' => ['nullable', 'date_format:Y-m-d\\TH:i'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['ingresado_at.date_format' => 'Indica la fecha y hora reales del ingreso a cámara.'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'observacion' => filled($this->input('observacion'))
                ? trim((string) $this->input('observacion'))
                : null,
            'ingresado_at' => filled($this->input('ingresado_at')) ? (string) $this->input('ingresado_at') : null,
        ]);
    }
}

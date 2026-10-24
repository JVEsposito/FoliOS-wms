<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidaRegistroDespachoMaterial;
use Illuminate\Foundation\Http\FormRequest;

class RetirarDespachoMaterialRequest extends FormRequest
{
    use ValidaRegistroDespachoMaterial;

    public function authorize(): bool
    {
        return $this->user()?->can('retirar-materiales') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->reglasRegistro(),
            'operacion_id' => ['required', 'uuid'],
            'motivo_excepcion_fifo' => ['nullable', 'string', 'min:5', 'max:1000'],
            'retiros' => ['required', 'array', 'min:1', 'max:100'],
            'retiros.*' => ['required', 'array:folio_id,cantidad,sesion_estiba_id'],
            'retiros.*.folio_id' => [
                'required',
                'uuid',
                'distinct',
                'exists:folios_materiales,folio_id',
            ],
            'retiros.*.cantidad' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'retiros.*.sesion_estiba_id' => [
                'nullable',
                'uuid',
                'exists:sesiones_estiba,id',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'motivo_excepcion_fifo' => $this->filled('motivo_excepcion_fifo')
                ? trim((string) $this->input('motivo_excepcion_fifo'))
                : null,
        ]);
    }
}

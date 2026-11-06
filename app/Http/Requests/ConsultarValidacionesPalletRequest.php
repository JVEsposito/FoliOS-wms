<?php

namespace App\Http\Requests;

use App\Enums\EstadoValidacionPallet;
use App\Enums\ResultadoValidacionPallet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConsultarValidacionesPalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('consultar-validaciones-pallet') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'temporada_id' => ['nullable', 'uuid', 'exists:temporadas,id'],
            'folio' => ['nullable', 'string', 'max:50'],
            'cliente' => ['nullable', 'string', 'max:255'],
            'resultado' => ['nullable', Rule::enum(ResultadoValidacionPallet::class)],
            'estado' => ['nullable', Rule::enum(EstadoValidacionPallet::class)],
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'linea_proceso' => ['nullable', 'integer', Rule::in([1, 2, 3])],
            'turno' => ['nullable', 'string', Rule::in(['A', 'B'])],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('folio')) {
            $this->merge([
                'folio' => mb_strtoupper(trim((string) $this->input('folio'))),
            ]);
        }

        if ($this->filled('turno')) {
            $this->merge([
                'turno' => mb_strtoupper(trim((string) $this->input('turno'))),
            ]);
        }
    }
}

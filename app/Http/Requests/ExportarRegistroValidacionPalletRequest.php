<?php

namespace App\Http\Requests;

use App\Enums\EstadoValidacionPallet;
use App\Enums\ResultadoValidacionPallet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportarRegistroValidacionPalletRequest extends FormRequest
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
            'temporada_id' => ['required', 'uuid', 'exists:temporadas,id'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'folio' => ['nullable', 'string', 'max:50'],
            'cliente' => ['nullable', 'string', 'max:255'],
            'formato' => ['nullable', Rule::in(['xlsx', 'pdf'])],
            'estado' => ['exclude_unless:formato,pdf', 'nullable', Rule::enum(EstadoValidacionPallet::class)],
            'resultado' => ['nullable', Rule::enum(ResultadoValidacionPallet::class)],
            'linea_proceso' => ['nullable', 'integer', Rule::in([1, 2, 3])],
            'turno' => ['nullable', 'string', Rule::in(['A', 'B'])],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'folio' => $this->filled('folio')
                ? mb_strtoupper(trim((string) $this->input('folio')))
                : null,
            'turno' => $this->filled('turno')
                ? mb_strtoupper(trim((string) $this->input('turno')))
                : null,
        ]);
    }
}

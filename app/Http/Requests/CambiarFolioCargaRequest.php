<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CambiarFolioCargaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar-cargas') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version_esperada' => ['required', 'integer', 'min:1'],
            'numero_folio_entrante' => ['required', 'string', 'max:50'],
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
            'confirmar_diferencias' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['numero_folio_entrante' => 'folio de reemplazo'];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegistrarRetornoCargaRequest extends FormRequest
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
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
            // Sin lista, retorna todo lo despachado.
            'folios' => ['sometimes', 'array', 'min:1', 'max:26'],
            'folios.*' => ['required', 'uuid', 'distinct'],
            'llegada_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:now'],
        ];
    }
}

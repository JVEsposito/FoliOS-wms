<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AgregarFoliosCargaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar-cargas') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'folios' => ['required', 'array', 'min:1', 'max:26'],
            'folios.*' => ['required', 'string', 'max:100', 'distinct'],
            'version_esperada' => ['required', 'integer', 'min:1'],
            'instructivo_embarque_id' => ['nullable', 'uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! is_array($this->input('folios'))) {
            return;
        }

        $this->merge([
            'folios' => collect($this->input('folios'))
                ->map(fn (mixed $folio): mixed => is_string($folio) ? trim($folio) : $folio)
                ->all(),
        ]);
    }
}

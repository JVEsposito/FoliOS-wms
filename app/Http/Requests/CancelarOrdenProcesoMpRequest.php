<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelarOrdenProcesoMpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cancelar-ordenes-proceso') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}

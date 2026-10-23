<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmarRecepcionMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('confirmar-recepciones-materiales') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'version_conocida' => ['required', 'integer', 'min:1'],
        ];
    }
}

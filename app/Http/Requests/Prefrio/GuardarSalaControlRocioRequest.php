<?php

namespace App\Http\Requests\Prefrio;

use Illuminate\Foundation\Http\FormRequest;

class GuardarSalaControlRocioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('supervisar-prefrio') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'min:2', 'max:80'],
            'activa' => ['sometimes', 'boolean'],
            'version_conocida' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

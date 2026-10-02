<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarFormatoRegistroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administrar-accesos') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'codigo' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique('formatos_registro', 'codigo')->ignore($this->route('formatoRegistro')?->id)],
            'nombre' => ['required', 'string', 'max:150'],
            'version' => ['required', 'string', 'max:20'],
            'fecha_vigencia' => ['required', 'date_format:Y-m-d'],
            'localidad' => ['required', 'string', 'max:255'],
            'activo' => ['required', 'boolean'],
            'actualizado_at_conocido' => [$this->route('formatoRegistro') ? 'required' : 'nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'codigo' => mb_strtoupper(trim((string) $this->input('codigo'))),
            'nombre' => trim((string) $this->input('nombre')),
            'version' => trim((string) $this->input('version')),
            'localidad' => trim((string) $this->input('localidad')),
        ]);
    }
}

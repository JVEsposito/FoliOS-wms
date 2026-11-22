<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreguntaAsistenteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('usar-asistente') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['texto' => trim((string) $this->input('texto', ''))]);
    }

    public function rules(): array
    {
        return ['texto' => ['required', 'string', 'max:500']];
    }

    public function messages(): array
    {
        return [
            'texto.required' => 'Escribe una pregunta.',
            'texto.max' => 'La pregunta no puede tener más de 500 caracteres.',
        ];
    }
}

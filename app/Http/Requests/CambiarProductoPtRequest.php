<?php

namespace App\Http\Requests;

/** Mismas reglas que la corrección de Validación, con permiso de administración y motivo. */
class CambiarProductoPtRequest extends CorregirValidacionPalletRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cambiar-atributos-pt') === true;
    }

    protected function prepareForValidation(): void
    {
        // El diálogo compartido envía «motivo_correccion»; el módulo lo registra como «motivo».
        if (! $this->has('motivo') && $this->has('motivo_correccion')) {
            $this->merge(['motivo' => $this->input('motivo_correccion')]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'motivo' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'motivo.required' => 'Indica el motivo del cambio.',
            'motivo.min' => 'Describe el motivo con al menos 10 caracteres.',
        ];
    }
}

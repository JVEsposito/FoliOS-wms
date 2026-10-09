<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransferirClienteMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transferir-folios-materiales-clientes') === true;
    }

    public function rules(): array
    {
        return ['operacion_id' => ['required', 'uuid'], 'cliente_destino_id' => ['required', 'uuid', 'exists:clientes,id'],
            'item_destino_id' => ['required', 'uuid', 'exists:items_materiales,id'],
            'cantidad' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:99999999999.999'],
            'motivo' => ['required', 'string', 'min:10', 'max:2000'], 'documento_respaldo' => ['nullable', 'string', 'max:150']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['motivo' => trim((string) $this->input('motivo')),
            'documento_respaldo' => trim((string) $this->input('documento_respaldo')) ?: null]);
    }
}

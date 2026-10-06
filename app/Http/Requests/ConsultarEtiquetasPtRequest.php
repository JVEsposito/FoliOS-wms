<?php

namespace App\Http\Requests;

use App\Models\PersonalAccessToken;
use Illuminate\Validation\Rule;

class ConsultarEtiquetasPtRequest extends ConsultarValidacionesPalletRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'origen' => ['nullable', Rule::in(['validacion', 'repaletizaje'])]];
    }

    public function authorize(): bool
    {
        $token = $this->user()?->currentAccessToken();

        return $this->user()?->can('imprimir-etiquetas-pt') === true && $token instanceof PersonalAccessToken
            && $token->dispositivo_id === null && in_array('oficina', $token->abilities, true);
    }
}

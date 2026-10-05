<?php

namespace App\Http\Requests;

use App\Models\PersonalAccessToken;

class ConsultarEtiquetasPtRequest extends ConsultarValidacionesPalletRequest
{
    public function authorize(): bool
    {
        $token = $this->user()?->currentAccessToken();

        return parent::authorize() && $token instanceof PersonalAccessToken
            && $token->dispositivo_id === null && in_array('oficina', $token->abilities, true);
    }
}

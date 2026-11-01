<?php

namespace App\Http\Requests;

use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use Illuminate\Foundation\Http\FormRequest;

class DeshacerFolioCargadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()
            && app(AlcanceOperacionalUsuario::class)->puedeEnviarFoliosAnden($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}

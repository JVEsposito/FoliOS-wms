<?php

namespace App\Http\Requests;

use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use Illuminate\Foundation\Http\FormRequest;

class RegistrarFolioCargadoRequest extends FormRequest
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
            'numero_folio' => ['required', 'string', 'max:50'],
            'generado_dispositivo_at' => ['required', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['numero_folio' => 'folio'];
    }
}

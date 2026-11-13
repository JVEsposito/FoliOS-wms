<?php

namespace App\Http\Requests;

class ConfirmarRetiroParcialPrefrioRequest extends AccionProcesoPrefrioRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $reglas = parent::rules();
        unset($reglas['datos']);

        return [
            ...$reglas,
            'folios' => ['required', 'array', 'min:1', 'max:100'],
            'folios.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}

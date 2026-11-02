<?php

namespace App\Http\Requests;

use App\Enums\TipoResolucionIncidenciaCarga;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolverIncidenciaCargaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $usuario = $this->user();

        if (! $usuario) {
            return false;
        }

        $alcance = app(AlcanceOperacionalUsuario::class);

        return $alcance->puedeResolverReparacionCarga($usuario)
            || $alcance->puedeResolverComercialmenteCarga($usuario);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'resolucion' => ['required', Rule::enum(TipoResolucionIncidenciaCarga::class)->except([TipoResolucionIncidenciaCarga::RetiradoDeCarga])],
            'folio_reemplazo_id' => [
                'nullable',
                'required_if:resolucion,reemplazo',
                'uuid',
                'exists:folios,id',
            ],
            'observacion' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Enums\CondicionTermicaFolio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinalizarParcialProcesoPrefrioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('supervisar-prefrio') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'version_conocida' => ['required', 'integer', 'min:0'],
            'ocurrido_at' => ['required', 'date'],
            'motivo' => ['required', 'string', 'min:3', 'max:100'],
            'resultados' => ['required', 'array', 'min:1', 'max:100'],
            'resultados.*' => ['required', 'array:folio_id,resultado,temperatura_final,motivo'],
            'resultados.*.folio_id' => ['required', 'uuid', 'distinct'],
            'resultados.*.resultado' => ['required', Rule::enum(CondicionTermicaFolio::class)->only([
                CondicionTermicaFolio::PrefrioAprobado,
                CondicionTermicaFolio::PendientePrefrio,
                CondicionTermicaFolio::RequiereReproceso,
                CondicionTermicaFolio::Retenido,
            ])],
            'resultados.*.temperatura_final' => ['nullable', 'numeric', 'between:-20,50'],
            'resultados.*.motivo' => ['nullable', 'string', 'min:3', 'max:100'],
        ];
    }
}

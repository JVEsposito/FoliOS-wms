<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

/**
 * Etiquetas de una orden de transformación: folios de salida ya producidos
 * (`folio_ids`) o folios reservados al planificar que aún no existen (`reserva_ids`).
 */
class GenerarEtiquetasTransformacionMaterialRequest extends GenerarEtiquetasMaterialRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'folio_ids' => ['required_without:reserva_ids', 'array', 'max:500'],
            'reserva_ids' => ['required_without:folio_ids', 'array', 'max:500'],
            'reserva_ids.*' => [
                'required',
                'uuid',
                'distinct',
                'exists:folios_salida_reservados_transformacion,id',
            ],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                $total = count((array) $this->input('folio_ids', []))
                    + count((array) $this->input('reserva_ids', []));
                if ($total > 500) {
                    $validator->errors()->add('folio_ids', 'Puedes generar hasta 500 etiquetas por vez.');
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'folio_ids.required_without' => 'Selecciona al menos un folio para etiquetar.',
            'reserva_ids.required_without' => 'Selecciona al menos un folio para etiquetar.',
        ];
    }
}

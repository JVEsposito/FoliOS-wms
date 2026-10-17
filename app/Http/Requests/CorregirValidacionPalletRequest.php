<?php

namespace App\Http\Requests;

use App\Enums\TipoBulto;
use App\Services\Validacion\ProyeccionTrazabilidadFolio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CorregirValidacionPalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('corregir-validaciones-pallet') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'tipo_bulto' => [
                'required',
                Rule::in([TipoBulto::Pallet->value, TipoBulto::Saldo->value]),
            ],
            'cantidad_cajas' => ['required', 'integer', 'min:1'],
            'linea_proceso' => ['required', 'integer', Rule::in([1, 2, 3])],
            'turno' => ['required', 'string', Rule::in(['A', 'B'])],
            'articulo_validacion_id' => [
                'required',
                'uuid',
                'exists:articulos_validacion,id',
            ],
            // Compatibilidad: sin composición, la corrección es de un solo CSG.
            'origen_validacion_id' => [
                'required_without:composicion',
                'nullable',
                'uuid',
                'exists:origenes_validacion,id',
            ],
            // Con composición, reemplaza todas las líneas del bulto: mismas reglas que la PDA.
            'composicion' => ['sometimes', 'array', 'min:1', 'max:20'],
            'composicion.*.origen_validacion_id' => ['required', 'uuid', 'exists:origenes_validacion,id'],
            'composicion.*.cantidad_cajas' => ['required', 'integer', 'min:1'],
            'composicion.*.lote_materia_prima' => [
                config('validacion.exigir_lote_proceso') ? 'required' : 'nullable',
                'string',
                'max:80',
            ],
            'composicion.*.proceso_packing' => [
                config('validacion.exigir_lote_proceso') ? 'required' : 'nullable',
                'string',
                'max:80',
            ],
            'categoria_validacion_id' => [
                'required',
                'uuid',
                'exists:categorias_validacion,id',
            ],
            'motivo_correccion' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'composicion.*.origen_validacion_id.required' => 'Elige el CSG de cada línea.',
            'composicion.*.cantidad_cajas.min' => 'Cada línea debe tener al menos una caja.',
            'composicion.*.lote_materia_prima.required' => 'Ingresa el lote de materia prima de cada línea.',
            'composicion.*.proceso_packing.required' => 'Ingresa el proceso de packing de cada línea.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $claves = collect($this->input('composicion', []))
                ->filter(fn (mixed $linea): bool => is_array($linea))
                ->map(fn (array $linea): string => implode('|', [
                    (string) ($linea['origen_validacion_id'] ?? ''),
                    ProyeccionTrazabilidadFolio::normalizarCodigo($linea['lote_materia_prima'] ?? null),
                    ProyeccionTrazabilidadFolio::normalizarCodigo($linea['proceso_packing'] ?? null),
                ]));

            if ($claves->duplicates()->isNotEmpty()) {
                $validator->errors()->add(
                    'composicion',
                    'No repitas la misma combinación de CSG, lote y proceso en el bulto.',
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'turno' => mb_strtoupper(trim((string) $this->input('turno'))),
            'motivo_correccion' => trim((string) $this->input('motivo_correccion')),
        ]);
    }
}

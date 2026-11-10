<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RegistrarRepaletizajeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $zona = config('app.operational_timezone');
        $fecha = $this->input('fecha_operacional');
        $this->merge([
            'modalidad' => $this->input('modalidad', 'consolidacion'),
            'turno' => mb_strtoupper(trim((string) $this->input('turno'))),
            'fecha_operacional' => match ($fecha) {
                'hoy' => now($zona)->toDateString(),
                'ayer' => now($zona)->subDay()->toDateString(),
                default => $fecha,
            },
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $hoy = now(config('app.operational_timezone'));

        return [
            'operacion_id' => ['required', 'uuid'],
            'modalidad' => ['required', Rule::in(['consolidacion', 'cambio_folio', 'division', 'reembalaje'])],
            'tipo_resultado' => ['nullable', 'required_if:modalidad,consolidacion', Rule::in(['pallet', 'saldo'])],
            'estrategia_folio' => ['nullable', 'required_if:modalidad,consolidacion', Rule::in(['conservar', 'nuevo'])],
            'numero_folio_resultante' => ['nullable', 'required_if:modalidad,consolidacion', 'string', 'max:80'],
            'folio_conservado_id' => [
                'nullable',
                'uuid',
                'required_if:estrategia_folio,conservar',
                Rule::exists('folios', 'id'),
            ],
            'cantidad_objetivo' => [
                'nullable',
                'integer',
                'min:1',
                'max:100000',
                'required_if:tipo_resultado,pallet',
            ],
            'origenes' => ['required', 'array', 'min:1', 'max:20'],
            'origenes.*.folio_id' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('folios', 'id'),
            ],
            'origenes.*.cantidad_aportada' => ['required', 'integer', 'min:1', 'max:100000'],
            'origenes.*.composicion' => ['nullable', 'array', 'min:1', 'max:100'],
            'origenes.*.composicion.*.clave' => [
                'required_with:origenes.*.composicion',
                'string',
                'max:500',
            ],
            'origenes.*.composicion.*.cantidad_aportada' => [
                'required_with:origenes.*.composicion',
                'integer',
                'min:1',
                'max:100000',
            ],
            'resultados' => ['nullable', 'required_unless:modalidad,consolidacion', 'array', 'min:1', ($this->input('modalidad') === 'reembalaje' ? 'max:20' : 'max:2')],
            'huella_reembalaje' => ['nullable', 'required_if:modalidad,reembalaje', 'string', 'size:64'],
            'resultados.*.envase_validacion_id' => ['nullable', 'required_if:modalidad,reembalaje', 'uuid'],
            'mermas' => ['nullable', 'array', 'max:100'],
            'mermas.*.clave' => ['required', 'string', 'max:500'],
            'mermas.*.kilos' => ['required', 'numeric', 'min:0', 'max:99999999999', 'decimal:0,4'],
            'motivo_merma' => ['nullable', 'string', 'max:1000'],
            'resultados.*.numero_folio' => ['required_with:resultados', 'string', 'max:80', 'distinct'],
            'resultados.*.tipo_resultado' => ['required_with:resultados', Rule::in(['pallet', 'saldo'])],
            'resultados.*.cantidad_objetivo' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'resultados.*.cantidad_resultante' => ['required_with:resultados', 'integer', 'min:1', 'max:100000'],
            'resultados.*.composicion' => ['nullable', 'array', 'min:1', 'max:100'],
            'resultados.*.composicion.*.clave' => ['required_with:resultados.*.composicion', 'string', 'max:500'],
            'resultados.*.composicion.*.cantidad_cajas' => ['required_with:resultados.*.composicion', 'integer', 'min:1', 'max:100000'],
            'observacion' => ['nullable', 'string', 'max:2000'],
            // El registro RRPL-01 agrupa por fecha operacional, turno y tarjador.
            'turno' => ['required', 'string', Rule::in(['A', 'B'])],
            // Solo el turno de noche puede declarar la fecha del día anterior.
            'fecha_operacional' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:'.($this->input('turno') === 'B' ? $hoy->copy()->subDay()->toDateString() : $hoy->toDateString()),
                'before_or_equal:'.$hoy->toDateString(),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cantidad_objetivo.min' => 'La capacidad debe ser de al menos una caja.',
            'resultados.*.cantidad_objetivo.min' => 'La capacidad de cada resultado debe ser de al menos una caja.',
            'origenes.*.composicion.min' => 'La composición de cada origen debe incluir al menos una línea.',
            'resultados.*.composicion.min' => 'La composición de cada resultado debe incluir al menos una línea.',
            'turno.required' => 'Indica el turno del repaletizaje.',
            'turno.in' => 'El turno debe ser A o B.',
            'fecha_operacional.after_or_equal' => 'La fecha debe ser hoy; solo el turno B puede registrar ayer.',
            'fecha_operacional.before_or_equal' => 'La fecha operacional no puede ser futura.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $modalidad = $this->input('modalidad', 'consolidacion');
            $origenes = $this->input('origenes', []);
            $resultados = $this->input('resultados', []);

            if ($modalidad === 'consolidacion' && count($origenes) < 2) {
                $validator->errors()->add('origenes', 'La consolidación requiere al menos dos folios de origen.');
            }
            if ($modalidad !== 'consolidacion' && count($origenes) !== 1) {
                $validator->errors()->add('origenes', 'Esta modalidad requiere exactamente un folio de origen.');
            }
            $esperados = $modalidad === 'division' ? 2 : ($modalidad === 'cambio_folio' ? 1 : null);
            if ($esperados !== null && count($resultados) !== $esperados) {
                $validator->errors()->add('resultados', "La modalidad seleccionada requiere {$esperados} resultado(s).");
            }
        }];
    }
}

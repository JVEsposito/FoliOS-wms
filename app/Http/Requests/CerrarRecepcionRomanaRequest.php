<?php

namespace App\Http\Requests;

use App\Enums\TipoEnvaseRomana;
use App\Enums\TipoRecepcionRomana;
use App\Models\RecepcionRomana;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CerrarRecepcionRomanaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('operar-romana') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $recepcion = $this->route('recepcion');
        $esPesajeEnvases = $recepcion instanceof RecepcionRomana
            && $recepcion->tipo_recepcion === TipoRecepcionRomana::FrutaPesajeEnvases;
        $esSoloEnvases = $recepcion instanceof RecepcionRomana
            && $recepcion->tipo_recepcion === TipoRecepcionRomana::SoloEnvases;
        $requiereDestare = ! $esPesajeEnvases && ! $esSoloEnvases;

        return [
            'operacion_id' => ['required', 'uuid'],
            'peso_tara' => [
                'nullable',
                Rule::requiredIf($requiereDestare),
                'numeric',
                'min:1',
                'max:200000',
                'decimal:0,2',
            ],
            'tipo_envase_calculo_neto' => ['nullable', Rule::enum(TipoEnvaseRomana::class)],
            'modo_salida_envases' => [
                Rule::requiredIf($requiereDestare),
                Rule::in(['mismos', 'diferentes', 'vacio']),
            ],
            'numero_guia_salida' => [
                Rule::requiredIf($requiereDestare && $this->input('modo_salida_envases') !== 'vacio'),
                'nullable', 'string', 'max:80',
            ],
            'salida_envases' => ['nullable', 'array', 'max:3'],
            'salida_envases.*.tipo_envase' => ['required', 'distinct', Rule::enum(TipoEnvaseRomana::class)],
            'salida_envases.*.cantidad' => ['required', 'integer', 'min:0', 'max:100000'],
            'taras_envases' => [
                'nullable',
                Rule::requiredIf($requiereDestare),
                'array',
                'min:1',
                'max:3',
            ],
            'taras_envases.*.tipo_envase' => [
                'required',
                'distinct',
                Rule::enum(TipoEnvaseRomana::class),
            ],
            'taras_envases.*.tara_unitaria' => [
                'required',
                'numeric',
                'min:0.001',
                'max:1000',
                'decimal:0,3',
            ],
            'observacion' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'peso_tara.required' => 'Ingresa la tara capturada en el destare.',
            'peso_tara.max' => 'La tara supera el máximo operacional de 200.000 kg.',
            'modo_salida_envases.required' => 'Selecciona cómo se retiran los envases antes de registrar el destare.',
            'numero_guia_salida.required' => 'Ingresa el número de guía de salida para los envases retirados.',
            'taras_envases.required' => 'Configura la tara de cada tipo de envase declarado.',
            'taras_envases.*.tipo_envase.distinct' => 'Cada tipo de envase puede configurar su tara solo una vez.',
            'taras_envases.*.tara_unitaria.required' => 'Ingresa la tara unitaria de cada envase.',
            'taras_envases.*.tara_unitaria.min' => 'La tara unitaria de cada envase debe ser mayor que cero.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $recepcion = $this->route('recepcion');
            if (! $recepcion instanceof RecepcionRomana
                || $recepcion->tipo_recepcion !== TipoRecepcionRomana::FrutaConEnvases
                || ! is_array($this->input('taras_envases'))) {
                return;
            }

            $entrantes = $recepcion->detallesEnvases()
                ->get(['tipo_envase', 'cantidad_validada'])
                ->mapWithKeys(fn ($detalle): array => [$detalle->tipo_envase->value => $detalle->cantidad_validada]);
            $modo = $this->input('modo_salida_envases');
            $salida = collect($this->input('salida_envases', []))->filter(fn ($fila): bool => is_array($fila))
                ->keyBy('tipo_envase');
            if ($modo !== 'diferentes' && $salida->isNotEmpty()) {
                $validator->errors()->add('salida_envases', 'Solo ingresa cantidades de salida al elegir más o menos envases.');
            }
            if ($modo === 'diferentes' && $entrantes->keys()->diff($salida->keys())->isNotEmpty()) {
                $validator->errors()->add('salida_envases', 'Indica la salida de cada tipo validado, incluso si sale cero.');
            }
            $tipos = $entrantes->keys()->merge($salida->keys())->unique()->sort()->values()->all();
            $configurados = collect($this->input('taras_envases'))->pluck('tipo_envase')->filter()->unique()
                ->sort()->values()->all();
            if ($tipos !== $configurados) {
                $validator->errors()->add('taras_envases', 'Configura la tara de cada tipo validado y de los tipos adicionales que salen.');
            }
            if ($modo === 'diferentes' && $salida->isNotEmpty()
                && $salida->every(fn ($fila): bool => (int) ($fila['cantidad'] ?? 0) === 0)) {
                $validator->errors()->add('salida_envases', 'Si el camión sale vacío, elige «Se va vacío».');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'numero_guia_salida' => filled($this->input('numero_guia_salida'))
                ? trim((string) $this->input('numero_guia_salida')) : null,
            'observacion' => filled($this->input('observacion'))
                ? trim((string) $this->input('observacion')) : null,
        ]);
    }
}

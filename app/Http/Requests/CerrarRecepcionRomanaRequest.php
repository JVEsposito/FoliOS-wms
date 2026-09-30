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
            'salida_sin_envases' => ['present', 'boolean'],
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

            $declarados = $recepcion->detallesEnvases()
                ->get(['tipo_envase'])
                ->map(fn ($detalle): string => $detalle->tipo_envase->value)
                ->sort()
                ->values()
                ->all();
            $configurados = collect($this->input('taras_envases'))
                ->pluck('tipo_envase')
                ->filter()
                ->sort()
                ->values()
                ->all();

            if ($declarados !== $configurados) {
                $validator->errors()->add(
                    'taras_envases',
                    'Debes configurar la tara de todos y únicamente los tipos de envase declarados.',
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $recepcion = $this->route('recepcion');
        $permiteSalidaSinEnvases = $recepcion instanceof RecepcionRomana
            && $recepcion->tipo_recepcion === TipoRecepcionRomana::FrutaConEnvases;
        $salidaSinEnvases = $permiteSalidaSinEnvases && $this->boolean('salida_sin_envases');

        $this->merge([
            'salida_sin_envases' => $salidaSinEnvases,
            'taras_envases' => $permiteSalidaSinEnvases ? $this->input('taras_envases') : null,
            'observacion' => filled($this->input('observacion')) ? trim((string) $this->input('observacion')) : null,
        ]);
    }
}

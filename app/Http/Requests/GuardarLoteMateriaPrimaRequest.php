<?php

namespace App\Http\Requests;

use App\Enums\TipoEnvaseRomana;
use App\Enums\TipoProductoMateriaPrima;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarLoteMateriaPrimaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar-lotes-materia-prima') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'version_conocida' => ['sometimes', 'required', 'integer', 'min:1'],
            'segmento_validacion_mp_id' => ['required', 'uuid', 'exists:segmentos_validacion_mp,id'],
            'numero_lote' => ['required', 'string', 'max:80'],
            'csg_validacion_id' => ['sometimes', 'nullable', 'uuid', 'exists:csg_validacion,id'],
            'sdp' => ['required', 'string', 'regex:/^[0-9]+$/', 'max:30'],
            'ggn' => ['nullable', 'string', 'regex:/^[0-9]{13}$/'],
            'fecha_cosecha' => ['required', 'date', 'before_or_equal:today'],
            'predio' => ['required', 'string', 'max:150'],
            'especie_validacion_id' => ['sometimes', 'nullable', 'uuid', 'exists:especies_validacion,id'],
            'variedad_validacion_id' => ['sometimes', 'nullable', 'uuid', 'exists:variedades_validacion,id'],
            'cuartel' => ['nullable', 'string', 'max:100'],
            'tipo_producto' => ['required', Rule::enum(TipoProductoMateriaPrima::class)],
            'envase_primario' => ['required', Rule::enum(TipoEnvaseRomana::class)],
            'envase_secundario' => [
                'nullable',
                Rule::in([TipoEnvaseRomana::Totes->value, TipoEnvaseRomana::Esponjas->value]),
                'different:envase_primario',
            ],
            'cantidad_envases_primarios' => ['required', 'integer', 'min:1', 'max:100000'],
            'cantidad_envases_secundarios' => [
                Rule::requiredIf(filled($this->input('envase_secundario'))),
                'integer',
                Rule::when(
                    filled($this->input('envase_secundario')),
                    ['min:1'],
                    ['min:0'],
                ),
                'max:100000',
            ],
            'kilos_netos_confirmados' => ['required', 'numeric', 'gt:0', 'max:1000000', 'decimal:0,3'],
            'requiere_hidrocooler' => ['required', 'boolean'],
            'observacion' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'sdp.regex' => 'El SdP debe contener solamente números.',
            'ggn.regex' => 'El GGN debe contener exactamente 13 dígitos.',
            'fecha_cosecha.before_or_equal' => 'La fecha de cosecha no puede ser futura.',
            'envase_secundario.different' => 'El envase secundario debe ser diferente del primario.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'numero_lote' => mb_strtoupper(trim((string) $this->input('numero_lote'))),
            'sdp' => trim((string) $this->input('sdp')),
            'ggn' => filled($this->input('ggn')) ? trim((string) $this->input('ggn')) : null,
            'predio' => trim((string) $this->input('predio')),
            'cuartel' => filled($this->input('cuartel'))
                ? trim((string) $this->input('cuartel'))
                : null,
            'envase_secundario' => filled($this->input('envase_secundario'))
                ? $this->input('envase_secundario')
                : null,
            'cantidad_envases_secundarios' => filled($this->input('envase_secundario'))
                ? $this->input('cantidad_envases_secundarios', 0)
                : 0,
            'observacion' => filled($this->input('observacion'))
                ? trim((string) $this->input('observacion'))
                : null,
        ]);
    }
}

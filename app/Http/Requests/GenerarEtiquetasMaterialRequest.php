<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GenerarEtiquetasMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('imprimir-etiquetas-materiales') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'perfil_id' => [
                'required',
                'uuid',
                Rule::exists('perfiles_impresion_etiquetas', 'id')->where('activo', true),
            ],
            'formato' => ['required', Rule::in(['pdf', 'nlbl', 'zpl'])],
            'diseno' => ['sometimes', Rule::in(['actual', 'clasica_bodega'])],
            'simbologia' => ['required', Rule::in(['code128', 'qr'])],
            'canal' => ['required', Rule::in(['oficina_descarga', 'pda_directa'])],
            'folio_ids' => ['required', 'array', 'min:1', 'max:500'],
            'folio_ids.*' => ['required', 'uuid', 'distinct', 'exists:folios,id'],
            'copias' => ['required', 'integer', 'between:1,20'],
            'motivo_reimpresion' => ['nullable', 'string', 'min:5', 'max:1000'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('diseno') === 'clasica_bodega' && $this->input('simbologia') !== 'code128') {
                $validator->errors()->add('simbologia', 'Clásica bodega utiliza código de barras Code 128. Para QR selecciona el diseño Actual.');
            }
            if ($this->input('diseno') === 'clasica_bodega'
                && ($this->route('ordenTransformacionMaterial') !== null || $this->route('transferenciaClienteMaterial') !== null)) {
                $validator->errors()->add('diseno', 'El diseño Clásica bodega está disponible para recepciones de materiales.');
            }
            if ($this->input('canal') === 'pda_directa'
                && $this->input('formato') !== 'zpl') {
                $validator->errors()->add(
                    'formato',
                    'La impresión directa desde PDA requiere formato ZPL.',
                );
            }
            if ($this->input('formato') === 'pdf'
                && $this->input('simbologia') !== 'code128') {
                $validator->errors()->add(
                    'simbologia',
                    'La vista PDF utiliza Code 128. Para QR selecciona NiceLabel/ZebraDesigner.',
                );
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'formato' => mb_strtolower(trim((string) $this->input('formato'))),
            'simbologia' => mb_strtolower(trim((string) $this->input('simbologia', 'code128'))),
            'canal' => mb_strtolower(trim((string) $this->input('canal', 'oficina_descarga'))),
            'copias' => $this->input('copias', 1),
            'motivo_reimpresion' => $this->filled('motivo_reimpresion')
                ? trim((string) $this->input('motivo_reimpresion'))
                : null,
        ]);
    }
}

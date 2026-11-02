<?php

namespace App\Http\Requests;

use App\Models\BinRetornoPacking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModificarBinRetornoPackingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $bin = $this->route('binRetornoPacking');
        $regularizado = $bin instanceof BinRetornoPacking && $bin->regularizado_at !== null;

        return [
            'operacion_id' => ['required', 'uuid'],
            'motivo' => ['required', 'string', 'min:5', 'max:2000'],
            'kilos_totales' => [
                'required',
                'numeric',
                'min:0.001',
                'max:999999999.999',
                'decimal:0,3',
            ],
            'observacion' => ['nullable', 'string', 'max:2000'],
            'origenes' => ['required', 'array', 'min:1', 'max:20'],
            'origenes.*.origen_id' => [
                'nullable',
                'uuid',
                'distinct',
                Rule::exists('bin_retorno_packing_origenes', 'id'),
            ],
            'origenes.*.lote_materia_prima_id' => [
                'nullable',
                'uuid',
                Rule::exists('lotes_materia_prima', 'id'),
            ],
            'origenes.*.numero_orden' => ['nullable', 'string', 'max:100'],
            'origenes.*.linea_proceso' => ['nullable', 'string', 'max:60'],
            'origenes.*.turno' => ['nullable', 'string', 'max:20'],
            'origenes.*.kilos_aportados' => [
                'required',
                'numeric',
                'min:0.001',
                'max:999999999.999',
                'decimal:0,3',
            ],
            'folio_definitivo' => [
                $regularizado ? 'required' : 'prohibited',
                'string',
                'max:80',
            ],
            'tipo_resultado_packing_id' => [
                $regularizado ? 'required' : 'prohibited',
                'uuid',
                Rule::exists('tipos_resultado_packing', 'id'),
            ],
            'nombre_resultado' => [
                $regularizado ? 'nullable' : 'prohibited',
                'string',
                'max:100',
            ],
            'kilos_totales_definitivos' => [
                $regularizado ? 'required' : 'prohibited',
                'numeric',
                'min:0.001',
                'max:999999999.999',
                'decimal:0,3',
            ],
            'origenes.*.kilos_aportados_definitivos' => [
                $regularizado ? 'required' : 'prohibited',
                'numeric',
                'min:0.001',
                'max:999999999.999',
                'decimal:0,3',
            ],
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Enums\TipoEnvaseRetornoPacking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarRetornoPackingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'cierra_entrega' => ['required_without:entregas', 'boolean'],
            'entregas' => ['nullable', 'array', 'min:1', 'max:20'],
            'entregas.*.entrega_fruta_proceso_id' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('entregas_fruta_proceso', 'id'),
            ],
            'entregas.*.cierra_entrega' => ['required', 'boolean'],
            'observacion' => ['nullable', 'string', 'max:2000'],
            'resultados' => ['required', 'array', 'min:1', 'max:20'],
            'resultados.*.tipo_resultado_packing_id' => [
                'required',
                'uuid',
                Rule::exists('tipos_resultado_packing', 'id')
                    ->where(fn ($consulta) => $consulta->where('activo', true)),
            ],
            'resultados.*.nombre_resultado' => ['nullable', 'string', 'max:100'],
            'resultados.*.tipo_envase' => ['sometimes', Rule::enum(TipoEnvaseRetornoPacking::class)],
            'resultados.*.cantidad_envases' => ['required_if:resultados.*.tipo_envase,caja_3_4', 'required_without:resultados.*.cantidad_bins', 'integer', 'min:1', 'max:100000'],
            'resultados.*.cantidad_bins' => ['required_without:resultados.*.cantidad_envases', 'integer', 'min:0', 'max:100000'],
            'resultados.*.kilos_netos' => ['nullable', 'numeric', 'min:0.001', 'max:999999999.999'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach ($this->input('resultados', []) as $i => $resultado) {
                $tipo = $resultado['tipo_envase'] ?? 'bins';
                $cantidad = (int) ($resultado['cantidad_envases'] ?? $resultado['cantidad_bins'] ?? 0);
                if ($cantidad < 1 || ($tipo === 'bins' && isset($resultado['cantidad_envases'], $resultado['cantidad_bins'])
                    && (int) $resultado['cantidad_bins'] !== $cantidad)
                    || ($tipo === 'caja_3_4' && (int) ($resultado['cantidad_bins'] ?? 0) !== 0)) {
                    $campo = array_key_exists('cantidad_envases', $resultado) ? 'cantidad_envases' : 'cantidad_bins';
                    $validator->errors()->add("resultados.{$i}.{$campo}", 'Indica una cantidad positiva en el envase seleccionado; las cajas 3/4 no se contabilizan como bins.');
                }
            }
        }];
    }
}

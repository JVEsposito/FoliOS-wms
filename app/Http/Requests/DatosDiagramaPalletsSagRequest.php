<?php

namespace App\Http\Requests;

use App\Services\InspeccionSag\NumeracionDiagramaPallets as Numeracion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Contenido de un diagrama de pallets SAG/USDA para emitir su PDF, guardado o no. */
class DatosDiagramaPalletsSagRequest extends FormRequest
{
    private const TEXTO = 'not_regex:/[\x00-\x1f\x7f]/u';

    public function authorize(): bool
    {
        return $this->user()?->can('consultar-inspeccion-sag') === true;
    }

    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::in(['usda', 'sag'])],
            'numero' => ['nullable', 'string', 'max:30', self::TEXTO],
            'csp' => ['nullable', 'string', 'max:50', self::TEXTO],
            'folio' => ['nullable', 'string', 'max:60', self::TEXTO],
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'lote' => ['nullable', 'string', 'max:100', self::TEXTO],
            'especie' => ['nullable', 'string', 'max:80', self::TEXTO],
            'exportadora' => ['nullable', 'string', 'max:150', self::TEXTO],
            'pallets' => ['required', 'array', 'min:1', 'max:'.Numeracion::MAXIMO_PALLETS],
            'pallets.*' => ['required', 'array:folio,base,corridas,ultima,declaradas'],
            'pallets.*.folio' => ['nullable', 'string', 'max:40', self::TEXTO],
            'pallets.*.base' => ['required', 'integer', 'min:1', 'max:'.Numeracion::MAXIMO_BASE],
            'pallets.*.corridas' => ['required', 'integer', 'min:1', 'max:'.Numeracion::MAXIMO_CORRIDAS],
            'pallets.*.ultima' => ['nullable', 'integer', 'min:1', 'max:'.(Numeracion::MAXIMO_BASE - 1)],
            'pallets.*.declaradas' => ['nullable', 'integer', 'min:1', 'max:'.(Numeracion::MAXIMO_BASE * Numeracion::MAXIMO_CORRIDAS)],
            'cajas_marcadas' => ['present', 'array', 'max:'.Numeracion::MAXIMO_MARCAS],
            'cajas_marcadas.*' => ['integer', 'min:1', 'distinct'],
        ];
    }

    public function attributes(): array
    {
        return ['pallets.*.base' => 'cajas por base', 'pallets.*.corridas' => 'corridas',
            'pallets.*.ultima' => 'cajas de la última corrida', 'pallets.*.declaradas' => 'cajas declaradas'];
    }
}

<?php

namespace App\Http\Requests;

use App\Rules\RutChileno;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarRecepcionFrutaEmbaladaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar-recepciones-fruta-embalada') === true;
    }

    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'version_conocida' => [$this->route('recepcionFrutaEmbalada') ? 'required' : 'nullable', 'integer', 'min:1'],
            'temporada_id' => ['required', 'uuid', 'exists:temporadas,id'],
            'cliente_id' => ['required', 'uuid', 'exists:clientes,id'],
            'planta_origen_id' => ['required', 'uuid', 'exists:plantas_origen,id'],
            'numero_guia' => ['required', 'string', 'max:50'],
            'confirmar_guia_duplicada' => ['sometimes', 'boolean'],
            'servicio' => ['required', Rule::in(['almacenaje', 'prefrio'])],
            'turno' => ['required', 'string', 'max:30'],
            'validador_id' => ['required', 'integer', 'exists:users,id'],
            'recepcion_at' => ['required', 'date'],
            'salida_at' => ['nullable', 'date', 'after_or_equal:recepcion_at'],
            'chofer' => ['required', 'string', 'max:150'],
            'rut_chofer' => ['nullable', 'string', 'max:20', new RutChileno],
            'patente_delantera' => ['required', 'string', 'max:20'],
            'patente_carro' => ['nullable', 'string', 'max:20'],
            'llega_con_prefrio' => ['required', 'boolean'],
            'condicion_sag_id' => ['nullable', 'uuid', Rule::exists('condiciones_sag', 'id')->where('activo', true)],
            'observacion' => ['nullable', 'string', 'max:2000'],
            // El cliente no puede aceptar, anular ni crear inventario desde esta API.
            'estado' => ['prohibited'], 'folio_id' => ['prohibited'],
            'pallets' => ['present', 'array', 'max:500'],
            'pallets.*' => ['array:id,folio_origen,tipo_bulto,articulo_validacion_id,origen_validacion_id,csp,cantidad_cajas,fecha_proceso_origen,temperatura_pulpa_c,condicion_sag_id,condicion_sag_personalizada'],
            'pallets.*.id' => ['nullable', 'uuid', 'distinct'],
            'pallets.*.folio_origen' => ['required', 'string', 'max:50'],
            'pallets.*.tipo_bulto' => ['required', Rule::in(['pallet', 'saldo'])],
            'pallets.*.articulo_validacion_id' => ['required', 'uuid'],
            'pallets.*.origen_validacion_id' => ['required', 'uuid'],
            'pallets.*.csp' => ['nullable', 'string', 'max:50'],
            'pallets.*.cantidad_cajas' => ['required', 'integer', 'between:1,100000'],
            'pallets.*.fecha_proceso_origen' => ['required', 'date_format:Y-m-d'],
            'pallets.*.temperatura_pulpa_c' => ['required', 'numeric', 'between:-50,80', 'decimal:0,2'],
            'pallets.*.condicion_sag_personalizada' => ['required', 'boolean'],
            'pallets.*.condicion_sag_id' => ['nullable', 'uuid', Rule::exists('condiciones_sag', 'id')->where('activo', true)],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['numero_guia', 'turno', 'patente_delantera', 'patente_carro', 'rut_chofer'] as $campo) {
            if (is_string($this->input($campo))) {
                $this->merge([$campo => filled($this->input($campo)) ? mb_strtoupper(trim((string) $this->input($campo))) : null]);
            }
        }
        $pallets = $this->input('pallets');
        if (is_array($pallets)) {
            $this->merge(['pallets' => array_map(function ($pallet) {
                if (is_array($pallet) && is_string($pallet['folio_origen'] ?? null)) {
                    $pallet['folio_origen'] = mb_strtoupper(trim((string) $pallet['folio_origen']));
                }

                return $pallet;
            }, $pallets)]);
        }
    }
}

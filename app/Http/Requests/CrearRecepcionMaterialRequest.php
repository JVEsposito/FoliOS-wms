<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CrearRecepcionMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar-recepciones-materiales') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'operacion_id' => ['required', 'uuid'],
            'cliente_id' => ['required', 'uuid', 'exists:clientes,id'],
            'proveedor_material_id' => ['required', 'uuid', 'exists:proveedores_materiales,id'],
            'numero_guia_despacho' => ['required', 'string', 'max:50'],
            'fecha_documento' => ['nullable', 'date'],
            'orden_compra' => ['nullable', 'string', 'max:80'],
            'patente' => ['nullable', 'string', 'max:20'],
            'transportista' => ['nullable', 'string', 'max:150'],
            'observacion' => ['nullable', 'string', 'max:2000'],
            'detalles' => ['required', 'array', 'min:1', 'max:100'],
            'detalles.*.item_material_id' => ['required', 'uuid', 'exists:items_materiales,id'],
            'detalles.*.unidad_ingreso' => ['nullable', 'string', 'max:40'],
            'detalles.*.conversion_version' => ['required_with:detalles.*.unidad_ingreso', 'nullable', 'integer', 'min:1'],
            'detalles.*.cantidad_documental' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'detalles.*.cantidad_contada' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'detalles.*.cantidad_aceptada' => ['required', 'numeric', 'min:0', 'decimal:0,3'],
            'detalles.*.cantidad_recibida' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'detalles.*.cantidad_rechazada' => ['required', 'numeric', 'min:0', 'decimal:0,3'],
            'detalles.*.observacion' => ['nullable', 'string', 'max:2000'],
            'detalles.*.bultos' => ['present', 'array', 'max:500'],
            'detalles.*.bultos.*.cantidad' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'detalles.*.bultos.*.lote_proveedor' => ['nullable', 'string', 'max:100'],
            'detalles.*.bultos.*.fecha_fabricacion' => ['nullable', 'date'],
            'detalles.*.bultos.*.fecha_vencimiento' => ['nullable', 'date'],
            'detalles.*.bultos.*.bloqueado' => ['nullable', 'boolean'],
            'detalles.*.bultos.*.motivo_bloqueo' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $detalles = collect($this->input('detalles', []))
            ->map(function (mixed $valor): mixed {
                if (! is_array($valor)) {
                    return $valor;
                }

                $aceptada = $valor['cantidad_aceptada']
                    ?? $valor['cantidad_recibida']
                    ?? null;
                $rechazada = $valor['cantidad_rechazada'] ?? 0;
                $contada = $valor['cantidad_contada'] ?? null;

                if ($contada === null
                    && is_numeric($aceptada)
                    && is_numeric($rechazada)) {
                    $contada = round((float) $aceptada + (float) $rechazada, 3);
                }

                $valor['cantidad_aceptada'] = $aceptada;
                $valor['cantidad_recibida'] = $aceptada;
                $valor['cantidad_rechazada'] = $rechazada;
                $valor['cantidad_contada'] = $contada;

                return $valor;
            })
            ->all();

        $this->merge([
            'numero_guia_despacho' => trim((string) $this->input('numero_guia_despacho')),
            'orden_compra' => $this->limpiar($this->input('orden_compra')),
            'patente' => $this->limpiar($this->input('patente')),
            'transportista' => $this->limpiar($this->input('transportista')),
            'observacion' => $this->limpiar($this->input('observacion')),
            'detalles' => $detalles,
        ]);
    }

    private function limpiar(mixed $valor): ?string
    {
        $texto = trim((string) ($valor ?? ''));

        return $texto === '' ? null : $texto;
    }
}

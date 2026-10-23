<?php

namespace App\Http\Requests;

use App\Rules\RutChileno;
use App\Services\Materiales\ServicioDirectorioTransporte;
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
            'provisional' => ['sometimes', 'boolean'],
            'fecha_recepcion' => ['sometimes', 'required', 'date'],
            'motivo_registro_tardio' => ['sometimes', 'nullable', 'string', 'min:5', 'max:1000'],
            'numero_guia_despacho' => [$this->esProvisional() ? 'nullable' : 'required', 'string', 'max:50'],
            'fecha_documento' => ['nullable', 'date'],
            'orden_compra' => ['nullable', 'string', 'max:80'],
            'patente' => ['nullable', 'string', 'max:20'],
            'transportista' => ['nullable', 'string', 'max:150'],
            'transporte' => ['sometimes', 'array:conductor,empresa,vehiculo,patente_carro'],
            'transporte.conductor' => ['nullable', 'array:rut,nombre,telefono,version_conocida,actualizar_directorio'],
            'transporte.conductor.rut' => ['nullable', 'required_with:transporte.conductor.nombre,transporte.conductor.telefono', 'regex:/^[1-9][0-9]{5,7}-[0-9K]$/', new RutChileno],
            'transporte.conductor.nombre' => ['nullable', 'required_with:transporte.conductor.rut', 'string', 'max:150'],
            'transporte.conductor.telefono' => ['nullable', 'string', 'max:40'],
            'transporte.empresa' => ['nullable', 'array:rut,nombre,telefono,version_conocida,actualizar_directorio'],
            'transporte.empresa.rut' => ['nullable', 'regex:/^[1-9][0-9]{5,7}-[0-9K]$/', new RutChileno],
            'transporte.empresa.nombre' => ['nullable', 'required_with:transporte.empresa.rut,transporte.empresa.telefono', 'string', 'max:150'],
            'transporte.empresa.telefono' => ['nullable', 'string', 'max:40'],
            'transporte.vehiculo' => ['nullable', 'array:patente,descripcion,version_conocida,actualizar_directorio'],
            'transporte.vehiculo.patente' => ['nullable', 'required_with:transporte.vehiculo.descripcion', 'regex:/^[A-Z0-9]{4,12}$/'],
            'transporte.vehiculo.descripcion' => ['nullable', 'string', 'max:150'],
            'transporte.patente_carro' => ['nullable', 'regex:/^[A-Z0-9]{4,12}$/'],
            ...collect(['conductor', 'empresa', 'vehiculo'])->flatMap(fn (string $tipo): array => [
                "transporte.$tipo.version_conocida" => ['nullable', 'integer', 'min:1'],
                "transporte.$tipo.actualizar_directorio" => ['sometimes', 'boolean'],
            ])->all(),
            'observacion' => ['nullable', 'string', 'max:2000'],
            'detalles' => ['required', 'array', 'min:1', 'max:100'],
            'detalles.*.item_material_id' => ['required', 'uuid', 'exists:items_materiales,id'],
            'detalles.*.detalle_origen_id' => ['nullable', 'uuid', 'distinct'],
            'detalles.*.unidad_ingreso' => ['nullable', 'string', 'max:40'],
            'detalles.*.conversion_version' => ['required_with:detalles.*.unidad_ingreso', 'nullable', 'integer', 'min:1'],
            'detalles.*.cantidad_documental' => [$this->esProvisional() ? 'nullable' : 'required', 'numeric', 'gt:0', 'decimal:0,3'],
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
            'numero_guia_despacho' => $this->esProvisional() ? $this->limpiar($this->input('numero_guia_despacho')) : trim((string) $this->input('numero_guia_despacho')),
            'orden_compra' => $this->limpiar($this->input('orden_compra')),
            'patente' => $this->limpiar($this->input('patente')),
            'transportista' => $this->limpiar($this->input('transportista')),
            'observacion' => $this->limpiar($this->input('observacion')),
            'detalles' => $detalles,
        ]);

        // No agregar la clave a clientes anteriores: preserva sus hashes de
        // idempotencia y las recepciones históricas que solo traían texto.
        if ($this->has('transporte') && is_array($viaje = $this->input('transporte'))) {
            foreach (['conductor', 'empresa', 'vehiculo'] as $tipo) {
                if (! isset($viaje[$tipo]) || ! is_array($viaje[$tipo])) {
                    continue;
                }
                foreach (['rut', 'nombre', 'telefono', 'patente', 'descripcion'] as $campo) {
                    if (array_key_exists($campo, $viaje[$tipo])) {
                        $valor = $viaje[$tipo][$campo];
                        $viaje[$tipo][$campo] = match ($campo) {
                            'rut' => ServicioDirectorioTransporte::rut($valor),
                            'patente' => ServicioDirectorioTransporte::patente($valor),
                            default => is_scalar($valor) || $valor === null ? $this->limpiar($valor) : $valor,
                        };
                    }
                }
            }
            if (array_key_exists('patente_carro', $viaje)) {
                $viaje['patente_carro'] = ServicioDirectorioTransporte::patente($viaje['patente_carro']);
            }
            $this->merge(['transporte' => $viaje]);
        }
    }

    private function esProvisional(): bool
    {
        return $this->has('provisional') ? $this->boolean('provisional') : (bool) $this->route('recepcionMaterial')?->provisional;
    }

    private function limpiar(mixed $valor): ?string
    {
        $texto = trim((string) ($valor ?? ''));

        return $texto === '' ? null : $texto;
    }
}

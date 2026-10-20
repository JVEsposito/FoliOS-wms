<?php

namespace App\Http\Requests;

use App\Models\Cliente;
use App\Models\IngresoPorteria;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GuardarIngresoPorteriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalizados = [];
        foreach (['patente', 'patente_carro'] as $campo) {
            if (is_string($this->input($campo))) {
                $normalizados[$campo] = preg_replace('/[\s-]+/', '', mb_strtoupper(trim($this->input($campo)))) ?: null;
            }
        }
        foreach (['cliente_otro', 'nombre_conductor', 'tipo_camion', 'observacion'] as $campo) {
            if (is_string($this->input($campo))) {
                $normalizados[$campo] = trim($this->input($campo)) ?: null;
            }
        }
        if (is_array($this->input('guias'))) {
            $normalizados['guias'] = array_map(fn ($guia) => is_string($guia) ? trim($guia) : $guia, $this->input('guias'));
        }
        $this->merge($normalizados);
    }

    public function rules(): array
    {
        return [
            ...($this->isMethod('post') ? ['operacion_id' => ['required', 'uuid']] : ['version' => ['required', 'integer', 'min:1'], 'motivo_correccion' => ['required', 'string', 'min:5', 'max:500']]),
            'cliente_id' => ['nullable', 'uuid', ...($this->esReintentoPropio() ? [] : [Rule::exists(Cliente::class, 'id')->where('activo', true)])],
            'cliente_otro' => ['nullable', 'required_without:cliente_id', Rule::prohibitedIf($this->filled('cliente_id')), 'string', 'max:180'],
            'motivo' => ['required', Rule::in(['carga', 'vacio', 'servicio'])],
            'patente' => ['required', 'string', 'regex:/^[A-Z0-9]{4,12}$/'],
            'patente_carro' => ['nullable', 'string', 'regex:/^[A-Z0-9]{4,12}$/'],
            'tipo_camion' => ['required', 'string', 'max:80'],
            'nombre_conductor' => ['required', 'string', 'max:180'],
            'guias' => ['present', 'array', 'max:30', Rule::when($this->input('motivo') === 'carga', ['min:1'])],
            'guias.*' => ['required', 'string', 'max:50', 'distinct:strict'],
            'cantidad' => ['nullable', 'required_with:unidad', 'integer', 'min:0', 'max:1000000'],
            'unidad' => ['nullable', 'required_with:cantidad', Rule::in(['bins', 'bultos'])],
            'observacion' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function esReintentoPropio(): bool
    {
        if (! $this->isMethod('post') || ! $this->user()
            || ! is_string($this->input('operacion_id')) || ! Str::isUuid($this->input('operacion_id'))) {
            return false;
        }

        // El servicio comprobará el hash original antes de devolver la operación.
        // Una baja posterior del cliente no debe impedir confirmar un ingreso recibido.
        return IngresoPorteria::query()->where('operacion_id', $this->input('operacion_id'))
            ->where('creado_por_user_id', $this->user()->id)->exists();
    }

    public function messages(): array
    {
        return [
            'required' => 'Complete :attribute.', 'required_without' => 'Seleccione un cliente o escriba el cliente/servicio en Otro.',
            'prohibited' => 'Seleccione un cliente del catálogo o complete Otro.',
            'guias.min' => 'Para un camión con carga, agregue al menos una guía.',
            'guias.*.distinct' => 'Una guía se repite dentro de este ingreso; agréguela una sola vez.',
            'patente.regex' => 'Ingrese una patente válida, con 4 a 12 letras y números.',
            'cliente_id.exists' => 'El cliente no está activo en el catálogo. Actualice la lista o utilice Otro.',
        ];
    }
}

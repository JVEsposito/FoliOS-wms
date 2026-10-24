<?php

namespace App\Http\Requests;

use App\Enums\TipoAlmacenMaterial;
use App\Models\DestinoMaterial;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarDestinoMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $destino = $this->route('destinoMaterial');

        return $this->user()?->can('administrar-catalogos-materiales') === true
            && ! ($destino instanceof DestinoMaterial && $destino->tipo === TipoAlmacenMaterial::Fisica->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $destino = $this->route('destinoMaterial');

        return [
            'nombre' => ['required', 'string', 'min:3', 'max:180'],
            'centro_costo' => ['required', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'codigo_externo' => [
                'nullable',
                'string',
                'max:150',
                Rule::unique('destinos_materiales', 'codigo_externo')->ignore(
                    $destino instanceof DestinoMaterial ? $destino->id : null,
                ),
            ],
            'activo' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nombre' => trim((string) $this->input('nombre')),
            'centro_costo' => mb_strtoupper(trim((string) $this->input('centro_costo'))),
            'descripcion' => $this->filled('descripcion')
                ? trim((string) $this->input('descripcion'))
                : null,
            'codigo_externo' => $this->filled('codigo_externo')
                ? trim((string) $this->input('codigo_externo'))
                : ($this->has('codigo_externo') ? null : $this->route('destinoMaterial')?->codigo_externo),
        ]);
    }
}

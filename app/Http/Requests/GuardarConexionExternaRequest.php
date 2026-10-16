<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class GuardarConexionExternaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('administrar-accesos');
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:100', Rule::unique('conexiones_externas')->ignore($this->route('conexionExterna')?->id)],
            'host' => ['required', 'string', 'max:253', function (string $attribute, mixed $value, Closure $fail): void {
                if (! filter_var($value, FILTER_VALIDATE_IP)
                    && ! filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                    $fail('Indica una dirección IP o un nombre de servidor, sin protocolo ni ruta.');
                }
            }],
            'puerto' => ['required', 'integer', 'between:1,65535'],
            'usuario' => ['required', 'string', 'max:128', 'not_regex:/[\x00-\x1f\x7f]/'],
            'password' => [$this->isMethod('POST') ? 'required' : 'nullable', 'string', 'max:1024', 'not_regex:/\x00/'],
            'base_datos' => ['required', 'string', 'max:64', 'not_regex:/[;\x00-\x1f\x7f]/'],
            'activa' => ['required', 'boolean'],
            'version_conocida' => [$this->isMethod('PUT') ? 'required' : 'prohibited', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return ['host' => 'servidor', 'puerto' => 'puerto', 'usuario' => 'usuario', 'password' => 'contraseña', 'base_datos' => 'base de datos'];
    }
}

<?php

namespace App\Http\Requests;

use App\Enums\RolUsuario;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use App\Services\Autorizacion\CatalogoOficinas;
use App\Services\Autorizacion\CatalogoPermisosMateriales;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GuardarPerfilAccesoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('administrar-accesos');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $perfil = $this->route('perfilAcceso');
        $catalogo = app(CatalogoModulosAcceso::class);

        return [
            'codigo' => [
                'required',
                'string',
                'max:80',
                'regex:/^[A-Z0-9][A-Z0-9_-]*$/',
                Rule::unique('perfiles_acceso', 'codigo')->ignore($perfil),
            ],
            'nombre' => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'solo_consulta' => ['sometimes', 'boolean'],
            'rol_base' => [
                'required',
                Rule::enum(RolUsuario::class),
            ],
            'modulos' => ['required', 'array', 'min:1'],
            'modulos.*' => ['required', 'string', 'distinct', Rule::in($catalogo->claves())],
            'modulos_tablet' => ['present', 'array'],
            'permisos_materiales' => ['sometimes', 'array', 'max:40'],
            'permisos_materiales.*' => ['required', 'string', 'distinct', Rule::in(app(CatalogoPermisosMateriales::class)->claves())],
            'modulos_tablet.*' => [
                'required',
                'string',
                'distinct',
                Rule::in($catalogo->clavesTablet()),
            ],
            'pantalla_inicio' => [
                'nullable',
                'string',
                Rule::in(array_column(app(CatalogoOficinas::class)->opcionesInicio(), 'ruta')),
            ],
            'activo' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $catalogo = app(CatalogoModulosAcceso::class);
            $compatiblesMateriales = app(CatalogoPermisosMateriales::class)->compatibles(
                (array) $this->input('modulos', []), $this->input('rol_base') === RolUsuario::Consulta->value,
            );
            if (array_diff((array) $this->input('permisos_materiales', []), $compatiblesMateriales) !== []) {
                $validator->errors()->add('permisos_materiales', 'Cada acción de Materiales requiere su oficina habilitada y un perfil que no sea de solo consulta.');
            }
            if (in_array('inventario.aprobar_ajustes', (array) $this->input('permisos_materiales', []), true)
                && ! in_array($this->input('rol_base'), ['administrador', 'supervisor_materiales'], true)) {
                $validator->errors()->add('permisos_materiales', 'La aprobación de tomas requiere nivel Administrador o Supervisor de materiales.');
            }
            $compatibles = $catalogo->modulosTabletCompatiblesCon(
                (array) $this->input('modulos', []),
            );
            $incompatibles = array_diff(
                (array) $this->input('modulos_tablet', []),
                $compatibles,
            );

            if ($incompatibles !== []) {
                $validator->errors()->add(
                    'modulos_tablet',
                    'Cada módulo PDA/tablet necesita al menos una de sus oficinas relacionadas habilitada.',
                );
            }

            $inicio = $this->input('pantalla_inicio');
            $oficinas = app(CatalogoOficinas::class);
            $oficina = is_string($inicio) ? $oficinas->oficina($inicio) : null;
            $relacionados = $oficina ? $oficinas->modulosRelacionados($oficina, $catalogo->claves()) : [];
            if ($oficina && array_intersect($relacionados, (array) $this->input('modulos', [])) === []) {
                $validator->errors()->add(
                    'pantalla_inicio',
                    "La pantalla de inicio «{$oficina['nombre']}» no corresponde a los módulos de oficina del perfil.",
                );
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'codigo.required' => 'Ingresa un código para el perfil.',
            'codigo.regex' => 'El código solo puede contener mayúsculas, números, guion y guion bajo.',
            'codigo.unique' => 'Ya existe un perfil con ese código.',
            'nombre.required' => 'Ingresa el nombre del perfil.',
            'rol_base.required' => 'Selecciona el nivel operacional base.',
            'rol_base.enum' => 'El nivel operacional seleccionado no es válido.',
            'modulos.required' => 'Selecciona al menos un módulo.',
            'modulos.min' => 'Selecciona al menos un módulo.',
            'modulos.*.in' => 'Uno de los módulos seleccionados no existe en el catálogo de accesos.',
            'modulos_tablet.present' => 'Indica los módulos PDA/tablet del perfil, aunque no habilites ninguno.',
            'modulos_tablet.*.in' => 'Uno de los módulos PDA/tablet seleccionados no existe o todavía no está implementado.',
            'pantalla_inicio.in' => 'La pantalla de inicio seleccionada no es una oficina de FoliOS.',
            'activo.required' => 'Indica si el perfil se encuentra activo.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $soloConsulta = $this->boolean('solo_consulta')
            || $this->input('rol_base') === RolUsuario::Consulta->value;

        $this->merge([
            'codigo' => mb_strtoupper(trim((string) $this->input('codigo'))),
            'solo_consulta' => $soloConsulta,
            'rol_base' => $soloConsulta
                ? RolUsuario::Consulta->value
                : $this->input('rol_base'),
            'nombre' => trim((string) $this->input('nombre')),
            'descripcion' => filled($this->input('descripcion'))
                ? trim((string) $this->input('descripcion'))
                : null,
            'modulos' => array_values(array_unique(array_filter(
                (array) $this->input('modulos', []),
                fn (mixed $modulo): bool => is_string($modulo) && $modulo !== '',
            ))),
            'pantalla_inicio' => filled($this->input('pantalla_inicio'))
                ? (string) $this->input('pantalla_inicio')
                : null,
            'modulos_tablet' => $soloConsulta
                ? []
                : array_values(array_unique(array_filter(
                    (array) $this->input('modulos_tablet', []),
                    fn (mixed $modulo): bool => is_string($modulo) && $modulo !== '',
                ))),
        ]);
    }
}

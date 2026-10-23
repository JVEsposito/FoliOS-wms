<?php

namespace App\Http\Controllers\Api;

use App\Enums\RolUsuario;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarPerfilAccesoRequest;
use App\Models\PerfilAcceso;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use App\Services\Autorizacion\CatalogoOficinas;
use App\Services\Autorizacion\CatalogoPermisosMateriales;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class PerfilAccesoController extends Controller
{
    public function __construct(
        private readonly CatalogoModulosAcceso $catalogo,
        private readonly CatalogoOficinas $oficinas,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('consultar-accesos');

        return response()->json([
            'data' => PerfilAcceso::query()
                ->withCount('usuarios')
                ->orderByDesc('protegido')
                ->orderBy('nombre')
                ->get()
                ->map(fn (PerfilAcceso $perfil): array => $this->perfil($perfil)),
            'catalogo' => $this->catalogo->macromodulos(),
            'catalogo_tablet' => $this->catalogo->macromodulosTablet(),
            'catalogo_permisos_materiales' => app(CatalogoPermisosMateriales::class)->catalogo(),
            'pantallas_inicio' => array_map(
                fn (array $oficina): array => [
                    'ruta' => $oficina['ruta'],
                    'nombre' => $oficina['nombre'],
                    'area' => $oficina['area_nombre'],
                    'modulos_relacionados' => $this->oficinas->modulosRelacionados($oficina, $this->catalogo->claves()),
                ],
                $this->oficinas->opcionesInicio(),
            ),
            'roles_base' => collect(RolUsuario::cases())
                ->sortBy(fn (RolUsuario $rol): int => $rol === RolUsuario::Administrador ? 1 : 0)
                ->map(fn (RolUsuario $rol): array => [
                    'clave' => $rol->value,
                    'nombre' => $this->nombreRol($rol),
                    'modulos_disponibles' => $this->catalogo->claves(),
                    'modulos_sugeridos' => $this->catalogo->modulosPredeterminados($rol),
                    'modulos_tablet_sugeridos' => $this->catalogo->modulosTabletPredeterminados($rol),
                ])
                ->values(),
        ]);
    }

    public function store(GuardarPerfilAccesoRequest $request): JsonResponse
    {
        $datos = $request->validated();
        unset($datos['solo_consulta']);

        $perfil = PerfilAcceso::create([
            ...$datos,
            'predeterminado' => false,
            'protegido' => false,
            'creado_por_user_id' => $request->user()->id,
            'actualizado_por_user_id' => $request->user()->id,
        ]);

        return response()->json(
            ['data' => $this->perfil($perfil->loadCount('usuarios'))],
            Response::HTTP_CREATED,
        );
    }

    public function update(
        GuardarPerfilAccesoRequest $request,
        PerfilAcceso $perfilAcceso,
    ): JsonResponse {
        if ($perfilAcceso->protegido) {
            throw new DomainException(
                'El perfil Administrador está protegido. Puedes modificar todos los demás perfiles.',
            );
        }

        $datos = $request->validated();
        unset($datos['solo_consulta']);

        if ($perfilAcceso->predeterminado
            && $perfilAcceso->rol_base->value !== $datos['rol_base']) {
            throw new DomainException(
                'Puedes modificar los módulos del perfil inicial, pero no su nivel operacional base.',
            );
        }
        $resultado = DB::transaction(function () use ($datos, $perfilAcceso, $request): array {
            $perfil = PerfilAcceso::query()->lockForUpdate()->findOrFail($perfilAcceso->id);
            // Un cliente anterior que omite el campo conserva la restricción.
            // Quitar oficinas también quita las acciones que dependían de ellas.
            if (! array_key_exists('permisos_materiales', $datos) && $perfil->permisos_materiales !== null) {
                $datos['permisos_materiales'] = array_values(array_intersect(
                    $perfil->permisos_materiales,
                    app(CatalogoPermisosMateriales::class)->compatibles($datos['modulos'], $datos['rol_base'] === RolUsuario::Consulta->value),
                ));
            }
            $seguridadCambio = $perfil->rol_base->value !== $datos['rol_base']
                || $perfil->modulos !== $datos['modulos']
                || $perfil->modulos_tablet !== $datos['modulos_tablet']
                || $perfil->activo !== (bool) $datos['activo']
                || (array_key_exists('permisos_materiales', $datos) && $perfil->permisos_materiales !== $datos['permisos_materiales']);

            $perfil->fill([
                ...$datos,
                'actualizado_por_user_id' => $request->user()->id,
            ])->save();

            if ($seguridadCambio) {
                $usuarios = $perfil->usuarios()->lockForUpdate()->get();
                foreach ($usuarios as $usuario) {
                    $usuario->forceFill(['rol' => $perfil->rol_base])->save();
                    $usuario->tokens()->delete();
                }
            }

            return [
                'perfil' => $perfil->refresh()->loadCount('usuarios'),
                'sesiones_revocadas' => $seguridadCambio,
            ];
        }, attempts: 3);

        return response()->json([
            'data' => $this->perfil($resultado['perfil']),
            'sesiones_revocadas' => $resultado['sesiones_revocadas'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function perfil(PerfilAcceso $perfil): array
    {
        return [
            'id' => $perfil->id,
            'codigo' => $perfil->codigo,
            'nombre' => $perfil->nombre,
            'descripcion' => $perfil->descripcion,
            'rol_base' => $perfil->rol_base->value,
            'rol_base_nombre' => $this->nombreRol($perfil->rol_base),
            'solo_consulta' => $perfil->rol_base === RolUsuario::Consulta,
            'modulos' => $perfil->modulos,
            'modulos_tablet' => $perfil->modulos_tablet ?? [],
            'permisos_materiales' => $perfil->permisos_materiales,
            'permisos_materiales_efectivos' => app(CatalogoPermisosMateriales::class)->efectivosPerfil($perfil),
            'pantalla_inicio' => $perfil->pantalla_inicio,
            'activo' => $perfil->activo,
            'predeterminado' => $perfil->predeterminado,
            'protegido' => $perfil->protegido,
            'usuarios_count' => (int) ($perfil->usuarios_count ?? 0),
            'actualizado_at' => $perfil->updated_at?->toAtomString(),
        ];
    }

    private function nombreRol(RolUsuario $rol): string
    {
        return match ($rol) {
            RolUsuario::Administrador => 'Administrador',
            RolUsuario::SupervisorFrio => 'Supervisor de frío',
            RolUsuario::SupervisorMateriales => 'Supervisor de materiales',
            RolUsuario::Despachador => 'Despachador',
            RolUsuario::OperadorPrefrio => 'Operador de prefrío',
            RolUsuario::GuardiaPorteria => 'Guardia de portería',
            RolUsuario::OperadorRomana => 'Operador de romana',
            RolUsuario::DigitadorMateriaPrima => 'Digitador de materia prima',
            RolUsuario::CamareroFrio => 'Camarero de frío',
            RolUsuario::CamareroMateriales => 'Camarero de materiales',
            RolUsuario::Validador => 'Validador de pallets',
            RolUsuario::ValidadorMp => 'Validador MP',
            RolUsuario::Tarjador => 'Tarjador',
            RolUsuario::Consulta => 'Solo consulta',
        };
    }
}

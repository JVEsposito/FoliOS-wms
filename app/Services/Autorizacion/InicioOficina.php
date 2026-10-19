<?php

namespace App\Services\Autorizacion;

use App\Enums\RolUsuario;

/**
 * Pantalla de inicio de cada usuario en FoliOS web.
 *
 * Regla única, usada por /FoliOS y por el logo de Oficina:
 * 1. La pantalla de inicio del perfil de acceso, si está definida y habilitada.
 * 2. Si no, la pantalla preferida del rol, si el perfil la habilita.
 * 3. Si no, la primera oficina habilitada en el orden del menú.
 * 4. Si el perfil no habilita ninguna oficina, null.
 *
 * Evalúa la misma identidad que recibe Oficina al iniciar sesión (IdentidadOficina),
 * con las mismas reglas que el menú (resources/js/office-navigation.js): el
 * administrador ve todo, un módulo vacío no restringe y basta uno de los permisos.
 */
class InicioOficina
{
    /**
     * @var array<string, string>
     */
    public const PREFERIDAS = [
        'administrador' => '/oficina/operacion-ahora',
        'supervisor_frio' => '/oficina/operacion-ahora',
        'despachador' => '/oficina/frigorifico/despacho/cargas',
        'operador_prefrio' => '/oficina/prefrio',
        'guardia_porteria' => '/oficina/porteria',
        'operador_romana' => '/oficina/romana',
        'digitador_materia_prima' => '/oficina/materia-prima/lotes',
        'validador_mp' => '/oficina/materia-prima',
        'supervisor_materiales' => '/oficina/materiales',
        'camarero_materiales' => '/oficina/materiales',
        'validador' => '/oficina/validacion',
        'camarero_frio' => '/oficina/frigorifico/camaras',
        'tarjador' => '/oficina/validacion/repaletizajes',
        'consulta' => '/oficina/consultas',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const ALIAS_MODULOS = [
        CatalogoModulosAcceso::OFICINA_MAESTROS_TEMPORADA => [
            CatalogoModulosAcceso::OFICINA_CATALOGOS_VALIDACION_LEGADO,
        ],
    ];

    public function __construct(
        private readonly CatalogoOficinas $catalogo,
    ) {}

    /**
     * @param  array<string, mixed>  $identidad
     * @param  string|null  $inicioPerfil  Pantalla de inicio fijada en el perfil de acceso.
     */
    public function resolver(array $identidad, ?string $inicioPerfil = null): ?string
    {
        $preferidaRol = self::PREFERIDAS[$identidad['rol'] ?? ''] ?? null;
        $rol = null;
        $primera = null;

        foreach ($this->catalogo->oficinas() as $oficina) {
            if (! $this->habilitada($identidad, $oficina)) {
                continue;
            }

            if ($inicioPerfil !== null && $oficina['ruta'] === $inicioPerfil) {
                return $inicioPerfil;
            }

            if ($oficina['ruta'] === $preferidaRol) {
                $rol = $preferidaRol;
            }

            $primera ??= $oficina['ruta'];
        }

        return $rol ?? $primera;
    }

    /**
     * @param  array<string, mixed>  $identidad
     * @param  array{ruta: string, modulo: string, permisos: list<string>}  $oficina
     */
    private function habilitada(array $identidad, array $oficina): bool
    {
        if (! $this->tieneModulo($identidad, $oficina['modulo'])) {
            return false;
        }

        foreach ($oficina['permisos'] as $permiso) {
            if ($this->puede($identidad, $permiso)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $identidad
     */
    private function puede(array $identidad, string $permiso): bool
    {
        if (($identidad['rol'] ?? null) === RolUsuario::Administrador->value) {
            return true;
        }

        $valores = $this->valores($identidad);

        if ($permiso === 'ambito_camaras_productos') {
            return in_array($valores['ambito_camaras'] ?? null, ['productos', 'ambos'], true);
        }

        return ($valores[$permiso] ?? null) === true;
    }

    /**
     * @param  array<string, mixed>  $identidad
     */
    private function tieneModulo(array $identidad, string $modulo): bool
    {
        if ($modulo === '') {
            return true;
        }

        $modulos = $this->valores($identidad)['modulos_acceso'] ?? null;
        if (! is_array($modulos)) {
            return true;
        }

        return in_array($modulo, $modulos, true)
            || array_intersect(self::ALIAS_MODULOS[$modulo] ?? [], $modulos) !== [];
    }

    /**
     * Igual que el menú: las claves de la identidad prevalecen sobre las capacidades.
     *
     * @param  array<string, mixed>  $identidad
     * @return array<string, mixed>
     */
    private function valores(array $identidad): array
    {
        $capacidades = is_array($identidad['capacidades'] ?? null) ? $identidad['capacidades'] : [];

        return array_merge($capacidades, $identidad);
    }
}

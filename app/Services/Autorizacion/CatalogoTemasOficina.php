<?php

namespace App\Services\Autorizacion;

use App\Enums\RolUsuario;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

final class CatalogoTemasOficina
{
    public const ADICIONALES = [
        'dark-princess' => 'Princesa Estelar',
        'light-garden' => 'Jardín Encantado',
        'dark-bit' => 'Modo bit',
        'dark-bit-neon' => 'Modo neón',
    ];

    private const ESTILOS_DE_PLANTA = [
        'personal-gundam' => ['nombre' => 'Gundam RX-78-2', 'color' => '#101e37', 'claro' => true],
        'personal-omegamon-x' => ['nombre' => 'Omegamon X', 'color' => '#0d111b', 'claro' => false],
        'personal-mugiwara' => ['nombre' => 'Mugiwara', 'color' => '#071c28', 'claro' => false],
    ];

    private ?array $personales = null;

    public function catalogo(): array
    {
        return self::ADICIONALES + array_column($this->personales(), 'nombre', 'clave');
    }

    public function preferencias(User $usuario): array
    {
        $personales = [];
        $temas = [];
        if ($usuario->activo && ! $usuario->esCliente()) {
            $correo = mb_strtolower(trim((string) $usuario->email));
            $perfil = $usuario->loadMissing('perfilAcceso')->perfilAcceso;
            if ($perfil === null || $perfil->activo) {
                $personales = $this->personales();
                $porCorreo = array_filter($personales, fn (array $tema): bool => $tema['correo'] === $correo);
                $permitidos = $perfil?->temas_oficina ?? [...$this->predeterminados($usuario, $correo), ...array_keys($porCorreo)];
                $temas = $this->ordenar($permitidos);
                $personales = array_intersect_key($personales, array_flip($temas));
            }
        }

        return [
            'temas_oficina' => $temas,
            'temas_oficina_personales' => (object) array_map(
                fn (array $tema): array => array_intersect_key($tema, array_flip(['nombre', 'color', 'claro'])),
                $personales,
            ),
        ];
    }

    /** @return list<string> */
    private function predeterminados(User $usuario, string $correo): array
    {
        $porRol = (array) (config('oficina.temas_por_rol', [])[$usuario->rol->value] ?? []);
        if ($usuario->rol === RolUsuario::Administrador) {
            array_push($porRol, 'dark-princess', 'light-garden');
        }
        $porCorreo = $correo === '' ? [] : array_keys(array_filter(
            (array) config('oficina.temas_personales', []),
            fn (mixed $correos): bool => in_array($correo, (array) $correos, true),
        ));

        return [...$porRol, ...$porCorreo];
    }

    /** @return list<string> */
    public function ordenar(array $temas): array
    {
        return array_values(array_intersect(array_keys($this->catalogo()), $temas));
    }

    public function hojaPersonal(User $usuario, string $clave): ?string
    {
        $permitidos = $this->preferencias($usuario)['temas_oficina_personales'];
        if (! isset($permitidos->{$clave})) {
            return null;
        }

        $tema = $this->personales()[$clave];
        $css = $this->leerArchivo($tema['ruta']);
        if ($css === null || $tema['ilustracion'] === null) {
            return $css;
        }

        $webp = $this->leerArchivo($tema['ilustracion']);
        if (! $this->esWebp($webp)) {
            return null;
        }

        return str_replace('__FOLIOS_TEMA_WEBP__', base64_encode($webp), $css);
    }

    private function leerArchivo(string $ruta, ?int $longitud = null): ?string
    {
        $contenido = is_file($ruta) && is_readable($ruta) ? @file_get_contents($ruta, length: $longitud) : false;

        return $contenido === false ? null : $contenido;
    }

    private function esWebp(?string $contenido): bool
    {
        return $contenido !== null && str_starts_with($contenido, 'RIFF') && substr($contenido, 8, 4) === 'WEBP';
    }

    private function personales(): array
    {
        if ($this->personales !== null) {
            return $this->personales;
        }

        // Las ilustraciones son opcionales y se instalan fuera del directorio público.
        $this->personales = [];
        $directorio = Storage::disk('local')->path('temas-oficina');
        $catalogo = json_decode($this->leerArchivo("{$directorio}/catalogo.json") ?? '', true);
        if (! is_array($catalogo)) {
            $catalogo = [];
        }
        $temasDePlanta = array_fill_keys(array_keys(self::ESTILOS_DE_PLANTA), null);
        foreach ($catalogo + $temasDePlanta as $clave => $tema) {
            if (! preg_match('/\Apersonal-[a-z0-9-]{1,40}\z/', (string) $clave)) {
                continue;
            }
            $correo = $tema['correo'] ?? null;
            $ruta = "{$directorio}/{$clave}.css";
            $ilustracion = null;
            if (isset(self::ESTILOS_DE_PLANTA[$clave])) {
                $arte = "{$directorio}/{$clave}.webp";
                $estilo = resource_path("temas-oficina/{$clave}.css");
                if ($this->esWebp($this->leerArchivo($arte, 12)) && is_file($estilo) && is_readable($estilo)) {
                    $tema = self::ESTILOS_DE_PLANTA[$clave];
                    $ruta = $estilo;
                    $ilustracion = $arte;
                }
            }
            if (! is_array($tema)
                || ! is_string($tema['nombre'] ?? null)
                || trim($tema['nombre']) === ''
                || ($correo !== null && (! is_string($correo) || ! filter_var($correo, FILTER_VALIDATE_EMAIL)))
                || ! is_string($tema['color'] ?? null)
                || ! preg_match('/\A#[a-fA-F0-9]{6}\z/', $tema['color'])
                || ! is_file($ruta)
                || ! is_readable($ruta)) {
                continue;
            }
            $this->personales[$clave] = [
                'clave' => $clave,
                'nombre' => $tema['nombre'],
                'correo' => $correo === null ? null : mb_strtolower($correo),
                'color' => $tema['color'],
                'claro' => ($tema['claro'] ?? false) === true,
                'ruta' => $ruta,
                'ilustracion' => $ilustracion,
            ];
        }

        return $this->personales;
    }
}

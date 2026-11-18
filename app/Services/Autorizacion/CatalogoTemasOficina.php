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
                $personales = array_filter($this->personales(), fn (array $tema): bool => $tema['correo'] === $correo);
                $permitidos = $perfil?->temas_oficina ?? [...$this->predeterminados($usuario, $correo), ...array_keys($personales)];
                $temas = array_values(array_intersect($this->ordenar($permitidos), [...array_keys(self::ADICIONALES), ...array_keys($personales)]));
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

        return isset($permitidos->{$clave}) ? $this->rutaPersonal($clave) : null;
    }

    private function rutaPersonal(string $clave): string
    {
        return Storage::disk('local')->path("temas-oficina/{$clave}.css");
    }

    private function personales(): array
    {
        if ($this->personales !== null) {
            return $this->personales;
        }

        // Paquetes locales opcionales. Los archivos viven fuera del directorio público.
        $this->personales = [];
        $ruta = Storage::disk('local')->path('temas-oficina/catalogo.json');
        if (! is_file($ruta) || ! is_readable($ruta)) {
            return $this->personales;
        }

        $catalogo = json_decode(file_get_contents($ruta), true);
        foreach (is_array($catalogo) ? $catalogo : [] as $clave => $tema) {
            if (! preg_match('/\Apersonal-[a-z0-9-]{1,40}\z/', (string) $clave)
                || ! is_array($tema)
                || ! is_string($tema['nombre'] ?? null)
                || trim($tema['nombre']) === ''
                || ! filter_var($tema['correo'] ?? null, FILTER_VALIDATE_EMAIL)
                || ! is_string($tema['color'] ?? null)
                || ! preg_match('/\A#[a-fA-F0-9]{6}\z/', $tema['color'])
                || ! is_file($this->rutaPersonal($clave))
                || ! is_readable($this->rutaPersonal($clave))) {
                continue;
            }
            $this->personales[$clave] = [
                'clave' => $clave,
                'nombre' => $tema['nombre'],
                'correo' => mb_strtolower($tema['correo']),
                'color' => $tema['color'],
                'claro' => ($tema['claro'] ?? false) === true,
            ];
        }

        return $this->personales;
    }
}

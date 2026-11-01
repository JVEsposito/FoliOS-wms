<?php

use App\Services\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROLES_PREDETERMINADOS = [
        'administrador',
        'supervisor_frio',
        'digitador_materia_prima',
        'consulta',
    ];

    public function up(): void
    {
        $this->actualizarPerfiles(fn (array $modulos): array => array_values(array_unique([
            ...$modulos,
            CatalogoModulosAcceso::OFICINA_ORDENES_PROCESO,
        ])));
    }

    public function down(): void
    {
        $this->actualizarPerfiles(fn (array $modulos): array => array_values(array_filter(
            $modulos,
            fn (string $modulo): bool => $modulo !== CatalogoModulosAcceso::OFICINA_ORDENES_PROCESO,
        )));
    }

    /** @param callable(array<int, string>): array<int, string> $cambio */
    private function actualizarPerfiles(callable $cambio): void
    {
        DB::table('perfiles_acceso')
            ->where('predeterminado', true)
            ->whereIn('rol_base', self::ROLES_PREDETERMINADOS)
            ->select(['id', 'modulos'])
            ->orderBy('id')
            ->each(function (object $perfil) use ($cambio): void {
                $modulos = json_decode((string) $perfil->modulos, true);
                $modulos = is_array($modulos) ? array_values(array_filter($modulos, is_string(...))) : [];

                DB::table('perfiles_acceso')
                    ->where('id', $perfil->id)
                    ->update([
                        'modulos' => json_encode($cambio($modulos), JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
            });
    }
};

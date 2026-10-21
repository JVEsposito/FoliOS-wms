<?php

use App\Services\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * El perfil inicial de Administrador guarda su lista de módulos: se le agrega Proveedores de Finanzas.
 * Ningún otro perfil lo recibe; quien lo necesite lo asigna desde Accesos.
 */
return new class extends Migration
{
    public function up(): void
    {
        $nuevos = [CatalogoModulosAcceso::OFICINA_FINANZAS_PROVEEDORES];

        DB::table('perfiles_acceso')
            ->where('predeterminado', true)
            ->where('rol_base', 'administrador')
            ->orderBy('id')
            ->each(function (object $perfil) use ($nuevos): void {
                $modulos = json_decode((string) $perfil->modulos, true) ?: [];
                $faltantes = array_values(array_diff($nuevos, $modulos));
                if ($faltantes === []) {
                    return;
                }

                DB::table('perfiles_acceso')->where('id', $perfil->id)->update([
                    'modulos' => json_encode([...$modulos, ...$faltantes], JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        // Preserva ajustes posteriores del administrador en los perfiles.
    }
};

<?php

use App\Services\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * El perfil inicial de Administrador guarda su lista de módulos: se le agrega Finanzas.
 * Ningún otro perfil lo recibe; quien lo necesite lo asigna desde Accesos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('perfiles_acceso')
            ->where('predeterminado', true)
            ->where('rol_base', 'administrador')
            ->orderBy('id')
            ->each(function (object $perfil): void {
                $modulos = json_decode((string) $perfil->modulos, true) ?: [];
                if (in_array(CatalogoModulosAcceso::OFICINA_FINANZAS_PREFACTURA, $modulos, true)) {
                    return;
                }

                DB::table('perfiles_acceso')->where('id', $perfil->id)->update([
                    'modulos' => json_encode([...$modulos, CatalogoModulosAcceso::OFICINA_FINANZAS_PREFACTURA], JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        // Preserva ajustes posteriores del administrador en los perfiles.
    }
};

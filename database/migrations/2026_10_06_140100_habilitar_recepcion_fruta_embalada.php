<?php

use App\Services\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('perfiles_acceso')->where('predeterminado', true)->orderBy('id')->each(function (object $perfil): void {
            $modulos = json_decode((string) $perfil->modulos, true) ?: [];
            $tablet = json_decode((string) $perfil->modulos_tablet, true) ?: [];
            if (! in_array($perfil->rol_base, ['administrador', 'supervisor_frio', 'validador', 'consulta'], true)) {
                return;
            }
            if ($perfil->rol_base !== 'consulta' && ! in_array('frigorifico.validacion', $modulos, true)) {
                return;
            }
            $modulos[] = CatalogoModulosAcceso::OFICINA_RECEPCION_FRUTA_EMBALADA;
            if ($perfil->rol_base !== 'consulta') {
                $tablet[] = CatalogoModulosAcceso::TABLET_RECEPCION_FRUTA_EMBALADA;
            }
            DB::table('perfiles_acceso')->where('id', $perfil->id)->update([
                'modulos' => json_encode(array_values(array_unique($modulos)), JSON_THROW_ON_ERROR),
                'modulos_tablet' => json_encode(array_values(array_unique($tablet)), JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Preserva ajustes posteriores del administrador en los perfiles.
    }
};

<?php

use App\Services\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('perfiles_acceso')->where('predeterminado', true)
            ->whereIn('rol_base', ['guardia_porteria', 'administrador'])
            ->orderBy('id')->each(function (object $perfil): void {
                $oficinas = json_decode($perfil->modulos, true) ?: [];
                if (! in_array(CatalogoModulosAcceso::OFICINA_PORTERIA, $oficinas, true)) {
                    return;
                }
                $moviles = json_decode($perfil->modulos_tablet ?? '[]', true) ?: [];
                if (in_array(CatalogoModulosAcceso::TABLET_PORTERIA, $moviles, true)) {
                    return;
                }
                DB::table('perfiles_acceso')->where('id', $perfil->id)->update([
                    'modulos_tablet' => json_encode([...$moviles, CatalogoModulosAcceso::TABLET_PORTERIA]),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        DB::table('perfiles_acceso')->where('predeterminado', true)
            ->whereIn('rol_base', ['guardia_porteria', 'administrador'])
            ->orderBy('id')->each(function (object $perfil): void {
                $moviles = json_decode($perfil->modulos_tablet ?? '[]', true) ?: [];
                DB::table('perfiles_acceso')->where('id', $perfil->id)->update([
                    'modulos_tablet' => json_encode(array_values(array_diff($moviles, [CatalogoModulosAcceso::TABLET_PORTERIA]))),
                    'updated_at' => now(),
                ]);
            });
    }
};

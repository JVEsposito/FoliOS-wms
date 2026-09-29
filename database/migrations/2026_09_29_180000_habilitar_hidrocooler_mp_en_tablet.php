<?php

use AppServices\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('perfiles_acceso')
            ->where('predeterminado', true)
            ->where('rol_base', '!=', 'consulta')
            ->select(['id', 'modulos', 'modulos_tablet'])
            ->orderBy('id')
            ->each(function (object $perfil): void {
                $modulos = json_decode((string) $perfil->modulos, true);
                $modulosTablet = json_decode((string) $perfil->modulos_tablet, true);

                if (! is_array($modulos) || ! in_array('materia-prima.hidrocooler', $modulos, true)) {
                    return;
                }

                $modulosTablet = is_array($modulosTablet) ? $modulosTablet : [];
                if (in_array(CatalogoModulosAcceso::TABLET_HIDROCOOLER_MP, $modulosTablet, true)) {
                    return;
                }

                DB::table('perfiles_acceso')->where('id', $perfil->id)->update([
                    'modulos_tablet' => json_encode([
                        ...$modulosTablet,
                        CatalogoModulosAcceso::TABLET_HIDROCOOLER_MP,
                    ], JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        // El administrador puede haber habilitado este módulo manualmente en un perfil.
        // Conservar las decisiones de acceso al revertir una migración de datos.
    }
};

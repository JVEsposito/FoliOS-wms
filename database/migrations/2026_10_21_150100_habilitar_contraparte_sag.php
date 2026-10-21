<?php

use App\Services\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('perfiles_acceso')->where('predeterminado', true)->where('rol_base', 'administrador')->get()
            ->each(function ($perfil): void {
                $modulos = json_decode($perfil->modulos, true) ?: [];
                DB::table('perfiles_acceso')->where('id', $perfil->id)->update([
                    'modulos' => json_encode(array_values(array_unique([...$modulos, CatalogoModulosAcceso::OFICINA_CONTRAPARTE_SAG]))),
                    'updated_at' => now(),
                ]);
            });
        DB::table('perfiles_acceso')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'codigo' => 'CONTRAPARTE_SAG', 'nombre' => 'Contraparte SAG',
            'descripcion' => 'Existencias, documentos y seguimiento fitosanitario. La autorización ante SAG se registra por separado.',
            'rol_base' => 'supervisor_frio',
            'modulos' => json_encode([CatalogoModulosAcceso::OFICINA_CONTRAPARTE_SAG, 'frigorifico.inspeccion-sag']),
            'modulos_tablet' => '[]', 'pantalla_inicio' => '/oficina/frigorifico/contraparte-sag',
            'activo' => true, 'predeterminado' => false, 'protegido' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Conserva los perfiles que administración haya asignado o personalizado.
    }
};

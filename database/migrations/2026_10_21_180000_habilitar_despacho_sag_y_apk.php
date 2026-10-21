<?php

use App\Services\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('formatos_registro')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'codigo' => 'F-CER-PPS-PA-001', 'nombre' => 'Preparación de despacho SAG / contingencia',
            'version' => '01', 'fecha_vigencia' => '2026-10-21', 'localidad' => 'Rengo',
            'activo' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('perfiles_acceso')->where(fn ($q) => $q->where('codigo', 'CONTRAPARTE_SAG')->orWhere(fn ($p) => $p->where('predeterminado', true)->where('rol_base', 'administrador')))->get()
            ->each(function ($perfil): void {
                $modulos = json_decode($perfil->modulos_tablet, true) ?: [];
                DB::table('perfiles_acceso')->where('id', $perfil->id)->update([
                    'modulos_tablet' => json_encode(array_values(array_unique([...$modulos, CatalogoModulosAcceso::TABLET_CONTRAPARTE_SAG]))), 'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        // Conserva los formatos emitidos y los permisos personalizados.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('formatos_registro')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'codigo' => 'F-CER-PPS-PA-002', 'nombre' => 'Cambio de mercado SAG',
            'version' => '01', 'fecha_vigencia' => '2026-10-21', 'localidad' => 'Rengo',
            'activo' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Se conserva el formato utilizado por documentos históricos.
    }
};

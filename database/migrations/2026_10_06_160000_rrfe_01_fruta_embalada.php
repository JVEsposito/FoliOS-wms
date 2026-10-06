<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recepciones_fruta_embalada', function (Blueprint $table): void {
            $table->json('formato_rrfe_snapshot')->nullable();
        });
        DB::table('formatos_registro')->insert([
            'id' => (string) Str::uuid(), 'codigo' => 'RRFE-01', 'nombre' => 'Registro recepción de fruta embalada',
            'version' => '1', 'fecha_vigencia' => '2026-03-02', 'localidad' => 'Rengo',
            'activo' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $formato = DB::table('formatos_registro')->where('codigo', 'RRFE-01')->first();
        if ($formato && ! DB::table('eventos_formato_registro')->where('formato_registro_id', $formato->id)->exists()) {
            DB::table('formatos_registro')->where('id', $formato->id)->delete();
        }
        Schema::table('recepciones_fruta_embalada', fn (Blueprint $table) => $table->dropColumn('formato_rrfe_snapshot'));
    }
};

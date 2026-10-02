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
        Schema::create('formatos_registro', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('codigo', 30)->unique();
            $table->string('nombre', 150);
            $table->string('version', 20);
            $table->date('fecha_vigencia');
            $table->string('localidad', 255);
            $table->boolean('activo')->default(true);
            $table->foreignUuid('creado_por_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('actualizado_por_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('eventos_formato_registro', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('formato_registro_id')->constrained('formatos_registro')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->json('antes')->nullable();
            $table->json('despues');
            $table->timestamps();
        });
        Schema::table('recepciones_romana', function (Blueprint $table): void {
            $table->string('formato_registro_codigo', 30)->nullable();
            $table->string('formato_registro_version', 20)->nullable();
            $table->date('formato_registro_fecha_vigencia')->nullable();
            $table->string('formato_registro_localidad', 255)->nullable();
        });

        DB::table('formatos_registro')->insert([
            'id' => (string) Str::uuid(),
            'codigo' => 'RPR-01',
            'nombre' => 'Registro de pesaje romana',
            'version' => '1',
            'fecha_vigencia' => '2026-08-31',
            'localidad' => 'Rengo, Carretera 5 Sur, km 108, Rosario, comuna de Rengo',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('recepciones_romana', function (Blueprint $table): void {
            $table->dropColumn([
                'formato_registro_codigo', 'formato_registro_version',
                'formato_registro_fecha_vigencia', 'formato_registro_localidad',
            ]);
        });
        Schema::dropIfExists('eventos_formato_registro');
        Schema::dropIfExists('formatos_registro');
    }
};

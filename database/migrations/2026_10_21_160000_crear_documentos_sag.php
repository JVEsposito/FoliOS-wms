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
        Schema::create('documentos_sag', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('temporada_id')->constrained('temporadas')->restrictOnDelete();
            $table->string('tipo', 30);
            $table->string('estado', 30)->default('borrador');
            $table->unsignedInteger('numero')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->date('fecha_actividad');
            $table->foreignUuid('responsable_id')->constrained('responsables_contraparte_sag')->restrictOnDelete();
            $table->uuid('repaletizaje_id')->nullable()->unique();
            $table->uuid('lote_inspeccion_sag_id')->nullable()->unique();
            $table->uuid('embarque_id')->nullable()->index();
            $table->json('encabezado');
            $table->json('entrada');
            $table->json('detalle');
            $table->json('formato_snapshot')->nullable();
            $table->json('responsable_snapshot')->nullable();
            $table->json('planta_snapshot')->nullable();
            $table->timestamp('emitido_at')->nullable();
            $table->foreignId('creado_por_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['temporada_id', 'tipo', 'numero']);
            $table->index(['temporada_id', 'fecha_actividad', 'tipo', 'estado'], 'documentos_sag_listado');
        });
        Schema::create('secuencias_documentos_sag', function (Blueprint $table) {
            $table->uuid('temporada_id');
            $table->string('tipo', 30);
            $table->unsignedInteger('ultimo')->default(0);
            $table->primary(['temporada_id', 'tipo']);
        });
        Schema::create('operaciones_documento_sag', function (Blueprint $table) {
            $table->uuid('operacion_id')->primary();
            $table->foreignUuid('documento_id')->constrained('documentos_sag')->restrictOnDelete();
            $table->string('accion', 40);
            $table->string('payload_hash', 64);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at');
        });
        Schema::create('archivos_documento_sag', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('documento_id')->constrained('documentos_sag')->restrictOnDelete();
            $table->string('nombre', 200);
            $table->string('ruta');
            $table->string('mime', 100);
            $table->string('sha256', 64);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['documento_id', 'sha256']);
        });
        foreach (['F-PA-CFT-DR-010' => 'Repaletizaje SAG', 'F-PA-CFT-DR-011' => 'Anulación de productos aprobados SAG'] as $codigo => $nombre) {
            DB::table('formatos_registro')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'codigo' => $codigo, 'nombre' => $nombre,
                'version' => '01', 'fecha_vigencia' => '2026-10-21', 'localidad' => 'Rengo',
                'activo' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (['archivos_documento_sag', 'operaciones_documento_sag', 'secuencias_documentos_sag', 'documentos_sag'] as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }
};

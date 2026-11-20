<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diagramas de presentación de pallets para inspección SAG/USDA. Se usan con los permisos
 * de Inspección SAG, así que no se agrega un módulo de acceso nuevo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagramas_pallets_sag', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('temporada_id')->constrained('temporadas');
            $table->string('tipo', 10);
            $table->string('numero', 30);
            $table->string('csp', 50)->nullable();
            $table->string('folio', 60)->nullable();
            $table->date('fecha')->nullable();
            $table->string('lote', 100)->nullable();
            $table->string('especie', 80)->nullable();
            $table->string('exportadora', 150)->nullable();
            $table->json('pallets');
            $table->json('cajas_marcadas');
            $table->unsignedSmallInteger('total_pallets');
            $table->unsignedInteger('total_cajas');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('creado_por_user_id')->constrained('users');
            $table->foreignId('actualizado_por_user_id')->constrained('users');
            $table->timestamps();
            $table->unique(['temporada_id', 'tipo', 'numero'], 'diagrama_pallets_sag_numero_unico');
            $table->index(['temporada_id', 'updated_at']);
        });
        Schema::create('eventos_diagrama_pallets_sag', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('diagrama_id')->constrained('diagramas_pallets_sag');
            $table->uuid('operacion_id')->unique();
            $table->string('payload_hash', 64);
            $table->foreignId('user_id')->constrained('users');
            $table->string('usuario_nombre');
            $table->unsignedInteger('version');
            $table->json('despues');
            $table->timestamp('created_at');
            $table->unique(['diagrama_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_diagrama_pallets_sag');
        Schema::dropIfExists('diagramas_pallets_sag');
    }
};

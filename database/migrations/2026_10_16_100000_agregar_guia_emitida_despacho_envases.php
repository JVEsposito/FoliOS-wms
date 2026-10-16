<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La guía de despacho tributaria se emite en el sistema de facturación. FoliOS guarda su
 * número (el GDE-… sigue siendo la referencia interna) y el archivo escaneado como respaldo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guias_despacho_envases', function (Blueprint $table): void {
            $table->string('numero_guia_despacho', 20)->nullable()->after('numero');
            $table->index('numero_guia_despacho', 'guia_envases_numero_emitida_idx');
        });

        Schema::create('archivos_guias_despacho_envases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('guia_despacho_envase_id');
            $table->foreign('guia_despacho_envase_id', 'archivo_guia_envases_guia_fk')
                ->references('id')
                ->on('guias_despacho_envases')
                ->restrictOnDelete();
            $table->string('ruta', 255);
            $table->string('nombre_original', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('bytes');
            $table->char('sha256', 64);
            $table->foreignId('subido_por_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('subido_at');
            // Reemplazar un archivo no lo borra: queda en el historial de la guía.
            $table->timestamp('reemplazado_at')->nullable();
            $table->timestamps();
            $table->index(['guia_despacho_envase_id', 'reemplazado_at'], 'archivo_guia_envases_vigente_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archivos_guias_despacho_envases');
        Schema::table('guias_despacho_envases', function (Blueprint $table): void {
            $table->dropIndex('guia_envases_numero_emitida_idx');
            $table->dropColumn('numero_guia_despacho');
        });
    }
};

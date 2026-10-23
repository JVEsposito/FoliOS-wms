<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operaciones_retiro_materiales', function (Blueprint $table): void {
            $table->dateTime('fecha_despacho')->nullable();
            $table->boolean('provisional')->default(false);
            $table->boolean('registro_retroactivo')->default(false);
            $table->string('numero_documento', 50)->nullable();
            $table->date('fecha_documento')->nullable();
            $table->text('motivo_registro_tardio')->nullable();
            $table->dateTime('fecha_limite_regularizacion')->nullable()->index('retiros_plazo_regularizacion_idx');
            $table->foreignId('autorizado_por_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('autorizado_at')->nullable();
            $table->text('motivo_autorizacion')->nullable();
            $table->foreignId('regularizado_por_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('regularizado_at')->nullable();
            $table->text('motivo_regularizacion')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->json('snapshot_registro')->nullable();
        });
        Schema::create('regularizaciones_despachos_materiales', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('operacion_retiro_material_id');
            $table->foreign('operacion_retiro_material_id', 'reg_despacho_entrega_fk')->references('id')->on('operaciones_retiro_materiales')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('payload_hash', 64);
            $table->json('antes');
            $table->json('despues');
            $table->text('motivo');
            $table->boolean('fuera_plazo');
            $table->unsignedInteger('version_anterior');
            $table->unsignedInteger('version_resultante');
            $table->dateTime('ocurrido_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regularizaciones_despachos_materiales');
        Schema::table('operaciones_retiro_materiales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('autorizado_por_user_id');
            $table->dropConstrainedForeignId('regularizado_por_user_id');
            $table->dropIndex('retiros_plazo_regularizacion_idx');
            $table->dropColumn(['fecha_despacho', 'provisional', 'registro_retroactivo', 'numero_documento', 'fecha_documento', 'motivo_registro_tardio', 'fecha_limite_regularizacion', 'autorizado_at', 'motivo_autorizacion', 'regularizado_at', 'motivo_regularizacion', 'version', 'snapshot_registro']);
        });
    }
};

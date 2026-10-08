<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items_materiales', function (Blueprint $table): void {
            $table->unsignedSmallInteger('dias_alerta_vencimiento')->nullable();
        });
        Schema::table('folios_materiales', function (Blueprint $table): void {
            $table->boolean('bloqueado_por_vencimiento')->default(false);
            $table->text('motivo_bloqueo_previo_vencimiento')->nullable();
            $table->index('fecha_vencimiento');
        });
        Schema::table('eventos_bloqueos_materiales', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->json('metadatos')->nullable();
        });
        Schema::table('detalles_despacho_materiales', function (Blueprint $table): void {
            $table->decimal('cantidad_sin_reserva_por_vencimiento', 14, 3)->default(0);
        });
        Schema::table('ordenes_transformacion_materiales', function (Blueprint $table): void {
            $table->json('faltantes_por_vencimiento')->nullable();
        });
        Schema::create('procesamientos_vencimientos_materiales', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('fecha_chile')->index();
            $table->unsignedInteger('folios_procesados');
            $table->unsignedInteger('reservas_liberadas');
            $table->unsignedInteger('reservas_reasignadas');
            $table->json('lineas_insuficientes');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procesamientos_vencimientos_materiales');
        Schema::table('ordenes_transformacion_materiales', fn (Blueprint $table) => $table->dropColumn('faltantes_por_vencimiento'));
        Schema::table('detalles_despacho_materiales', fn (Blueprint $table) => $table->dropColumn('cantidad_sin_reserva_por_vencimiento'));
        Schema::table('eventos_bloqueos_materiales', fn (Blueprint $table) => $table->dropColumn('metadatos'));
        // Se conserva user_id nullable: la auditoría del sistema no se elimina al revertir.
        Schema::table('folios_materiales', function (Blueprint $table): void {
            $table->dropIndex(['fecha_vencimiento']);
            $table->dropColumn(['bloqueado_por_vencimiento', 'motivo_bloqueo_previo_vencimiento']);
        });
        Schema::table('items_materiales', fn (Blueprint $table) => $table->dropColumn('dias_alerta_vencimiento'));
    }
};

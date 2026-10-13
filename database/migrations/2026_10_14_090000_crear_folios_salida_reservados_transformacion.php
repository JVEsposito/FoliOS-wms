<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Folios de salida que la orden reserva al planificarse: imprimibles antes de producir.
        Schema::create('folios_salida_reservados_transformacion', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('orden_transformacion_material_id');
            $table->foreign('orden_transformacion_material_id', 'folios_salida_reservados_orden_fk')
                ->references('id')
                ->on('ordenes_transformacion_materiales')
                ->restrictOnDelete();
            $table->unsignedInteger('secuencia');
            $table->string('numero_folio', 64)->unique('folios_salida_reservados_numero_uq');
            $table->decimal('cantidad_teorica', 14, 3);
            $table->string('estado', 20)->default('reservado')->index('folios_salida_reservados_estado_idx');
            $table->uuid('lote_transformacion_material_id')->nullable();
            $table->foreign('lote_transformacion_material_id', 'folios_salida_reservados_lote_fk')
                ->references('id')
                ->on('lotes_transformacion_materiales')
                ->restrictOnDelete();
            $table->uuid('folio_id')->nullable();
            $table->foreign('folio_id', 'folios_salida_reservados_folio_fk')
                ->references('id')
                ->on('folios')
                ->restrictOnDelete();
            $table->decimal('cantidad_real', 14, 3)->nullable();
            $table->unsignedInteger('impresiones')->default(0);
            $table->dateTime('ultima_impresion_at')->nullable();
            $table->boolean('requiere_reimpresion')->default(false);
            $table->string('motivo_anulacion', 200)->nullable();
            $table->dateTime('anulado_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['orden_transformacion_material_id', 'secuencia'],
                'folios_salida_reservados_orden_secuencia_uq',
            );
        });

        // Una etiqueta impresa puede corresponder a un folio real o a un folio reservado.
        Schema::table('folios_trabajos_impresion_materiales', function (Blueprint $table): void {
            $table->uuid('folio_id')->nullable()->change();
            $table->uuid('folio_salida_reservado_id')->nullable()->after('folio_id');
            $table->foreign('folio_salida_reservado_id', 'folios_trabajos_impresion_reservado_fk')
                ->references('id')
                ->on('folios_salida_reservados_transformacion')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('folios_trabajos_impresion_materiales', function (Blueprint $table): void {
            $table->dropForeign('folios_trabajos_impresion_reservado_fk');
            $table->dropColumn('folio_salida_reservado_id');
        });
        Schema::dropIfExists('folios_salida_reservados_transformacion');
    }
};

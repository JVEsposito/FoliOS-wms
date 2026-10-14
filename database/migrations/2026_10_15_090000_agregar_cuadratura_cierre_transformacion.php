<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Las órdenes existentes conservan el flujo por lote.
        Schema::table('ordenes_transformacion_materiales', function (Blueprint $table): void {
            $table->string('modo_operacion', 30)->default('por_lote')->after('estado');
        });

        // Parte del retiro que volvió al folio de origen por quedar sin armar.
        Schema::table('reservas_transformacion_materiales', function (Blueprint $table): void {
            $table->decimal('cantidad_devuelta', 14, 3)->default(0)->after('cantidad_consumida');
        });

        // Cuadratura por componente al cerrar una orden en modo cuadratura.
        Schema::create('cuadraturas_transformacion_materiales', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('orden_transformacion_material_id');
            $table->foreign('orden_transformacion_material_id', 'cuadraturas_transformacion_orden_fk')
                ->references('id')
                ->on('ordenes_transformacion_materiales')
                ->restrictOnDelete();
            $table->uuid('item_material_id');
            $table->foreign('item_material_id', 'cuadraturas_transformacion_item_fk')
                ->references('id')
                ->on('items_materiales')
                ->restrictOnDelete();
            $table->boolean('es_componente_principal')->default(false);
            $table->decimal('cantidad_retirada', 14, 3);
            $table->decimal('cantidad_teorica', 14, 3);
            $table->decimal('cantidad_merma', 14, 3);
            $table->decimal('cantidad_sin_armar', 14, 3);
            $table->decimal('cantidad_utilizada', 14, 3);
            $table->decimal('desviacion', 14, 3);
            $table->timestamps();

            $table->unique(
                ['orden_transformacion_material_id', 'item_material_id'],
                'cuadraturas_transformacion_orden_item_uq',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuadraturas_transformacion_materiales');
        Schema::table('reservas_transformacion_materiales', function (Blueprint $table): void {
            $table->dropColumn('cantidad_devuelta');
        });
        Schema::table('ordenes_transformacion_materiales', function (Blueprint $table): void {
            $table->dropColumn('modo_operacion');
        });
    }
};

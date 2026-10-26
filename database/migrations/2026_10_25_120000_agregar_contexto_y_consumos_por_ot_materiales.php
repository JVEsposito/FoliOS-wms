<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes_transformacion_materiales', function (Blueprint $t): void {
            $t->string('referencia_ot', 100)->nullable();
            $t->string('proceso', 100)->nullable();
            $t->string('operador_nombre', 150)->nullable();
            $t->index(['temporada_id', 'fecha_operacional', 'turno'], 'consumos_ot_periodo');
        });
        Schema::create('imputaciones_consumo_materiales', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('movimiento_almacen_id')->constrained('movimientos_almacenes_materiales', indexName: 'imputacion_consumo_mov_fk');
            $t->foreignUuid('orden_transformacion_material_id')->constrained('ordenes_transformacion_materiales', indexName: 'imputacion_consumo_orden_fk');
            $t->decimal('cantidad', 14, 3);
            $t->decimal('cantidad_merma', 14, 3)->default(0);
            $t->json('snapshot');
            $t->timestamps();
            $t->unique(['movimiento_almacen_id', 'orden_transformacion_material_id'], 'imputacion_consumo_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imputaciones_consumo_materiales');
        Schema::table('ordenes_transformacion_materiales', function (Blueprint $t): void {
            $t->dropIndex('consumos_ot_periodo');
            $t->dropColumn(['referencia_ot', 'proceso', 'operador_nombre']);
        });
    }
};

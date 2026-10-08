<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verificaciones_ubicacion', function (Blueprint $t): void {
            $t->string('contenido', 20)->default('productos');
            $t->boolean('verificar_cantidad')->default(false);
            $t->decimal('tolerancia_cantidad_pct', 6, 3)->default(2);
        });
        Schema::table('verificaciones_ubicacion_items', fn (Blueprint $t) => $t->json('snapshot_materiales')->nullable());
        Schema::create('verificaciones_ubicacion_folios', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('verificacion_ubicacion_item_id')->constrained('verificaciones_ubicacion_items', 'id', 'verificacion_folios_item_fk')->restrictOnDelete();
            $t->foreignUuid('folio_esperado_id')->nullable()->constrained('folios')->restrictOnDelete();
            $t->foreignUuid('folio_encontrado_id')->nullable()->constrained('folios')->restrictOnDelete();
            $t->string('folio_encontrado_numero', 100)->nullable();
            $t->decimal('cantidad_esperada', 14, 3)->nullable();
            $t->decimal('cantidad_contada', 14, 3)->nullable();
            $t->string('unidad_medida', 30)->nullable();
            $t->foreignUuid('otra_posicion_id')->nullable()->constrained('posiciones')->restrictOnDelete();
            $t->string('resultado', 25);
            $t->timestamps();
        });
        Schema::table('incidencias_verificacion_ubicacion', function (Blueprint $t): void {
            // El FK antiguo necesita un índice propio antes de retirar su único.
            $t->index('verificacion_ubicacion_item_id', 'incidencia_verificacion_item_idx');
            $t->dropUnique('incidencia_verificacion_item_unique');
            $t->foreignUuid('verificacion_ubicacion_folio_id')->nullable()->unique('incidencia_verificacion_folio_unique')->constrained('verificaciones_ubicacion_folios', 'id', 'incidencia_verificacion_folio_fk')->restrictOnDelete();
            $t->decimal('cantidad_esperada', 14, 3)->nullable();
            $t->decimal('cantidad_contada', 14, 3)->nullable();
            $t->string('unidad_medida', 30)->nullable();
            $t->foreignId('resuelto_por_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('resuelta_at')->nullable();
            $t->string('resolucion', 1000)->nullable();
        });
        Schema::table('movimientos_almacenes_materiales', function (Blueprint $t): void {
            $t->foreignUuid('incidencia_verificacion_id')->nullable()->constrained('incidencias_verificacion_ubicacion', 'id', 'movimiento_material_verificacion_fk')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_almacenes_materiales', function (Blueprint $t): void {
            $t->dropForeign('movimiento_material_verificacion_fk');
            $t->dropColumn('incidencia_verificacion_id');
        });
        Schema::table('incidencias_verificacion_ubicacion', function (Blueprint $t): void {
            $t->dropForeign('incidencia_verificacion_folio_fk');
            $t->dropUnique('incidencia_verificacion_folio_unique');
            $t->dropColumn('verificacion_ubicacion_folio_id');
            $t->dropConstrainedForeignId('resuelto_por_user_id');
            $t->dropColumn(['cantidad_esperada', 'cantidad_contada', 'unidad_medida', 'resuelta_at', 'resolucion']);
            // No reintroducir el único: puede haber varias incidencias históricas.
        });
        Schema::dropIfExists('verificaciones_ubicacion_folios');
        Schema::table('verificaciones_ubicacion_items', fn (Blueprint $t) => $t->dropColumn('snapshot_materiales'));
        Schema::table('verificaciones_ubicacion', fn (Blueprint $t) => $t->dropColumn(['contenido', 'verificar_cantidad', 'tolerancia_cantidad_pct']));
    }
};

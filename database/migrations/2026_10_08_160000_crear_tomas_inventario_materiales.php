<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tomas_inventario_materiales', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('temporada_id')->constrained('temporadas');
            $t->json('camara_ids');
            $t->string('categoria')->nullable();
            $t->string('estado')->default('borrador');
            $t->uuid('operacion_id')->unique();
            $t->string('payload_hash', 64);
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('abierta_por_user_id')->constrained('users');
            $t->foreignId('revisada_por_user_id')->nullable()->constrained('users');
            $t->foreignId('aprobada_por_user_id')->nullable()->constrained('users');
            $t->timestamp('abierta_at')->nullable();
            $t->timestamp('revisada_at')->nullable();
            $t->timestamp('aprobada_at')->nullable();
            $t->timestamp('anulada_at')->nullable();
            $t->text('motivo_anulacion')->nullable();
            $t->json('foto')->nullable();
            $t->json('operaciones')->nullable();
            $t->timestamps();
        });
        Schema::create('tomas_inventario_materiales_posiciones', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('toma_id')->constrained('tomas_inventario_materiales');
            $t->foreignUuid('posicion_id')->constrained('posiciones');
            $t->foreignId('user_id')->constrained('users');
            $t->string('estado')->default('pendiente');
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('contada_at')->nullable();
            $t->json('lecturas')->nullable();
            $t->timestamps();
            $t->unique(['toma_id', 'posicion_id'], 'toma_posicion_unica');
        });
        Schema::create('tomas_inventario_materiales_resultados', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('toma_posicion_id')->constrained('tomas_inventario_materiales_posiciones', indexName: 'toma_resultado_posicion_fk');
            $t->unsignedInteger('lectura_version');
            $t->boolean('vigente')->default(true);
            $t->foreignUuid('folio_id')->nullable()->constrained('folios');
            $t->string('numero_folio');
            $t->foreignUuid('saldo_id')->nullable()->constrained('saldos_materiales_almacenes');
            $t->foreignUuid('item_material_id')->nullable()->constrained('items_materiales');
            $t->string('unidad_medida')->nullable();
            $t->decimal('cantidad_inicial', 14, 3)->default(0);
            $t->decimal('cantidad_esperada', 14, 3)->default(0);
            $t->decimal('cantidad_contada', 14, 3)->default(0);
            $t->decimal('diferencia', 14, 3)->default(0);
            $t->decimal('diferencia_pct', 14, 3)->nullable();
            $t->string('tipo');
            $t->json('movimientos')->nullable();
            $t->json('saldo_confirmado')->nullable();
            $t->string('accion')->nullable();
            $t->text('motivo')->nullable();
            $t->foreignUuid('posicion_destino_id')->nullable()->constrained('posiciones');
            $t->foreignUuid('movimiento_almacen_id')->nullable()->constrained('movimientos_almacenes_materiales', indexName: 'toma_resultado_ajuste_fk');
            $t->timestamps();
            $t->index(['toma_posicion_id', 'vigente'], 'toma_resultado_actual');
        });
        Schema::create('reubicaciones_toma_inventario_materiales', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('resultado_id')->unique()->constrained('tomas_inventario_materiales_resultados', indexName: 'toma_reubicacion_resultado_fk');
            $t->foreignUuid('saldo_id')->constrained('saldos_materiales_almacenes');
            $t->foreignUuid('posicion_origen_id')->nullable()->constrained('posiciones', indexName: 'toma_reubicacion_origen_fk');
            $t->foreignUuid('posicion_destino_id')->constrained('posiciones', indexName: 'toma_reubicacion_destino_fk');
            $t->decimal('cantidad', 14, 3);
            $t->foreignId('user_id')->constrained('users');
            $t->text('motivo');
            $t->timestamps();
        });
        Schema::table('movimientos_almacenes_materiales', function (Blueprint $t): void {
            $t->foreignUuid('toma_inventario_id')->nullable()->constrained('tomas_inventario_materiales');
        });
        Schema::table('incidencias_verificacion_ubicacion', function (Blueprint $t): void {
            $t->string('tipo_resolucion')->nullable();
            $t->uuid('resolucion_operacion_id')->nullable()->unique('incidencia_resolucion_operacion_unica');
            $t->string('resolucion_payload_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('incidencias_verificacion_ubicacion', function (Blueprint $t): void {
            $t->dropUnique('incidencia_resolucion_operacion_unica');
            $t->dropColumn(['tipo_resolucion', 'resolucion_operacion_id', 'resolucion_payload_hash']);
        });
        Schema::table('movimientos_almacenes_materiales', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('toma_inventario_id');
        });
        Schema::dropIfExists('reubicaciones_toma_inventario_materiales');
        Schema::dropIfExists('tomas_inventario_materiales_resultados');
        Schema::dropIfExists('tomas_inventario_materiales_posiciones');
        Schema::dropIfExists('tomas_inventario_materiales');
    }
};

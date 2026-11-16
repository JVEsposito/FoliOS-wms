<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estándares de consumo de materiales: cuánto material lleva cada caja de un embalaje
 * PT de un cliente. Un estándar puede venir de planta (ODBC) o ser propio de FoliOS;
 * sus versiones no se modifican y rigen desde una fecha de proceso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estandares_materiales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('temporada_id')->constrained('temporadas')->restrictOnDelete();
            $table->foreignUuid('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->foreignUuid('envase_validacion_id')->constrained('envases_validacion')->restrictOnDelete();
            $table->string('origen', 20);
            $table->string('codigo', 60);
            $table->string('descripcion', 200);
            $table->string('codigo_articulo', 100)->nullable();
            $table->string('descripcion_articulo', 200)->nullable();
            $table->string('especie', 100)->nullable();
            $table->boolean('activo')->default(true)->index();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('estandar_base_id')->nullable();
            $table->foreignId('creado_por_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actualizado_por_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['temporada_id', 'cliente_id', 'envase_validacion_id', 'origen'], 'estandar_material_embalaje_origen_unique');
            $table->index(['temporada_id', 'cliente_id', 'origen'], 'estandar_material_cliente_origen_index');
        });

        Schema::table('estandares_materiales', function (Blueprint $table) {
            $table->foreign('estandar_base_id')->references('id')->on('estandares_materiales')->restrictOnDelete();
        });

        Schema::create('versiones_estandares_materiales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('estandar_material_id')->constrained('estandares_materiales')->restrictOnDelete();
            $table->unsignedInteger('numero');
            $table->date('vigente_desde');
            $table->string('origen', 20);
            $table->string('motivo', 500)->nullable();
            $table->string('huella_origen', 64)->nullable();
            $table->json('snapshot');
            $table->foreignId('creado_por_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['estandar_material_id', 'numero'], 'version_estandar_material_numero_unique');
            $table->index(['estandar_material_id', 'vigente_desde'], 'version_estandar_material_vigencia_index');
        });

        Schema::create('lineas_estandares_materiales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('version_estandar_material_id');
            $table->foreign('version_estandar_material_id', 'linea_estandar_version_foreign')
                ->references('id')->on('versiones_estandares_materiales')->restrictOnDelete();
            $table->unsignedSmallInteger('orden');
            $table->foreignUuid('item_material_id')->nullable()->constrained('items_materiales')->restrictOnDelete();
            $table->string('codigo_insumo', 100);
            $table->string('descripcion_insumo', 200);
            $table->string('unidad_medida', 40)->nullable();
            $table->decimal('cantidad_por_caja', 16, 6);
            $table->string('redondeo', 10);
            $table->string('variacion_tipo', 20)->nullable();
            $table->string('variacion_valor', 120)->nullable();
            $table->timestamps();

            $table->index(['version_estandar_material_id', 'orden'], 'linea_estandar_version_orden_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lineas_estandares_materiales');
        Schema::dropIfExists('versiones_estandares_materiales');
        Schema::table('estandares_materiales', function (Blueprint $table) {
            $table->dropForeign(['estandar_base_id']);
        });
        Schema::dropIfExists('estandares_materiales');
    }
};

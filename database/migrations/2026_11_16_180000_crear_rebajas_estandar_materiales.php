<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rebaja por estándar: descuenta del almacén de Packing los materiales que lleva lo
 * validado en PT (día de proceso, cliente, marca, proceso y embalaje), según el estándar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rebajas_estandar_materiales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('operacion_id')->unique();
            $table->string('payload_hash', 64);
            $table->string('codigo', 20)->unique();
            $table->foreignUuid('temporada_id')->constrained('temporadas')->restrictOnDelete();
            $table->foreignUuid('almacen_material_id')->constrained('destinos_materiales')->restrictOnDelete();
            $table->date('desde')->nullable();
            $table->date('hasta')->nullable();
            $table->json('filtros');
            $table->string('estado', 20)->default('confirmada')->index();
            $table->integer('cajas')->default(0);
            $table->unsignedInteger('pallets')->default(0);
            $table->json('resumen');
            $table->string('observacion', 500)->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmada_at')->index();
            $table->timestamps();
        });

        // Sin FK a validaciones_pallet: la rebaja es de Bodega y conserva su copia aunque la
        // operación de frío se reinicie.
        Schema::create('producciones_rebaja_estandar', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('rebaja_estandar_material_id');
            $table->foreign('rebaja_estandar_material_id', 'produccion_rebaja_foreign')
                ->references('id')->on('rebajas_estandar_materiales')->restrictOnDelete();
            $table->uuid('validacion_pallet_id')->index();
            $table->string('numero_folio', 50);
            $table->unsignedSmallInteger('linea');
            $table->date('fecha_proceso')->index();
            $table->uuid('cliente_id');
            $table->string('marca', 150)->nullable();
            $table->string('proceso', 100)->nullable();
            $table->uuid('envase_validacion_id');
            $table->string('variedad', 120)->nullable();
            $table->string('calibre', 120)->nullable();
            $table->string('categoria', 120)->nullable();
            // Con signo: una corrección posterior a la rebaja se registra como ajuste negativo o positivo.
            $table->integer('cajas');
            $table->uuid('estandar_material_id');
            $table->uuid('version_estandar_material_id');
            $table->foreign('version_estandar_material_id', 'produccion_rebaja_version_foreign')
                ->references('id')->on('versiones_estandares_materiales')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('consumos_rebaja_estandar', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('rebaja_estandar_material_id');
            $table->foreign('rebaja_estandar_material_id', 'consumo_rebaja_foreign')
                ->references('id')->on('rebajas_estandar_materiales')->restrictOnDelete();
            $table->foreignUuid('item_material_id')->constrained('items_materiales')->restrictOnDelete();
            $table->string('unidad_medida', 40)->nullable();
            $table->string('redondeo', 10);
            $table->decimal('teorico', 18, 6);
            $table->decimal('fraccion_anterior', 18, 6)->default(0);
            $table->decimal('pendiente_anterior', 18, 6)->default(0);
            $table->decimal('a_descontar', 18, 6);
            $table->decimal('descontado', 18, 6);
            $table->decimal('pendiente', 18, 6)->default(0);
            $table->decimal('fraccion_resultante', 18, 6)->default(0);
            $table->unsignedSmallInteger('folios')->default(0);
            $table->timestamps();
        });

        // Fracción por descontar (Entero o milésimas) y faltante por falta de saldo, por almacén e ítem.
        Schema::create('saldos_rebaja_estandar', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('almacen_material_id')->constrained('destinos_materiales')->restrictOnDelete();
            $table->foreignUuid('item_material_id')->constrained('items_materiales')->restrictOnDelete();
            $table->decimal('fraccion', 18, 6)->default(0);
            $table->decimal('pendiente', 18, 6)->default(0);
            $table->timestamps();
            $table->unique(['almacen_material_id', 'item_material_id'], 'saldo_rebaja_almacen_item_unique');
        });

        DB::table('secuencias_documentos')->insertOrIgnore(['clave' => 'rebajas_estandar_materiales', 'ultimo_numero' => 0]);
    }

    public function down(): void
    {
        Schema::dropIfExists('saldos_rebaja_estandar');
        Schema::dropIfExists('consumos_rebaja_estandar');
        Schema::dropIfExists('producciones_rebaja_estandar');
        Schema::dropIfExists('rebajas_estandar_materiales');
        DB::table('secuencias_documentos')->where('clave', 'rebajas_estandar_materiales')->delete();
    }
};

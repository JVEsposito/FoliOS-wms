<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Copia local de la vista de estándares de planta (odbc_estandar_materiales). Cada lectura
 * guarda un estándar por empresa y artículo; la incorporación los convierte en estándares
 * de planta de FoliOS. Nada de lo leído descuenta materiales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lecturas_estandares_origen', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('temporada_id')->nullable()->constrained('temporadas')->nullOnDelete();
            $table->string('temporada_origen', 40);
            $table->uuid('conexion_externa_id')->nullable();
            $table->uuid('operacion_integracion_id')->nullable()->index();
            $table->string('estado', 20);
            $table->unsignedInteger('filas')->default(0);
            $table->unsignedInteger('estandares')->default(0);
            $table->unsignedInteger('filas_duplicadas')->default(0);
            $table->unsignedInteger('cabeceras_descartadas')->default(0);
            $table->string('mensaje', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('leida_at')->index();
            $table->timestamps();
        });

        Schema::create('estandares_origen', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('lectura_estandar_origen_id');
            $table->foreign('lectura_estandar_origen_id', 'estandar_origen_lectura_foreign')
                ->references('id')->on('lecturas_estandares_origen')->cascadeOnDelete();
            $table->string('cod_empresa', 40);
            $table->string('cod_articulo', 100);
            $table->string('codigo_estandar', 100)->nullable();
            $table->string('descripcion_estandar', 200)->nullable();
            $table->string('descripcion_articulo', 200)->nullable();
            $table->string('cod_especie', 40)->nullable();
            $table->string('desc_especie', 100)->nullable();
            $table->dateTime('fecha_creacion')->nullable();
            $table->dateTime('fecha_edicion')->nullable();
            $table->unsignedSmallInteger('cabeceras')->default(1);
            $table->string('huella', 64);
            $table->json('lineas');
            $table->timestamps();

            $table->index(['lectura_estandar_origen_id', 'cod_empresa', 'cod_articulo'], 'estandar_origen_lectura_articulo_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estandares_origen');
        Schema::dropIfExists('lecturas_estandares_origen');
    }
};

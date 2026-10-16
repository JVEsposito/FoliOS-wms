<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Copia de solo lectura del stock de producto terminado del sistema de origen (vía la
 * conexión externa) para saber qué falta por validar. No es operación de FoliOS: Validación
 * sigue siendo la única puerta a Frigorífico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lecturas_stock_origen', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('conexion_externa_id')->nullable()->constrained('conexiones_externas')->nullOnDelete();
            $table->string('tabla', 64);
            $table->string('bodega', 30);
            $table->string('temporada_origen', 30)->nullable();
            $table->string('estado', 20)->index();
            $table->string('causa', 40)->nullable();
            $table->string('mensaje', 255)->nullable();
            $table->unsignedInteger('filas')->default(0);
            $table->unsignedInteger('pallets')->default(0);
            $table->unsignedInteger('nuevos')->default(0);
            $table->unsignedInteger('modificados')->default(0);
            $table->unsignedInteger('retirados')->default(0);
            $table->foreignId('solicitada_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('iniciada_at');
            $table->timestamp('terminada_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pallets_stock_origen', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('bodega', 30);
            $table->string('numero_pallet', 50);
            $table->string('tipo_bulto', 20);
            $table->boolean('tipo_mezclado')->default(false);
            $table->string('temporada_origen', 30)->nullable();
            $table->string('temporada_origen_nombre', 100)->nullable();
            $table->string('empresa', 50)->nullable();
            $table->string('especie', 100)->nullable();
            $table->string('variedad', 150)->nullable();
            $table->string('calibre', 100)->nullable();
            $table->string('categoria', 100)->nullable();
            $table->string('envase_codigo', 100)->nullable();
            $table->string('envase', 200)->nullable();
            $table->string('marca', 100)->nullable();
            $table->string('origen', 100)->nullable();
            $table->json('mezclados');
            $table->unsignedInteger('cajas');
            $table->decimal('kilos', 12, 2)->default(0);
            $table->date('fecha_embalaje')->nullable();
            $table->timestamp('recibido_origen_at')->nullable();
            $table->json('composicion');
            $table->char('huella', 64);
            $table->boolean('presente')->default(true)->index();
            $table->timestamp('primera_lectura_at');
            $table->timestamp('ultima_lectura_at');
            $table->timestamp('modificado_origen_at');
            $table->timestamp('ausente_desde')->nullable();
            $table->foreignUuid('lectura_stock_origen_id')->nullable()->constrained('lecturas_stock_origen')->nullOnDelete();
            $table->timestamps();
            $table->unique(['bodega', 'numero_pallet'], 'pallet_stock_origen_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pallets_stock_origen');
        Schema::dropIfExists('lecturas_stock_origen');
    }
};

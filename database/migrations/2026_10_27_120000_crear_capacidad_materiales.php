<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_pallet_materiales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('codigo', 40)->unique();
            $t->string('nombre', 150);
            $t->unsignedInteger('largo_mm');
            $t->unsignedInteger('ancho_mm');
            $t->unsignedInteger('altura_total_mm');
            $t->unsignedSmallInteger('max_niveles');
            $t->decimal('peso_maximo_kg', 12, 3)->nullable();
            $t->boolean('activo')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('actualizado_por_user_id')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('capacidades_almacenes_materiales', function (Blueprint $t) {
            $t->foreignUuid('almacen_material_id')->primary()->constrained('destinos_materiales')->restrictOnDelete();
            $t->unsignedInteger('largo_mm');
            $t->unsignedInteger('ancho_mm');
            $t->unsignedInteger('altura_util_mm');
            $t->json('zonas');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('actualizado_por_user_id')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('dimensiones_cargas_materiales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            // Una recepción administrable puede eliminar su saldo; la auditoría
            // independiente conserva las medidas sin bloquear esa operación.
            $t->foreignUuid('saldo_material_almacen_id')->unique('dim_carga_saldo_unique')
                ->constrained('saldos_materiales_almacenes')->cascadeOnDelete();
            $t->foreignUuid('tipo_pallet_id')->constrained('tipos_pallet_materiales')->restrictOnDelete();
            $t->unsignedInteger('largo_mm');
            $t->unsignedInteger('ancho_mm');
            $t->unsignedInteger('altura_total_mm');
            $t->unsignedSmallInteger('max_niveles');
            $t->unsignedInteger('bultos_fisicos');
            $t->decimal('peso_unitario_kg', 12, 3)->nullable();
            $t->unsignedBigInteger('saldo_version');
            $t->decimal('cantidad_referencia', 14, 3);
            $t->string('unidad_medida', 100);
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('actualizado_por_user_id')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('eventos_capacidad_materiales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('operacion_id')->unique();
            $t->char('payload_hash', 64);
            $t->string('entidad', 40);
            $t->uuid('registro_id');
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->json('anterior')->nullable();
            $t->json('resultado');
            $t->timestamp('ocurrido_at');
            $t->index(['entidad', 'registro_id', 'ocurrido_at'], 'capacidad_entidad_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_capacidad_materiales');
        Schema::dropIfExists('dimensiones_cargas_materiales');
        Schema::dropIfExists('capacidades_almacenes_materiales');
        Schema::dropIfExists('tipos_pallet_materiales');
    }
};

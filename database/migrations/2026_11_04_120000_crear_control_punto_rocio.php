<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chequeos diarios de condensación: T° y humedad del aire, punto de rocío y
 * margen frente a la T° superficial de la fruta, por etapa (sellado,
 * paletizado, ingreso y salida de prefrío…) y punto de control.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Salas sin registro propio en el sistema (sellado, paletizado, andén…).
        Schema::create('salas_control_rocio', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nombre', 80)->unique();
            $table->boolean('activa')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('creada_por_user_id')->constrained('users');
            $table->timestamps();
        });

        Schema::create('mediciones_punto_rocio', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('operacion_id')->unique();
            $table->char('payload_hash', 64);
            $table->foreignUuid('temporada_id')->constrained('temporadas');
            $table->string('etapa', 30);
            $table->string('punto_tipo', 10);
            $table->foreignUuid('sala_control_rocio_id')->nullable()->constrained('salas_control_rocio');
            $table->foreignUuid('tunel_prefrio_id')->nullable()->constrained('tuneles_prefrio');
            $table->foreignUuid('camara_id')->nullable()->constrained('camaras');
            $table->string('punto_nombre', 120);
            $table->string('referencia', 80)->nullable();
            $table->decimal('temperatura_ambiente_c', 5, 2);
            $table->decimal('humedad_relativa', 5, 2);
            $table->decimal('temperatura_superficie_c', 5, 2)->nullable();
            $table->decimal('punto_rocio_c', 5, 2);
            $table->decimal('margen_c', 5, 2)->nullable();
            $table->decimal('vapor_g_m3', 6, 2);
            $table->decimal('margen_minimo_c', 4, 2);
            $table->string('estado', 30);
            $table->string('gotas_visibles', 10);
            $table->boolean('requiere_accion')->default(false);
            $table->text('observacion')->nullable();
            $table->timestamp('medido_at');
            $table->date('fecha_operacional');
            $table->foreignId('registrado_por_user_id')->constrained('users');
            $table->foreignUuid('dispositivo_id')->nullable()->constrained('dispositivos');
            $table->text('accion_correctiva')->nullable();
            $table->foreignId('accion_registrada_por_user_id')->nullable()->constrained('users');
            $table->timestamp('accion_registrada_at')->nullable();
            $table->timestamp('anulada_at')->nullable();
            $table->foreignId('anulada_por_user_id')->nullable()->constrained('users');
            $table->text('motivo_anulacion')->nullable();
            $table->timestamps();

            $table->index(['fecha_operacional', 'medido_at'], 'mediciones_rocio_fecha_idx');
            $table->index(['requiere_accion', 'accion_registrada_at'], 'mediciones_rocio_alertas_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mediciones_punto_rocio');
        Schema::dropIfExists('salas_control_rocio');
    }
};

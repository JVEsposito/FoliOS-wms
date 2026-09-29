<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verificaciones_ubicacion', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('temporada_id')->constrained('temporadas')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('dispositivo_id')->constrained('dispositivos')->restrictOnDelete();
            $table->timestamp('turno_inicio_at');
            $table->timestamp('turno_fin_at');
            $table->timestamp('vence_at');
            $table->string('estado', 20)->default('pendiente');
            $table->unsignedInteger('objetivo')->default(5);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['user_id', 'turno_inicio_at'], 'verificacion_usuario_turno_unique');
            $table->index(['temporada_id', 'turno_inicio_at', 'estado'], 'verificacion_temporada_turno_idx');
        });

        Schema::create('verificaciones_ubicacion_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('verificacion_ubicacion_id')->constrained('verificaciones_ubicacion')->restrictOnDelete();
            $table->foreignUuid('posicion_id')->constrained('posiciones')->restrictOnDelete();
            $table->foreignUuid('folio_esperado_id')->nullable()->constrained('folios')->restrictOnDelete();
            $table->uuid('ubicacion_asignada_id')->nullable();
            $table->foreignUuid('folio_encontrado_id')->nullable()->constrained('folios')->restrictOnDelete();
            $table->string('folio_encontrado_numero', 100)->nullable();
            $table->string('resultado', 25)->nullable();
            $table->timestamp('verificada_at')->nullable();
            $table->foreignUuid('dispositivo_id')->nullable()->constrained('dispositivos')->restrictOnDelete();
            $table->uuid('operacion_id')->nullable()->unique();
            $table->char('respuesta_payload_hash', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['verificacion_ubicacion_id', 'posicion_id'], 'verificacion_ronda_posicion_unique');
            $table->index(['posicion_id', 'verificada_at'], 'verificacion_posicion_fecha_idx');
        });

        Schema::create('incidencias_verificacion_ubicacion', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('verificacion_ubicacion_item_id')->unique()->constrained('verificaciones_ubicacion_items', 'id', 'incidencia_verificacion_item_fk')->restrictOnDelete();
            $table->foreignUuid('temporada_id')->constrained('temporadas')->restrictOnDelete();
            $table->foreignUuid('camara_id')->constrained('camaras')->restrictOnDelete();
            $table->foreignUuid('posicion_id')->constrained('posiciones')->restrictOnDelete();
            $table->foreignUuid('folio_esperado_id')->nullable()->constrained('folios')->restrictOnDelete();
            $table->foreignUuid('folio_encontrado_id')->nullable()->constrained('folios')->restrictOnDelete();
            $table->string('folio_encontrado_numero', 100)->nullable();
            $table->foreignUuid('otra_posicion_id')->nullable()->constrained('posiciones')->restrictOnDelete();
            $table->foreignId('reportado_por_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('dispositivo_id')->constrained('dispositivos')->restrictOnDelete();
            $table->string('tipo', 25);
            $table->string('estado', 20)->default('abierta');
            $table->timestamp('reportada_at');
            $table->timestamps();
            $table->index(['temporada_id', 'estado', 'reportada_at'], 'incidencia_verificacion_abierta_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidencias_verificacion_ubicacion');
        Schema::dropIfExists('verificaciones_ubicacion_items');
        Schema::dropIfExists('verificaciones_ubicacion');
    }
};

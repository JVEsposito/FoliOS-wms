<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de los destinos que el servidor rechaza y de las tareas para las que la tablet no
 * encuentra posición. Es telemetría operacional: se borra junto con la tarea o la temporada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidencias_destino_planificador', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('temporada_id')->constrained('temporadas')->cascadeOnDelete();
            $t->foreignUuid('tarea_movimiento_id')->constrained('tareas_movimiento')->cascadeOnDelete();
            $t->foreignUuid('maniobra_operacional_id')->nullable()->constrained('maniobras_operacionales')->nullOnDelete();
            $t->foreignUuid('folio_id')->nullable()->constrained('folios')->nullOnDelete();
            $t->string('origen', 30);
            $t->foreignUuid('camara_id')->nullable()->constrained('camaras')->nullOnDelete();
            $t->foreignUuid('posicion_id')->nullable()->constrained('posiciones')->nullOnDelete();
            $t->string('motivo', 500);
            $t->char('huella_motivo', 64);
            $t->string('planner_version', 80)->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUuid('dispositivo_id')->nullable()->constrained('dispositivos')->nullOnDelete();
            $t->unsignedInteger('repeticiones')->default(1);
            $t->timestamp('primera_at');
            $t->timestamp('ultima_at');
            $t->timestamps();

            $t->index(['temporada_id', 'ultima_at'], 'incidencias_destino_temporada_idx');
            $t->index(['tarea_movimiento_id', 'origen', 'huella_motivo', 'ultima_at'], 'incidencias_destino_tarea_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidencias_destino_planificador');
    }
};

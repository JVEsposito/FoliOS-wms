<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tareas_movimiento', function (Blueprint $table): void {
            // A diferencia de (folio_id, estado), sus claves no cambian al reservar,
            // iniciar o liberar. Evita insertar estados en huecos retenidos por otra tarea.
            $table->index(['folio_id', 'id'], 'tareas_folio_mutex_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tareas_movimiento', function (Blueprint $table): void {
            $table->dropIndex('tareas_folio_mutex_idx');
        });
    }
};

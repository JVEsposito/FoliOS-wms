<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sesiones_estiba', function (Blueprint $table): void {
            $table->foreignUuid('tarea_movimiento_id')->nullable()->constrained('tareas_movimiento')->restrictOnDelete();
            $table->string('clave_tarea_activa', 73)->nullable()->unique();
        });
        Schema::table('reservas_bandas_maniobra', function (Blueprint $table): void {
            $table->foreignUuid('sesion_estiba_id')->nullable()->constrained('sesiones_estiba')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('sesiones_estiba')->whereNotNull('tarea_movimiento_id')->where('estado', 'abierta')->exists()) {
            throw new RuntimeException('Completa las labores y cierra sus sesiones antes de revertir esta migración.');
        }
        Schema::table('reservas_bandas_maniobra', fn (Blueprint $table) => $table->dropConstrainedForeignId('sesion_estiba_id'));
        Schema::table('sesiones_estiba', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tarea_movimiento_id');
            $table->dropColumn('clave_tarea_activa');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modalidad del ciclo de hidrocooler. «oficial» es el registro completo POEMP-R3, como hasta ahora.
 * «patio» es el registro simplificado: tiempos, temperaturas y estado del agua. Los ciclos existentes
 * quedan como oficiales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procesos_hidrocooler_materia_prima', function (Blueprint $table): void {
            $table->string('modalidad', 20)->default('oficial')->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('procesos_hidrocooler_materia_prima', function (Blueprint $table): void {
            $table->dropColumn('modalidad');
        });
    }
};

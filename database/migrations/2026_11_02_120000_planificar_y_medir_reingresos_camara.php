<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reingresos_folio_camara', function (Blueprint $table) {
            // Tarea del planificador que guía al camarero; el destino lo calcula el servidor.
            $table->foreignUuid('plan_operacional_id')->nullable()->after('motivo')->constrained('planes_operacionales')->restrictOnDelete();
            // Registro interno opcional: el sistema lo pide, pero no bloquea la ubicación.
            $table->decimal('temperatura_pulpa', 4, 1)->nullable()->after('plan_operacional_id');
            $table->foreignId('temperatura_por_user_id')->nullable()->after('temperatura_pulpa')->constrained('users')->restrictOnDelete();
            $table->timestamp('temperatura_registrada_at')->nullable()->after('temperatura_por_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('reingresos_folio_camara', function (Blueprint $table) {
            $table->dropConstrainedForeignId('temperatura_por_user_id');
            $table->dropConstrainedForeignId('plan_operacional_id');
            $table->dropColumn(['temperatura_pulpa', 'temperatura_registrada_at']);
        });
    }
};

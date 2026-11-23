<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Topes de Haiku editables desde Administración y lista de usuarios del piloto. Vacíos,
 * rigen los valores del .env y Haiku queda para todos los usuarios del asistente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asistente_configuracion', function (Blueprint $table): void {
            $table->decimal('tope_mensual_usd', 8, 2)->nullable()->after('modo');
            $table->unsignedSmallInteger('limite_diario_usuario')->nullable()->after('tope_mensual_usd');
            $table->json('usuarios_piloto')->nullable()->after('limite_diario_usuario');
        });
    }

    public function down(): void
    {
        Schema::table('asistente_configuracion', function (Blueprint $table): void {
            $table->dropColumn(['tope_mensual_usd', 'limite_diario_usuario', 'usuarios_piloto']);
        });
    }
};

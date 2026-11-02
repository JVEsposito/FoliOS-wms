<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entregas_fruta_proceso', function (Blueprint $table): void {
            $table->string('embalaje_snapshot', 150)->nullable();
            $table->decimal('kilos_por_caja_snapshot', 10, 3)->nullable();
            // Cero identifica entregas anteriores: su embalaje se lee de la versión
            // histórica con la que se registraron, sin reescribir esos datos.
            $table->unsignedInteger('version_embalaje')->default(0);
        });
        Schema::create('correcciones_embalaje_mp', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('operacion_id')->unique();
            $table->char('payload_hash', 64);
            $table->foreignUuid('entrega_fruta_proceso_id')->constrained('entregas_fruta_proceso')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('anterior');
            $table->json('nuevo');
            $table->string('motivo', 500);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['entrega_fruta_proceso_id', 'version'], 'correcciones_embalaje_mp_version_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('correcciones_embalaje_mp')->exists()) {
            throw new RuntimeException('Existen correcciones de embalaje auditadas; no se puede eliminar su historial.');
        }
        Schema::dropIfExists('correcciones_embalaje_mp');
        Schema::table('entregas_fruta_proceso', function (Blueprint $table): void {
            $table->dropColumn(['embalaje_snapshot', 'kilos_por_caja_snapshot', 'version_embalaje']);
        });
    }
};

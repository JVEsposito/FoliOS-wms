<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_asignacion_bodegas_materiales', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
        });
        DB::table('configuracion_asignacion_bodegas_materiales')->insert(['id' => 1]);
        Schema::create('reglas_bodegas_materiales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('nombre', 150);
            $t->foreignUuid('almacen_material_id')->constrained('destinos_materiales')->restrictOnDelete();
            $t->foreignUuid('cliente_id')->nullable()->constrained('clientes')->restrictOnDelete();
            $t->string('categoria_operacional', 30)->nullable();
            $t->unsignedInteger('prioridad');
            $t->boolean('activo');
            $t->unsignedInteger('version');
            $t->foreignId('actualizado_por_user_id')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['activo', 'prioridad']);
        });
        Schema::create('eventos_reglas_bodegas_materiales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('operacion_id')->unique();
            $t->uuid('regla_id')->index();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('usuario');
            $t->char('payload_hash', 64);
            $t->json('antes')->nullable();
            $t->json('despues');
            $t->text('motivo');
            $t->timestamp('ocurrido_at', 6);
        });
        Schema::table('folios_materiales', function (Blueprint $t) {
            // NULL conserva Central para todo el inventario anterior.
            $t->foreignUuid('almacen_inicial_id')->nullable()->constrained('destinos_materiales')->restrictOnDelete();
            $t->json('asignacion_bodega')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('folios_materiales', function (Blueprint $t) {
            $t->dropConstrainedForeignId('almacen_inicial_id');
            $t->dropColumn('asignacion_bodega');
        });
        Schema::dropIfExists('eventos_reglas_bodegas_materiales');
        Schema::dropIfExists('reglas_bodegas_materiales');
        Schema::dropIfExists('configuracion_asignacion_bodegas_materiales');
    }
};

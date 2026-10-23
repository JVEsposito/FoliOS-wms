<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operaciones_integracion', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('tipo', 30);
            $t->foreignUuid('conexion_externa_id')->constrained('conexiones_externas')->restrictOnDelete();
            $t->unsignedInteger('version_conexion');
            $t->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->char('fuente_hash', 64)->nullable()->index();
            $t->char('clave_activa', 64)->nullable()->unique();
            $t->string('estado', 20)->index();
            $t->string('fase', 150);
            $t->longText('entrada')->nullable();
            $t->longText('resultado')->nullable();
            $t->timestamp('resultado_hasta')->nullable();
            $t->timestamp('iniciada_at')->nullable();
            $t->timestamp('terminada_at')->nullable();
            $t->timestamps();
            $t->index(['tipo', 'fuente_hash', 'created_at'], 'operacion_integracion_fuente');
        });
        Schema::table('lecturas_stock_origen', function (Blueprint $t): void {
            $t->char('configuracion_hash', 64)->nullable();
            $t->uuid('operacion_integracion_id')->nullable()->index();
        });
        Schema::table('pallets_stock_origen', fn (Blueprint $t) => $t->string('disponibilidad_origen', 501)->nullable());
    }

    public function down(): void
    {
        Schema::table('pallets_stock_origen', fn (Blueprint $t) => $t->dropColumn('disponibilidad_origen'));
        Schema::table('lecturas_stock_origen', function (Blueprint $t): void {
            $t->dropColumn(['configuracion_hash', 'operacion_integracion_id']);
        });
        Schema::dropIfExists('operaciones_integracion');
    }
};

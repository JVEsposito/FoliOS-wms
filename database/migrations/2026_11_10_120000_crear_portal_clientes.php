<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Exportadoras que puede consultar cada usuario de cliente.
        Schema::create('usuarios_clientes', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->primary(['user_id', 'cliente_id']);
            $table->index('cliente_id');
        });

        // Horario de carga que un cliente pide; despacho lo acepta (crea el embarque) o lo rechaza.
        Schema::create('solicitudes_carga_cliente', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('operacion_id')->unique();
            $table->char('payload_hash', 64);
            $table->foreignUuid('temporada_id')->constrained('temporadas')->restrictOnDelete();
            $table->foreignUuid('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->string('codigo', 30)->unique();
            $table->date('fecha');
            $table->time('hora');
            $table->unsignedSmallInteger('intervalo_minutos');
            $table->string('estado', 20)->index();
            $table->string('modalidad', 20);
            $table->unsignedSmallInteger('cantidad_pallets')->nullable();
            $table->string('referencia', 150)->nullable();
            $table->string('observacion', 1000)->nullable();
            $table->string('motivo_resolucion', 1000)->nullable();
            $table->foreignUuid('embarque_id')->nullable()->constrained('embarques')->restrictOnDelete();
            $table->foreignId('solicitado_por_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('resuelto_por_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resuelto_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['temporada_id', 'fecha', 'estado'], 'solicitudes_carga_calendario');
            $table->index(['cliente_id', 'fecha'], 'solicitudes_carga_cliente_fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_carga_cliente');
        Schema::dropIfExists('usuarios_clientes');
    }
};

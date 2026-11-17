<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reasignación de un movimiento de envases (una guía de ingreso o de salida) entre productores CSG de una
 * misma cuenta corriente, completa o por cantidades. No crea asientos en movimientos_envases: la cuenta del
 * cliente no cambia, solo a qué productor se atribuyen esas cantidades en el estado de cuenta y en el Excel.
 * Cada fila es historial interno; se aplican en orden de registro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reasignaciones_productor_envases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('operacion_id')->unique();
            $table->char('payload_hash', 64);
            $table->foreignUuid('temporada_id')->constrained('temporadas')->restrictOnDelete();
            $table->foreignUuid('cliente_id')->constrained('clientes')->restrictOnDelete();
            // Documento: «recepcion:{id}:recibidos», «recepcion:{id}:recibidos:guia:{id}», «recepcion:{id}:entregados» o «despacho:{id}».
            $table->string('documento_clave', 120);
            $table->string('lado', 12);
            $table->foreignUuid('recepcion_romana_id')->nullable()->constrained('recepciones_romana')->restrictOnDelete();
            $table->uuid('guia_recepcion_romana_id')->nullable();
            $table->foreignUuid('guia_despacho_envase_id')->nullable()->constrained('guias_despacho_envases')->restrictOnDelete();
            $table->string('numero_documento', 80);
            $table->string('titulo', 160);
            // Fecha del documento, para el saldo inicial por productor de un estado de cuenta con período.
            $table->timestamp('fecha_documento')->nullable();
            // Productor que tenía las cantidades (código nulo: sin productor declarado) y el que las recibe.
            $table->json('productor_origen');
            $table->json('productor_destino');
            $table->string('modalidad', 12);
            // Envases reasignados y lo que tenía el productor de origen al reasignar.
            $table->json('envases');
            $table->json('disponibles');
            $table->text('motivo');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('registrado_at');
            $table->timestamps();
            $table->index(['cliente_id', 'temporada_id', 'registrado_at'], 'reasig_prod_envases_cuenta_idx');
            $table->index(['documento_clave'], 'reasig_prod_envases_documento_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reasignaciones_productor_envases');
    }
};

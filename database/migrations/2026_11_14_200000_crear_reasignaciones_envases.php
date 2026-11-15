<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reasignación de una guía de la cuenta corriente de envases a otro productor CSG o a otra cuenta (cliente),
 * sin tocar kilos, Validación MP ni cantidades. Cada fila es una reasignación o su deshacer; la vigente dice
 * dónde están hoy los envases de esa guía. Los asientos que mueven la cuenta quedan en movimientos_envases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reasignaciones_envases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('operacion_id')->unique();
            $table->char('payload_hash', 64);
            $table->foreignUuid('temporada_id')->constrained('temporadas')->restrictOnDelete();
            // Guía reasignada: «recepcion:{id}:recibidos», «recepcion:{id}:recibidos:guia:{id}», «recepcion:{id}:entregados» o «despacho:{id}».
            $table->string('documento_clave', 120)->index();
            $table->string('lado', 12);
            $table->foreignUuid('recepcion_romana_id')->nullable()->constrained('recepciones_romana')->restrictOnDelete();
            // Sin llave foránea: la migración que desglosa las guías de ingreso se puede revertir sola
            // (sus pruebas lo hacen); las guías no se eliminan físicamente.
            $table->uuid('guia_recepcion_romana_id')->nullable()->index();
            $table->foreignUuid('guia_despacho_envase_id')->nullable()->constrained('guias_despacho_envases')->restrictOnDelete();
            $table->string('numero_documento', 80);
            $table->foreignUuid('cliente_origen_id')->constrained('clientes')->restrictOnDelete();
            $table->json('productor_origen')->nullable();
            $table->foreignUuid('cliente_destino_id')->constrained('clientes')->restrictOnDelete();
            $table->json('productor_destino')->nullable();
            $table->boolean('deshace')->default(false);
            $table->boolean('vigente')->default(false);
            $table->json('envases');
            $table->text('motivo');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('registrado_at');
            $table->timestamps();
            $table->index(['vigente', 'recepcion_romana_id'], 'reasig_envases_vigente_recepcion_idx');
            $table->index(['vigente', 'guia_despacho_envase_id'], 'reasig_envases_vigente_despacho_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reasignaciones_envases');
    }
};

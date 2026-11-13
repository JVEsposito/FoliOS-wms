<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Citas de chat y correo: folios, recepciones, cargas y demás operaciones, y menciones
 * de usuarios. La etiqueta queda fija al enviar para que el historial se lea igual aunque
 * el registro cambie después.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('com_referencias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mensaje_id')->nullable()->constrained('com_mensajes')->restrictOnDelete();
            $table->foreignId('correo_id')->nullable()->constrained('com_correos')->restrictOnDelete();
            $table->string('tipo', 30);
            $table->string('referencia_id', 36);
            $table->string('etiqueta', 120);
            $table->timestamp('created_at')->nullable();
            $table->unique(['mensaje_id', 'tipo', 'referencia_id'], 'com_ref_mensaje_unica');
            $table->unique(['correo_id', 'tipo', 'referencia_id'], 'com_ref_correo_unica');
            // Menciones sin leer y «dónde se citó» buscan por el registro citado.
            $table->index(['tipo', 'referencia_id', 'mensaje_id'], 'com_ref_registro_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('com_referencias');
    }
};

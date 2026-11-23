<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recomendaciones a pedido: qué datos se enviaron a Haiku, qué respondió y si se mostró.
 * Sirve para revisarlas; no se borra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistente_recomendaciones', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('llamada_ia_id')->nullable()->constrained('asistente_llamadas_ia')->restrictOnDelete();
            $table->string('consulta', 60);
            $table->json('datos_enviados');
            $table->text('respuesta')->nullable();
            $table->string('estado', 20);
            $table->string('motivo', 300)->nullable();
            $table->timestamp('created_at');
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistente_recomendaciones');
    }
};

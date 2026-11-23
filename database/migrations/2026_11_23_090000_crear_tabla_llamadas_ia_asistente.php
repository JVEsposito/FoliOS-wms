<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Registro de cada llamada del asistente a Haiku: tokens, costo y resultado. No se borra. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistente_llamadas_ia', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('modelo', 60);
            $table->string('estado', 20);
            $table->string('herramienta', 40)->nullable();
            $table->string('consulta', 60)->nullable();
            $table->unsignedInteger('tokens_entrada')->default(0);
            $table->unsignedInteger('tokens_salida')->default(0);
            $table->unsignedInteger('tokens_cache_lectura')->default(0);
            $table->unsignedInteger('tokens_cache_escritura')->default(0);
            $table->decimal('costo_usd', 12, 6)->default(0);
            $table->unsignedInteger('duracion_ms')->default(0);
            $table->string('detalle', 500)->nullable();
            $table->timestamp('created_at');
            $table->index(['created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistente_llamadas_ia');
    }
};

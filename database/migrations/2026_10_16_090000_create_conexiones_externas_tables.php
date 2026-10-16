<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conexiones_externas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nombre', 100)->unique();
            $table->text('credenciales');
            $table->boolean('activa')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->string('ultima_prueba_estado', 20)->nullable();
            $table->timestamp('ultima_prueba_at')->nullable();
            $table->foreignId('creado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('eventos_conexion_externa', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('conexion_externa_id')->constrained('conexiones_externas');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accion', 30);
            $table->json('campos_modificados');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_conexion_externa');
        Schema::dropIfExists('conexiones_externas');
    }
};

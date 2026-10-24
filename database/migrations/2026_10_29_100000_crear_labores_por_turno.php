<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turnos_planificador', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('temporada_id')->constrained('temporadas')->restrictOnDelete();
            $t->string('nombre', 80);
            $t->dateTime('inicio_at')->index();
            $t->dateTime('fin_at')->index();
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('creado_por_user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('actualizado_por_user_id')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('funciones_camarero_turno', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('turno_id')->constrained('turnos_planificador')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('funcion_principal', 50);
            $t->json('funciones_apoyo');
            $t->json('preferencia')->nullable();
            $t->boolean('disponible')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['turno_id', 'user_id']);
        });
        Schema::create('labores_camarero', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('turno_id')->constrained('turnos_planificador')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('dispositivo_id')->constrained('dispositivos')->restrictOnDelete();
            $t->string('clave_labor', 160);
            $t->string('bloqueo_labor', 160)->nullable()->unique();
            $t->string('bloqueo_camarero', 30)->nullable()->unique();
            $t->string('funcion', 50);
            $t->string('titulo', 190);
            $t->string('estado', 40)->index();
            $t->string('motivo', 500)->nullable();
            $t->json('contexto');
            $t->dateTime('vence_at')->index();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['user_id', 'estado']);
        });
        // Anclas existentes: evitan bloquear huecos de los índices únicos al crear la primera labor.
        Schema::create('cerrojos_labores_turno', function (Blueprint $t): void {
            $t->string('clave', 220)->primary();
            $t->foreignUuid('labor_id')->nullable()->constrained('labores_camarero')->restrictOnDelete();
        });
        Schema::create('eventos_labores_turno', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->foreignUuid('turno_id')->constrained('turnos_planificador')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('tipo', 50);
            $t->json('datos');
            $t->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_labores_turno');
        Schema::dropIfExists('cerrojos_labores_turno');
        Schema::dropIfExists('labores_camarero');
        Schema::dropIfExists('funciones_camarero_turno');
        Schema::dropIfExists('turnos_planificador');
    }
};

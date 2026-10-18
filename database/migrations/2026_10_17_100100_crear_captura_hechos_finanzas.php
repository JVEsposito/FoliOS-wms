<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Captura de hechos valorizables. Todo vive en la base de Finanzas: las referencias a la
 * operación (origen, cliente, temporada) son identificadores sin llave foránea, con una
 * copia de lo leído para que Finanzas no dependa de que el registro siga igual.
 */
return new class extends Migration
{
    protected $connection = 'finanzas';

    public function up(): void
    {
        Schema::create('fin_lecturas', function (Blueprint $table): void {
            $table->id();
            $table->string('origen', 20);
            $table->boolean('conciliacion')->default(false);
            $table->string('estado', 20);
            $table->unsignedBigInteger('solicitada_por_user_id')->nullable();
            $table->string('solicitada_por_nombre', 150)->nullable();
            $table->dateTime('iniciada_at');
            $table->dateTime('terminada_at')->nullable();
            $table->unsignedInteger('duracion_ms')->nullable();
            $table->json('resumen')->nullable();
            $table->text('error')->nullable();
            $table->index('iniciada_at');
        });

        Schema::create('fin_cursores', function (Blueprint $table): void {
            $table->string('fuente', 40)->primary();
            $table->dateTime('marca_at')->nullable();
            $table->foreignId('lectura_id')->nullable()->constrained('fin_lecturas')->nullOnDelete();
            $table->dateTime('actualizado_at');
        });

        Schema::create('fin_hechos', function (Blueprint $table): void {
            $table->id();
            $table->string('fuente', 40);
            $table->string('origen_id', 100);
            $table->unsignedInteger('version');
            $table->boolean('vigente');
            $table->string('estado', 20);
            $table->string('motivo_version', 20);
            $table->char('cliente_id', 36)->nullable();
            $table->string('cliente_nombre', 180)->nullable();
            $table->char('temporada_id', 36)->nullable();
            $table->string('temporada_codigo', 30)->nullable();
            $table->string('referencia', 100)->nullable();
            $table->dateTime('ocurrido_at');
            $table->dateTime('termina_at')->nullable();
            $table->date('fecha_operacional');
            $table->json('medidas');
            $table->json('datos');
            $table->char('huella', 64);
            $table->foreignId('lectura_id')->nullable()->constrained('fin_lecturas')->nullOnDelete();
            $table->dateTime('capturado_at');

            $table->unique(['fuente', 'origen_id', 'version']);
            $table->index(['fuente', 'origen_id', 'vigente']);
            $table->index(['vigente', 'fecha_operacional']);
            $table->index(['vigente', 'cliente_id', 'fecha_operacional']);
            $table->index(['fuente', 'vigente', 'ocurrido_at']);
            $table->index('referencia');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_hechos');
        Schema::dropIfExists('fin_cursores');
        Schema::dropIfExists('fin_lecturas');
    }
};

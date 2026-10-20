<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'finanzas';

    public function up(): void
    {
        // Los siete conceptos originales conservan sus claves; solo las altas de Oficina
        // necesitan filas. No se modifican precios, hechos ni cierres existentes.
        Schema::connection($this->connection)->create('fin_servicios', function (Blueprint $table): void {
            $table->string('clave', 40)->primary();
            $table->string('nombre', 120);
            $table->string('fuente', 40);
            $table->string('descripcion', 500)->nullable();
            $table->string('aplicacion', 20);
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->unsignedSmallInteger('dia_desde')->default(1);
            $table->unsignedSmallInteger('dia_hasta')->nullable();
            $table->timestamps();
            $table->index(['fuente', 'vigente_desde']);
        });
        Schema::connection($this->connection)->create('fin_servicios_eventos', function (Blueprint $table): void {
            $table->id();
            $table->string('servicio', 40);
            $table->foreign('servicio')->references('clave')->on('fin_servicios');
            $table->string('accion', 20);
            $table->json('datos');
            $table->string('motivo', 500)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_nombre')->nullable();
            $table->dateTime('created_at');
            $table->index(['servicio', 'id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('fin_servicios_eventos');
        Schema::connection($this->connection)->dropIfExists('fin_servicios');
    }
};

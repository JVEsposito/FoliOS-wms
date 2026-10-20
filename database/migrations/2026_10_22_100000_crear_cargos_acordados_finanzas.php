<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'finanzas';

    public function up(): void
    {
        Schema::connection($this->connection)->table('fin_servicios', function (Blueprint $table): void {
            $table->string('unidad_libre', 60)->nullable();
            $table->string('proceso_referencia', 120)->nullable();
        });
        Schema::connection($this->connection)->create('fin_cargos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('operacion_id')->unique();
            $table->string('payload_hash', 64);
            $table->string('servicio', 40);
            $table->char('cliente_id', 36);
            $table->string('cliente_nombre', 180);
            $table->date('fecha');
            $table->decimal('cantidad', 24, 6);
            $table->string('base_cobro', 30);
            $table->string('estado_ejecucion', 30);
            $table->string('respaldo', 500);
            $table->json('contexto')->nullable();
            $table->dateTime('aprobado_at')->nullable();
            $table->dateTime('anulado_at')->nullable();
            $table->string('motivo_anulacion', 500)->nullable();
            $table->timestamps();
            $table->foreign('servicio')->references('clave')->on('fin_servicios');
            $table->index(['fecha', 'cliente_id']);
        });
        Schema::connection($this->connection)->create('fin_cargos_eventos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('cargo_id');
            $table->foreign('cargo_id')->references('id')->on('fin_cargos');
            $table->string('accion', 20);
            $table->json('datos');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_nombre')->nullable();
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('fin_cargos_eventos');
        Schema::connection($this->connection)->dropIfExists('fin_cargos');
        Schema::connection($this->connection)->table('fin_servicios', function (Blueprint $table): void {
            $table->dropColumn(['unidad_libre', 'proceso_referencia']);
        });
    }
};

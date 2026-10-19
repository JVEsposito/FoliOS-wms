<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tarifario de Finanzas: monedas y precios por servicio y unidad de cobro, para un cliente o
 * como tarifa general, con vigencia e historial. Vive en la base de Finanzas; el cliente se
 * guarda como referencia a la operación, sin llave foránea.
 *
 * No se precargan monedas: los datos de Finanzas que el registro de migraciones no conoce
 * detienen la migración (EsquemaFinanzas::retirarHuerfanas). La oficina las agrega.
 */
return new class extends Migration
{
    protected $connection = 'finanzas';

    public function up(): void
    {
        Schema::connection($this->connection)->create('fin_monedas', function (Blueprint $table): void {
            $table->string('codigo', 10)->primary();
            $table->string('nombre', 60);
            $table->string('simbolo', 10);
            // Decimales con que se redondean los montos; el precio unitario admite hasta 6.
            $table->unsignedTinyInteger('decimales');
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('fin_tarifas', function (Blueprint $table): void {
            $table->id();
            // Nulo: tarifa general, para los clientes sin precio propio en ese servicio.
            $table->char('cliente_id', 36)->nullable();
            $table->string('cliente_nombre', 180)->nullable();
            $table->string('servicio', 40);
            $table->string('unidad', 40);
            $table->string('moneda', 10);
            $table->decimal('precio', 18, 6);
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->string('nota', 500)->nullable();
            $table->dateTime('anulada_at')->nullable();
            $table->string('motivo_anulacion', 500)->nullable();
            $table->unsignedBigInteger('actualizada_por_user_id')->nullable();
            $table->string('actualizada_por_nombre')->nullable();
            $table->timestamps();

            $table->foreign('moneda')->references('codigo')->on('fin_monedas');
            $table->index(['servicio', 'cliente_id', 'vigente_desde'], 'fin_tarifas_servicio_cliente');
            $table->index(['cliente_id', 'vigente_desde'], 'fin_tarifas_cliente');
        });

        Schema::connection($this->connection)->create('fin_tarifas_eventos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tarifa_id')->constrained('fin_tarifas');
            $table->string('accion', 20);
            $table->json('cambios');
            $table->string('motivo', 500)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_nombre')->nullable();
            $table->dateTime('created_at');

            $table->index(['tarifa_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('fin_tarifas_eventos');
        Schema::connection($this->connection)->dropIfExists('fin_tarifas');
        Schema::connection($this->connection)->dropIfExists('fin_monedas');
    }
};

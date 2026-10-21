<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'comex';

    public function up(): void
    {
        Schema::connection('comex')->create('cx_bookings', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('codigo', 80);
            $t->foreignUuid('naviera_id')->constrained('cx_partes_comerciales');
            $t->uuid('cliente_operacion_id');
            $t->json('cliente');
            $t->string('gestion', 20);
            $t->string('estado', 20)->default('borrador');
            $t->json('datos');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['naviera_id', 'codigo']);
        });
        Schema::connection('comex')->create('cx_booking_contenedores', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('booking_id')->constrained('cx_bookings');
            $t->string('referencia', 80);
            $t->string('numero', 11)->nullable();
            $t->string('tipo', 20);
            $t->string('sello', 80)->nullable();
            $t->boolean('activo')->default(true);
            $t->timestamps();
            $t->unique(['booking_id', 'referencia']);
        });
        Schema::connection('comex')->create('cx_booking_asignaciones', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('booking_id')->constrained('cx_bookings');
            $t->foreignUuid('contenedor_id')->constrained('cx_booking_contenedores');
            $t->foreignUuid('nota_id')->constrained('cx_notas_venta');
            $t->foreignUuid('revision_id')->constrained('cx_nota_revisiones');
            $t->uuid('linea_id');
            $t->decimal('cantidad', 18, 6);
            $t->json('acuerdo');
            $t->boolean('activa')->default(true);
            $t->timestamps();
            $t->index(['nota_id', 'linea_id', 'activa']);
        });
        Schema::connection('comex')->create('cx_booking_despachos', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('booking_id')->constrained('cx_bookings');
            $t->foreignUuid('contenedor_id')->constrained('cx_booking_contenedores');
            $t->uuid('embarque_id');
            $t->uuid('embarque_vigente')->nullable()->unique();
            $t->uuid('contenedor_vigente')->nullable()->unique();
            $t->json('evidencia');
            $t->string('source_version', 64);
            $t->dateTime('captured_at');
            $t->boolean('activo')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('comex')->dropIfExists('cx_booking_despachos');
        Schema::connection('comex')->dropIfExists('cx_booking_asignaciones');
        Schema::connection('comex')->dropIfExists('cx_booking_contenedores');
        Schema::connection('comex')->dropIfExists('cx_bookings');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'finanzas';

    public function up(): void
    {
        Schema::connection('finanzas')->create('fin_costos_comex', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('booking_id')->index();
            $t->uuid('contenedor_id')->nullable();
            $t->string('concepto', 200);
            $t->string('tratamiento', 30);
            $t->date('fecha');
            $t->char('moneda', 3);
            $t->decimal('previsto', 18, 6);
            $t->decimal('comprometido', 18, 6)->nullable();
            $t->json('conversion');
            $t->json('fuente');
            $t->string('source_version', 64);
            $t->dateTime('captured_at');
            $t->string('respaldo', 1000);
            $t->string('estado', 20)->default('abierto');
            $t->json('cierre')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::connection('finanzas')->create('fin_imputaciones_comex', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('costo_id')->constrained('fin_costos_comex');
            $t->foreignUuid('documento_id')->constrained('fin_documentos_compra');
            $t->unsignedSmallInteger('linea');
            $t->decimal('monto', 15, 0);
            $t->smallInteger('signo');
            $t->json('documento');
            $t->string('source_version', 64);
            $t->boolean('activa')->default(true);
            $t->timestamps();
            $t->index(['documento_id', 'linea', 'activa']);
        });
        Schema::connection('finanzas')->create('fin_operaciones_comex', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('accion', 50);
            $t->string('payload_hash', 64);
            $t->json('resultado');
            $t->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection('finanzas')->dropIfExists('fin_operaciones_comex');
        Schema::connection('finanzas')->dropIfExists('fin_imputaciones_comex');
        Schema::connection('finanzas')->dropIfExists('fin_costos_comex');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'finanzas';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->create('fin_pagos_proveedor', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('operacion_id')->unique();
            $t->char('payload_hash', 64);
            $t->uuid('proveedor_id');
            $t->foreign('proveedor_id')->references('id')->on('fin_proveedores');
            $t->json('proveedor_snapshot');
            $t->date('fecha');
            $t->decimal('monto', 18, 0);
            $t->string('cuenta', 30);
            $t->foreign('cuenta')->references('codigo')->on('fin_cuentas_compra');
            $t->string('referencia', 180);
            $t->char('referencia_activa', 64)->nullable()->unique();
            $t->string('respaldo', 1000);
            $t->dateTime('anulado_at')->nullable();
            $t->date('fecha_anulacion')->nullable();
            $t->string('motivo_anulacion', 500)->nullable();
            $t->timestamps();
        });
        $schema->create('fin_aplicaciones_proveedor', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('operacion_id')->unique();
            $t->char('payload_hash', 64);
            $t->uuid('proveedor_id');
            $t->foreign('proveedor_id')->references('id')->on('fin_proveedores');
            $t->string('credito_tipo', 20);
            $t->uuid('credito_id');
            $t->uuid('documento_id');
            $t->foreign('documento_id')->references('id')->on('fin_documentos_compra');
            $t->date('fecha');
            $t->decimal('monto', 18, 0);
            $t->string('respaldo', 500);
            $t->dateTime('anulado_at')->nullable();
            $t->date('fecha_anulacion')->nullable();
            $t->string('motivo_anulacion', 500)->nullable();
            $t->timestamps();
            $t->index(['credito_tipo', 'credito_id']);
            $t->index(['documento_id', 'fecha']);
        });
        $schema->create('fin_evaluaciones_proveedor', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('operacion_id')->unique();
            $t->char('payload_hash', 64);
            $t->uuid('proveedor_id');
            $t->foreign('proveedor_id')->references('id')->on('fin_proveedores');
            $t->date('desde');
            $t->date('hasta');
            $t->json('criterios');
            $t->decimal('resultado', 3, 2);
            $t->string('conclusion', 20);
            $t->string('respaldo', 1000);
            $t->unsignedBigInteger('user_id');
            $t->string('user_nombre', 180);
            $t->dateTime('anulado_at')->nullable();
            $t->string('motivo_anulacion', 500)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['fin_evaluaciones_proveedor', 'fin_aplicaciones_proveedor', 'fin_pagos_proveedor'] as $tabla) {
            Schema::connection($this->connection)->dropIfExists($tabla);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        $s->table('rh_marcajes', function (Blueprint $t): void {
            $t->string('id_externo', 256)->collation('utf8mb4_bin')->change();
        });
        $s->create('rh_lotes_integracion', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('fuente_id');
            $t->string('estado', 30);
            $t->unsignedInteger('intentos')->default(0);
            $t->json('consulta');
            $t->longText('contenido');
            $t->json('resultado')->nullable();
            $t->timestamps();
            $t->foreign('fuente_id')->references('id')->on('rh_fuentes');
            $t->index(['fuente_id', 'created_at']);
        });
        $s->create('rh_entregas_nomina', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('movimiento_id');
            $t->unsignedInteger('secuencia');
            $t->json('contenido');
            $t->string('huella', 64);
            $t->dateTime('acusado_at')->nullable();
            $t->string('recibo_externo', 200)->nullable();
            $t->timestamps();
            $t->foreign('movimiento_id')->references('id')->on('rh_movimientos_nomina');
            $t->unique(['movimiento_id', 'secuencia']);
            $t->index('acusado_at');
        });
    }

    public function down(): void
    {
        // No se reduce id_externo: podría truncar identificadores válidos de un proveedor.
        $s = Schema::connection($this->connection);
        $s->dropIfExists('rh_entregas_nomina');
        $s->dropIfExists('rh_lotes_integracion');
    }
};

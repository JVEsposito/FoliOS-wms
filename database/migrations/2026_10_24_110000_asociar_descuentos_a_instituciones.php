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
        $s->table('rh_conceptos_nomina', function (Blueprint $t): void {
            $t->foreignUuid('institucion_id')->nullable()->constrained('rh_instituciones');
        });
        $s->table('rh_parametros_legales', fn (Blueprint $t) => $t->unsignedInteger('imm_no_remuneracional')->nullable());
        $s->create('rh_mandatos_descuento', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('persona_id')->constrained('rh_personas');
            $t->foreignUuid('empleador_id')->constrained('rh_empleadores');
            $t->foreignUuid('concepto_id')->constrained('rh_conceptos_nomina');
            $t->foreignUuid('institucion_id')->constrained('rh_instituciones');
            $t->string('referencia', 100);
            $t->date('desde');
            $t->date('hasta');
            $t->decimal('cuota', 18, 6);
            $t->decimal('deuda_total', 18, 0)->nullable();
            $t->unsignedInteger('cuotas')->nullable();
            $t->string('base_porcentaje', 30)->nullable();
            $t->boolean('pago_directo_institucion')->default(false);
            $t->string('excepcion_ccaf', 30)->nullable();
            $t->json('concepto');
            $t->json('institucion');
            $t->string('respaldo', 1000);
            $t->timestamps();
            $t->unique(['persona_id', 'institucion_id', 'referencia'], 'rh_mandato_referencia');
        });
        $s->table('rh_movimientos_nomina', function (Blueprint $t): void {
            $t->foreignUuid('empleador_id')->nullable()->constrained('rh_empleadores');
            $t->foreignUuid('contrato_id')->nullable()->constrained('rh_contratos');
            $t->foreignUuid('institucion_id')->nullable()->constrained('rh_instituciones');
            $t->foreignUuid('mandato_id')->nullable()->constrained('rh_mandatos_descuento');
            $t->index(['institucion_id', 'fecha', 'estado'], 'rh_movimientos_institucion');
        });
    }

    public function down(): void
    {
        $s = Schema::connection($this->connection);
        $s->table('rh_movimientos_nomina', function (Blueprint $t): void {
            $t->dropIndex('rh_movimientos_institucion');
            $t->dropConstrainedForeignId('mandato_id');
            $t->dropConstrainedForeignId('institucion_id');
            $t->dropConstrainedForeignId('contrato_id');
            $t->dropConstrainedForeignId('empleador_id');
        });
        $s->dropIfExists('rh_mandatos_descuento');
        $s->table('rh_parametros_legales', fn (Blueprint $t) => $t->dropColumn('imm_no_remuneracional'));
        $s->table('rh_conceptos_nomina', fn (Blueprint $t) => $t->dropConstrainedForeignId('institucion_id'));
    }
};

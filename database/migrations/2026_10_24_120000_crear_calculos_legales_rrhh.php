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
        foreach (['rh_afiliaciones', 'rh_mandatos_descuento'] as $tabla) {
            $s->table($tabla, fn (Blueprint $t) => $t->string('regimen_apv', 1)->nullable());
        }
        $s->create('rh_indices_nomina', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('mes', 7);
            $t->unsignedInteger('version');
            $t->decimal('uf', 18, 6);
            $t->decimal('utm', 18, 6);
            $t->date('fecha_uf');
            $t->json('comisiones');
            $t->string('respaldo', 1000);
            $t->timestamps();
            $t->unique(['mes', 'version']);
        });
        $s->create('rh_calculos_legales', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('operacion_id')->unique();
            $t->string('payload_hash', 64);
            $t->string('tipo', 40);
            $t->string('mes', 7);
            $t->foreignUuid('persona_id')->nullable()->constrained('rh_personas');
            $t->foreignUuid('contrato_id')->nullable()->constrained('rh_contratos');
            $t->foreignUuid('parametro_id')->nullable()->constrained('rh_indices_nomina');
            $t->json('entrada');
            $t->json('calculo');
            $t->timestamps();
            $t->index(['mes', 'tipo']);
        });
    }

    public function down(): void
    {
        $s = Schema::connection($this->connection);
        $s->dropIfExists('rh_calculos_legales');
        $s->dropIfExists('rh_indices_nomina');
        foreach (['rh_afiliaciones', 'rh_mandatos_descuento'] as $tabla) {
            $s->table($tabla, fn (Blueprint $t) => $t->dropColumn('regimen_apv'));
        }
    }
};

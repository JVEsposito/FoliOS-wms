<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        $s->create('rh_cargos', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('codigo', 40)->unique();
            $t->string('nombre', 120);
            $t->string('descripcion', 500)->nullable();
            $t->boolean('activo')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        $s->table('rh_personas', function (Blueprint $t): void {
            $t->uuid('cargo_id')->nullable();
            $t->foreign('cargo_id')->references('id')->on('rh_cargos');
        });
        $s->create('rh_conceptos_nomina', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('codigo', 40)->unique();
            $t->string('nombre', 120);
            $t->string('tipo', 30);
            $t->string('calculo', 30);
            $t->boolean('remuneracion');
            $t->boolean('imponible');
            $t->boolean('tributable');
            $t->boolean('base_he');
            $t->string('grupo_descuento', 30)->nullable();
            $t->string('codigo_lre', 40)->nullable();
            $t->string('codigo_externo', 100)->nullable();
            $t->string('fundamento', 1000);
            $t->boolean('activo')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        $s->create('rh_parametros_legales', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->date('desde');
            $t->date('hasta');
            $t->unsignedInteger('imm_general');
            $t->unsignedInteger('imm_menor_mayor');
            $t->string('respaldo', 1000);
            $t->timestamps();
            $t->index(['desde', 'hasta']);
        });
        foreach ([['2026-01-01', '2026-04-30', 539000, 402082, 'Ley 21.751 · https://www.bcn.cl/leychile/navegar?idNorma=1214530'],
            ['2026-05-01', '2026-12-31', 553553, 412938, 'Ley 21.830 · https://www.bcn.cl/leychile/navegar?idNorma=1225354']] as [$desde,$hasta,$general,$especial,$respaldo]) {
            DB::connection($this->connection)->table('rh_parametros_legales')->insert(['id' => (string) Str::uuid(), 'desde' => $desde, 'hasta' => $hasta, 'imm_general' => $general, 'imm_menor_mayor' => $especial, 'respaldo' => $respaldo, 'created_at' => now(), 'updated_at' => now()]);
        }
        $s->create('rh_condiciones_laborales', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('persona_id');
            $t->uuid('cargo_id');
            $t->string('cargo_nombre', 120);
            $t->date('desde');
            $t->date('hasta');
            $t->string('regimen', 30);
            $t->decimal('horas_semanales', 5, 2);
            $t->decimal('sueldo_mensual', 18, 6);
            $t->string('tramo_imm', 30);
            $t->string('respaldo', 1000);
            $t->timestamps();
            $t->foreign('persona_id')->references('id')->on('rh_personas');
            $t->foreign('cargo_id')->references('id')->on('rh_cargos');
            $t->index(['persona_id', 'desde']);
        });
        $s->create('rh_pactos_conceptos', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('persona_id');
            $t->uuid('concepto_id');
            $t->json('concepto');
            $t->date('desde');
            $t->date('hasta');
            $t->decimal('cantidad', 18, 6);
            $t->decimal('valor', 18, 6);
            $t->string('respaldo', 1000);
            $t->timestamps();
            $t->foreign('persona_id')->references('id')->on('rh_personas');
            $t->foreign('concepto_id')->references('id')->on('rh_conceptos_nomina');
            $t->index(['persona_id', 'desde']);
        });
        $s->create('rh_movimientos_nomina', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('operacion_id')->unique();
            $t->string('payload_hash', 64);
            $t->uuid('persona_id');
            $t->uuid('concepto_id');
            $t->uuid('pacto_id')->nullable();
            $t->date('fecha');
            $t->string('estado', 20)->default('borrador');
            $t->json('entrada');
            $t->json('calculo');
            $t->decimal('total', 18, 0);
            $t->dateTime('aprobado_at')->nullable();
            $t->dateTime('anulado_at')->nullable();
            $t->timestamps();
            $t->foreign('persona_id')->references('id')->on('rh_personas');
            $t->foreign('concepto_id')->references('id')->on('rh_conceptos_nomina');
            $t->foreign('pacto_id')->references('id')->on('rh_pactos_conceptos');
            $t->index(['persona_id', 'fecha', 'estado']);
        });
    }

    public function down(): void
    {
        $s = Schema::connection($this->connection);
        foreach (['rh_movimientos_nomina', 'rh_pactos_conceptos', 'rh_condiciones_laborales', 'rh_parametros_legales', 'rh_conceptos_nomina'] as $tabla) {
            $s->dropIfExists($tabla);
        }
        $s->table('rh_personas', function (Blueprint $t): void {
            $t->dropForeign(['cargo_id']);
            $t->dropColumn('cargo_id');
        });
        $s->dropIfExists('rh_cargos');
    }
};

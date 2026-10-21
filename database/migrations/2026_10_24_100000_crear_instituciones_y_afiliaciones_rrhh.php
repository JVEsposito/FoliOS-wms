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
        foreach (['rh_instituciones', 'rh_empleadores'] as $tabla) {
            $s->create($tabla, function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->string('codigo', 40)->unique();
                $t->string('rut', 12)->unique();
                $t->string('razon_social', 160);
                $t->string('tipo', 30);
                $t->string('codigo_oficial', 40)->nullable();
                $t->string('codigo_externo', 100)->nullable();
                $t->date('desde');
                $t->date('hasta');
                $t->boolean('activo')->default(true);
                $t->date('verificado_en');
                $t->string('respaldo', 1000);
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
            });
        }
        $s->create('rh_contratos', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('persona_id');
            $t->uuid('empleador_id');
            $t->string('referencia', 100);
            $t->string('tipo', 30);
            $t->date('desde');
            $t->date('hasta');
            $t->uuid('prorroga_de')->nullable();
            $t->json('empleador');
            $t->string('respaldo', 1000);
            $t->timestamps();
            $t->foreign('persona_id')->references('id')->on('rh_personas');
            $t->foreign('empleador_id')->references('id')->on('rh_empleadores');
            $t->foreign('prorroga_de')->references('id')->on('rh_contratos');
            $t->unique(['empleador_id', 'referencia']);
            $t->index(['persona_id', 'empleador_id', 'desde', 'hasta'], 'rh_contratos_vigencia');
        });
        $s->create('rh_afiliaciones', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('persona_id')->nullable();
            $t->uuid('empleador_id')->nullable();
            $t->uuid('institucion_id');
            $t->string('regimen', 30);
            $t->date('desde');
            $t->date('hasta');
            $t->string('referencia', 100);
            $t->string('moneda_plan', 5)->nullable();
            $t->decimal('precio_plan', 18, 6)->nullable();
            $t->json('institucion');
            $t->string('respaldo', 1000);
            $t->timestamps();
            $t->foreign('persona_id')->references('id')->on('rh_personas');
            $t->foreign('empleador_id')->references('id')->on('rh_empleadores');
            $t->foreign('institucion_id')->references('id')->on('rh_instituciones');
            $t->index(['persona_id', 'regimen', 'desde', 'hasta'], 'rh_afiliacion_persona');
            $t->index(['empleador_id', 'regimen', 'desde', 'hasta'], 'rh_afiliacion_empleador');
        });
    }

    public function down(): void
    {
        $s = Schema::connection($this->connection);
        foreach (['rh_afiliaciones', 'rh_contratos', 'rh_empleadores', 'rh_instituciones'] as $tabla) {
            $s->dropIfExists($tabla);
        }
    }
};

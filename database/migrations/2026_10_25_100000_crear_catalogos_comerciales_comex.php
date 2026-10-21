<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'comex';

    public function up(): void
    {
        Schema::connection('comex')->create('cx_partes_comerciales', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('codigo', 40)->unique();
            $t->string('pais_iso2', 2);
            $t->string('tax_id', 80);
            $t->string('identidad_fiscal', 80);
            $t->string('razon_social', 200);
            $t->string('direccion', 300);
            $t->string('ciudad', 120);
            $t->json('roles');
            $t->string('contacto', 200)->nullable();
            $t->uuid('cliente_operacion_id')->nullable()->unique();
            $t->json('cliente_operacion')->nullable();
            $t->boolean('activo')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->string('respaldo', 1000);
            $t->timestamps();
            $t->unique(['pais_iso2', 'identidad_fiscal'], 'cx_parte_identidad');
        });
        Schema::connection('comex')->create('cx_lugares_comerciales', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('codigo', 40)->unique();
            $t->string('tipo', 20);
            $t->string('nombre', 200);
            $t->string('pais_iso2', 2);
            $t->string('localidad', 120);
            $t->string('direccion', 300)->nullable();
            $t->string('codigo_externo', 50)->nullable();
            $t->foreignUuid('puerto_id')->nullable()->constrained('cx_lugares_comerciales');
            $t->boolean('activo')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->string('respaldo', 1000);
            $t->timestamps();
        });
        Schema::connection('comex')->create('cx_eventos_comerciales', function (Blueprint $t): void {
            $t->id();
            $t->string('entidad', 30);
            $t->uuid('entidad_id');
            $t->string('accion', 40);
            $t->json('datos');
            $t->unsignedBigInteger('user_id');
            $t->string('user_nombre', 255);
            $t->dateTime('created_at');
            $t->index(['entidad', 'entidad_id']);
        });
        Schema::connection('comex')->create('cx_operaciones_comerciales', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('accion', 50);
            $t->string('payload_hash', 64);
            $t->json('resultado');
            $t->dateTime('created_at');
        });
    }

    public function down(): void
    {
        foreach (['cx_operaciones_comerciales', 'cx_eventos_comerciales', 'cx_lugares_comerciales', 'cx_partes_comerciales'] as $tabla) {
            Schema::connection('comex')->dropIfExists($tabla);
        }
    }
};

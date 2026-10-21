<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection($this->connection)->create('rh_personas', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('codigo', 40)->unique();
            $t->string('nombre', 180);
            $t->string('documento', 40)->nullable();
            $t->string('empleador', 180)->nullable();
            $t->string('cargo', 120)->nullable();
            $t->string('area', 120)->nullable();
            $t->string('ceco', 60)->nullable();
            $t->boolean('activa')->default(true);
            $t->string('fuente_marcaje', 60)->default('folios');
            $t->string('pin_hash')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::connection($this->connection)->create('rh_fuentes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('codigo', 60)->unique();
            $t->string('nombre', 120);
            $t->string('tipo', 30);
            $t->boolean('activa')->default(true);
            $t->text('configuracion')->nullable();
            $t->timestamps();
        });
        Schema::connection($this->connection)->create('rh_vinculos', function (Blueprint $t): void {
            $t->id();
            $t->uuid('fuente_id');
            $t->uuid('persona_id');
            $t->string('identificador_externo', 100)->collation('utf8mb4_bin');
            $t->timestamps();
            $t->foreign('fuente_id')->references('id')->on('rh_fuentes');
            $t->foreign('persona_id')->references('id')->on('rh_personas');
            $t->unique(['fuente_id', 'identificador_externo']);
            $t->unique(['fuente_id', 'persona_id']);
        });
        Schema::connection($this->connection)->create('rh_asignaciones', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('operacion_id')->unique();
            $t->string('payload_hash', 64);
            $t->uuid('persona_id');
            $t->char('cliente_id', 36)->nullable();
            $t->string('cliente_nombre', 180)->nullable();
            $t->string('persona_nombre', 180);
            $t->string('cargo', 120);
            $t->string('area', 120);
            $t->string('ceco', 60)->nullable();
            $t->string('fuente_marcaje', 60);
            $t->dateTime('desde_at');
            $t->dateTime('hasta_at');
            $t->unsignedSmallInteger('descanso_programado_minutos')->default(0);
            $t->decimal('costo_hora', 18, 6)->nullable();
            $t->char('moneda_costo', 3)->nullable();
            $t->string('estado', 20)->default('programada');
            $t->string('observacion', 500)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->foreign('persona_id')->references('id')->on('rh_personas');
            $t->index(['desde_at', 'cliente_id']);
        });
        Schema::connection($this->connection)->create('rh_marcajes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('persona_id');
            $t->string('fuente_codigo', 60);
            $t->string('id_externo', 150)->collation('utf8mb4_bin');
            $t->string('payload_hash', 64);
            $t->string('tipo', 10);
            $t->dateTime('instante_at', 6);
            $t->string('canal', 30);
            $t->uuid('corrige_id')->nullable();
            $t->dateTime('anulado_at')->nullable();
            $t->timestamps();
            $t->foreign('persona_id')->references('id')->on('rh_personas');
            $t->foreign('corrige_id')->references('id')->on('rh_marcajes');
            $t->unique(['fuente_codigo', 'id_externo']);
            $t->index(['persona_id', 'fuente_codigo', 'instante_at']);
        });
        Schema::connection($this->connection)->create('rh_importaciones', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('fuente_id');
            $t->unsignedInteger('recibidas');
            $t->unsignedInteger('aceptadas');
            $t->unsignedInteger('repetidas');
            $t->json('errores');
            $t->timestamps();
            $t->foreign('fuente_id')->references('id')->on('rh_fuentes');
        });
        Schema::connection($this->connection)->create('rh_eventos', function (Blueprint $t): void {
            $t->id();
            $t->string('entidad', 30);
            $t->uuid('registro_id');
            $t->string('accion', 40);
            $t->json('datos');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('user_nombre')->nullable();
            $t->dateTime('created_at');
            $t->index(['entidad', 'registro_id', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['rh_eventos', 'rh_importaciones', 'rh_marcajes', 'rh_asignaciones', 'rh_vinculos', 'rh_fuentes', 'rh_personas'] as $tabla) {
            Schema::connection($this->connection)->dropIfExists($tabla);
        }
    }
};

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
        $s->create('rh_expedientes_contratistas', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('empleador_id')->constrained('rh_empleadores');
            $t->json('empleador');
            $t->string('faena', 40);
            $t->string('mes', 7);
            $t->string('referencia_contrato', 200);
            $t->json('personal');
            $t->unsignedInteger('personas_count');
            $t->string('estado', 20)->default('pendiente');
            $t->unsignedInteger('version')->default(1);
            $t->string('observacion', 1000)->nullable();
            $t->string('revisado_por')->nullable();
            $t->dateTime('revisado_at')->nullable();
            $t->timestamps();
            $t->unique(['empleador_id', 'faena', 'mes'], 'rh_expediente_contratista_mes');
        });
        $s->create('rh_antecedentes_contratistas', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('expediente_id')->constrained('rh_expedientes_contratistas');
            $t->string('tipo', 30);
            $t->date('desde');
            $t->date('hasta');
            $t->date('fecha_documento');
            $t->string('referencia', 500);
            $t->json('personas');
            $t->string('estado', 20)->default('pendiente');
            $t->unsignedInteger('version')->default(1);
            $t->string('observacion', 1000)->nullable();
            $t->string('revisado_por')->nullable();
            $t->dateTime('revisado_at')->nullable();
            $t->timestamps();
            $t->unique(['expediente_id', 'tipo', 'referencia'], 'rh_antecedente_referencia');
        });
        $s->create('rh_retenciones_contratistas', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('operacion_id')->unique();
            $t->string('payload_hash', 64);
            $t->foreignUuid('expediente_id')->constrained('rh_expedientes_contratistas');
            $t->string('tipo', 30);
            $t->date('fecha');
            $t->decimal('monto', 18, 0);
            $t->string('referencia', 200);
            $t->foreignUuid('institucion_id')->nullable()->constrained('rh_instituciones');
            $t->foreignUuid('persona_destino_id')->nullable()->constrained('rh_personas');
            $t->json('destinatario')->nullable();
            $t->string('respaldo', 1000);
            $t->dateTime('anulado_at')->nullable();
            $t->timestamps();
            $t->unique(['expediente_id', 'tipo', 'referencia'], 'rh_retencion_referencia');
        });
    }

    public function down(): void
    {
        $s = Schema::connection($this->connection);
        foreach (['rh_retenciones_contratistas', 'rh_antecedentes_contratistas', 'rh_expedientes_contratistas'] as $tabla) {
            $s->dropIfExists($tabla);
        }
    }
};

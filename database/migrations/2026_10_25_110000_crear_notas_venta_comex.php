<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'comex';

    public function up(): void
    {
        Schema::connection('comex')->create('cx_notas_venta', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('vendedor_id')->constrained('cx_partes_comerciales');
            $t->string('folio', 80);
            $t->uuid('cliente_operacion_id');
            $t->json('cliente');
            $t->string('gestion', 20);
            $t->string('estado', 20)->default('abierta');
            $t->uuid('revision_activa_id')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['vendedor_id', 'folio'], 'cx_nota_folio_vendedor');
        });
        Schema::connection('comex')->create('cx_nota_revisiones', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('nota_id')->constrained('cx_notas_venta');
            $t->unsignedInteger('numero');
            $t->string('estado', 20)->default('borrador');
            $t->json('datos');
            $t->unsignedBigInteger('preparado_por_user_id');
            $t->string('preparado_por');
            $t->unsignedBigInteger('aprobado_por_user_id')->nullable();
            $t->string('aprobado_por')->nullable();
            $t->dateTime('enviado_at')->nullable();
            $t->dateTime('decidido_at')->nullable();
            $t->string('observacion', 1000)->nullable();
            $t->timestamps();
            $t->unique(['nota_id', 'numero']);
        });
        Schema::connection('comex')->table('cx_notas_venta', function (Blueprint $t): void {
            $t->foreign('revision_activa_id')->references('id')->on('cx_nota_revisiones');
        });
    }

    public function down(): void
    {
        Schema::connection('comex')->table('cx_notas_venta', fn (Blueprint $t) => $t->dropForeign(['revision_activa_id']));
        Schema::connection('comex')->dropIfExists('cx_nota_revisiones');
        Schema::connection('comex')->dropIfExists('cx_notas_venta');
    }
};

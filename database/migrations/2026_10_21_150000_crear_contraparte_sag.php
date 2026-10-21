<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_contraparte_sag', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->json('datos');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('responsables_contraparte_sag', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('nombre', 150);
            $table->string('rut', 20);
            $table->string('cargo', 40);
            $table->string('referencia_autorizacion', 250);
            $table->boolean('activo')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('eventos_contraparte_sag', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tipo', 50);
            $table->string('referencia', 150);
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('datos');
            $table->timestamp('created_at');
            $table->index(['tipo', 'referencia']);
        });
        Schema::create('saldos_existencia_sag', function (Blueprint $table) {
            $table->uuid('folio_id')->primary();
            $table->foreign('folio_id')->references('id')->on('folios')->restrictOnDelete();
            $table->foreignUuid('temporada_id')->constrained('temporadas')->restrictOnDelete();
            $table->unsignedInteger('cajas')->default(0);
            $table->unsignedInteger('unidades')->default(0);
            $table->json('snapshot');
            $table->timestamps();
            $table->index(['temporada_id', 'unidades']);
        });
        Schema::create('movimientos_existencia_sag', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('folio_id')->constrained('folios')->restrictOnDelete();
            $table->foreignUuid('temporada_id')->constrained('temporadas')->restrictOnDelete();
            $table->string('actividad', 50);
            $table->string('referencia', 150);
            $table->integer('variacion_cajas');
            $table->integer('variacion_unidades');
            $table->unsignedInteger('saldo_cajas');
            $table->unsignedInteger('saldo_unidades');
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->index(['temporada_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['movimientos_existencia_sag', 'saldos_existencia_sag', 'eventos_contraparte_sag', 'responsables_contraparte_sag', 'configuracion_contraparte_sag'] as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }
};

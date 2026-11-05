<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de cadena de frío: por etapa, la T° del aire planificada y la T°
 * superficial más baja que tendrá la fruta en ese aire. De ahí salen el punto
 * de rocío y la humedad máxima admisibles. Cada medición guarda el rocío máximo
 * del plan vigente al registrarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_cadena_frio_etapas', function (Blueprint $table): void {
            $table->string('etapa', 30)->primary();
            $table->decimal('temperatura_aire_c', 4, 1)->nullable();
            $table->decimal('temperatura_fruta_c', 4, 1)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('actualizado_por_user_id')->constrained('users');
            $table->timestamps();
        });

        Schema::table('mediciones_punto_rocio', function (Blueprint $table): void {
            $table->decimal('plan_rocio_maximo_c', 5, 2)->nullable()->after('origen_estado');
        });
    }

    public function down(): void
    {
        Schema::table('mediciones_punto_rocio', function (Blueprint $table): void {
            $table->dropColumn('plan_rocio_maximo_c');
        });
        Schema::dropIfExists('plan_cadena_frio_etapas');
    }
};

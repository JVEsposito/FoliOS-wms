<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculos entre chequeos de punto de rocío y margen mínimo por etapa.
 *
 * - Aire del envase: el aire de la sala de sellado queda dentro de la bolsa; se
 *   compara su punto de rocío con la T° superficial de una medición posterior.
 * - Fruta que llega: la fruta trae la T° superficial de otra medición; se compara
 *   con el punto de rocío del aire del punto al que llega.
 *
 * Cada medición guarda la comparación calculada al registrarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('margenes_punto_rocio_etapa', function (Blueprint $table): void {
            $table->string('etapa', 30)->primary();
            $table->decimal('margen_minimo_c', 4, 2);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('actualizado_por_user_id')->constrained('users');
            $table->timestamps();
        });

        Schema::table('mediciones_punto_rocio', function (Blueprint $table): void {
            $table->foreignUuid('sellado_referencia_id')->nullable()->after('margen_minimo_c')->constrained('mediciones_punto_rocio');
            $table->decimal('envase_punto_rocio_c', 5, 2)->nullable()->after('sellado_referencia_id');
            $table->decimal('envase_margen_c', 5, 2)->nullable()->after('envase_punto_rocio_c');
            $table->string('envase_estado', 30)->nullable()->after('envase_margen_c');
            $table->foreignUuid('origen_referencia_id')->nullable()->after('envase_estado')->constrained('mediciones_punto_rocio');
            $table->decimal('origen_superficie_c', 5, 2)->nullable()->after('origen_referencia_id');
            $table->decimal('origen_margen_c', 5, 2)->nullable()->after('origen_superficie_c');
            $table->string('origen_estado', 30)->nullable()->after('origen_margen_c');
        });
    }

    public function down(): void
    {
        Schema::table('mediciones_punto_rocio', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sellado_referencia_id');
            $table->dropConstrainedForeignId('origen_referencia_id');
            $table->dropColumn(['envase_punto_rocio_c', 'envase_margen_c', 'envase_estado', 'origen_superficie_c', 'origen_margen_c', 'origen_estado']);
        });
        Schema::dropIfExists('margenes_punto_rocio_etapa');
    }
};

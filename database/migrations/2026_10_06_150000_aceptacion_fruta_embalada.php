<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folios', function (Blueprint $t): void {
            $t->string('clave_identificador_integracion', 150)->nullable()->virtualAs("CASE WHEN origen_sistema = 'recepcion_externa' THEN id ELSE identificador_externo END");
            $t->dropUnique('folios_origen_externo_unique');
            $t->unique(['origen_sistema', 'clave_identificador_integracion'], 'folios_integracion_unica');
        });
        Schema::create('secuencias_folio_planta', function (Blueprint $t): void {
            $t->string('id', 20)->primary();
            $t->unsignedBigInteger('ultimo')->default(0);
        });
        Schema::create('aceptaciones_fruta_embalada', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('recepcion_id')->unique()->constrained('recepciones_fruta_embalada', indexName: 'afe_recepcion_fk');
            $t->foreignUuid('temporada_id')->constrained('temporadas');
            $t->foreignId('user_id')->constrained('users');
            $t->uuid('operacion_id')->unique();
            $t->string('payload_hash', 64);
            $t->string('estado', 20)->default('aceptada');
            $t->json('snapshot');
            $t->json('advertencias');
            $t->foreignUuid('plan_operacional_id')->nullable()->constrained('planes_operacionales');
            $t->dateTime('aceptada_at');
            $t->uuid('anulacion_operacion_id')->nullable()->unique();
            $t->foreignId('anulada_por_user_id')->nullable()->constrained('users');
            $t->dateTime('anulada_at')->nullable();
            $t->text('motivo_anulacion')->nullable();
            $t->timestamps();
        });
        Schema::create('recepcion_fruta_embalada_folios', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('aceptacion_id')->constrained('aceptaciones_fruta_embalada');
            $t->foreignUuid('recepcion_pallet_id')->unique()->constrained('recepciones_fruta_embalada_pallets', indexName: 'rfef_pallet_fk');
            $t->foreignUuid('folio_id')->unique()->constrained('folios');
            $t->string('folio_origen', 50);
            $t->boolean('folio_interno');
            $t->timestamps();
        });
        Schema::create('incidencias_recepcion_embalada', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('recepcion_folio_id')->constrained('recepcion_fruta_embalada_folios');
            $t->decimal('temperatura_pulpa', 6, 2);
            $t->decimal('umbral_prefrio', 6, 2);
            $t->string('estado', 20)->default('abierta');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('folios')->where('origen_sistema', 'recepcion_externa')->groupBy('identificador_externo')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new DomainException('No se puede revertir la unicidad mientras existan referencias externas repetidas.');
        }
        Schema::dropIfExists('incidencias_recepcion_embalada');
        Schema::dropIfExists('recepcion_fruta_embalada_folios');
        Schema::dropIfExists('aceptaciones_fruta_embalada');
        Schema::dropIfExists('secuencias_folio_planta');
        Schema::table('folios', function (Blueprint $t): void {
            $t->dropUnique('folios_integracion_unica');
            $t->dropColumn('clave_identificador_integracion');
            $t->unique(['origen_sistema', 'identificador_externo'], 'folios_origen_externo_unique');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recepciones_romana', function (Blueprint $table): void {
            $table->timestamp('eliminada_at')->nullable();
            $table->unsignedTinyInteger('guia_vigente')->nullable()->default(1);
        });
        Schema::table('recepciones_romana', function (Blueprint $table): void {
            $table->unique(['temporada_id', 'cliente_id', 'numero_guia_despacho', 'guia_vigente'], 'recepciones_romana_guia_vigente_unique');
            $table->dropUnique('recepciones_romana_temporada_cliente_guia_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('recepciones_romana')->groupBy('temporada_id', 'cliente_id', 'numero_guia_despacho')
            ->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Hay guías reutilizadas después de eliminar recepciones. La reversión requiere conservar esos registros históricos.');
        }
        Schema::table('recepciones_romana', function (Blueprint $table): void {
            $table->unique(['temporada_id', 'cliente_id', 'numero_guia_despacho'], 'recepciones_romana_temporada_cliente_guia_unique');
            $table->dropUnique('recepciones_romana_guia_vigente_unique');
            $table->dropColumn(['eliminada_at', 'guia_vigente']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bins de la planta que se agregan a una recepción cuando el campo manda más totes de los
 * que caben en sus bins. La referencia de totes por bin es por especie y solo sirve para
 * sugerir la cantidad en la PDA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preferencias_envase_especie', function (Blueprint $table): void {
            $table->unsignedSmallInteger('totes_por_bin')->nullable()->after('tipo_envase');
        });
        // Cereza: 24 totes por bin, igual a la relación entre sus pesos de referencia (210 / 8,75).
        $cerezas = DB::table('especies_validacion')->whereIn(DB::raw('LOWER(nombre)'), ['cereza', 'cerezas'])->pluck('id');
        foreach ($cerezas as $id) {
            $existe = DB::table('preferencias_envase_especie')->where('especie_validacion_id', $id)->exists();
            $existe
                ? DB::table('preferencias_envase_especie')->where('especie_validacion_id', $id)->whereNull('totes_por_bin')->update(['totes_por_bin' => 24])
                : DB::table('preferencias_envase_especie')->insert(['especie_validacion_id' => $id, 'tipo_envase' => 'totes', 'totes_por_bin' => 24, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::table('validaciones_mp', function (Blueprint $table): void {
            $table->unsignedSmallInteger('bins_prestados_planta')->default(0)->after('requiere_segregacion');
        });
        Schema::table('segmentos_validacion_mp', function (Blueprint $table): void {
            $table->unsignedSmallInteger('bins_prestados_planta')->default(0)->after('variedad_snapshot');
        });
        Schema::table('lotes_materia_prima', function (Blueprint $table): void {
            $table->unsignedSmallInteger('bins_prestados_planta')->default(0)->after('cantidad_envases_secundarios');
        });
    }

    public function down(): void
    {
        Schema::table('lotes_materia_prima', fn (Blueprint $table) => $table->dropColumn('bins_prestados_planta'));
        Schema::table('segmentos_validacion_mp', fn (Blueprint $table) => $table->dropColumn('bins_prestados_planta'));
        Schema::table('validaciones_mp', fn (Blueprint $table) => $table->dropColumn('bins_prestados_planta'));
        Schema::table('preferencias_envase_especie', fn (Blueprint $table) => $table->dropColumn('totes_por_bin'));
    }
};

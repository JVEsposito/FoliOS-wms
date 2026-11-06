<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Columnas generadas e indexadas: incluyen el historial sin reescribir asientos.
        foreach (['recepciones_romana' => 'numero_guia_salida', 'guias_despacho_envases' => 'numero_guia_despacho'] as $tabla => $columna) {
            $limpio = "UPPER(REPLACE(REPLACE(TRIM({$columna}), ' ', ''), '.', ''))";
            $noDigitos = $limpio;
            foreach (range(0, 9) as $digito) {
                $noDigitos = "REPLACE({$noDigitos}, '{$digito}', '')";
            }
            $sinCeros = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
                ? "TRIM(LEADING '0' FROM {$limpio})" : "LTRIM({$limpio}, '0')";
            $expresion = "CASE WHEN {$noDigitos} = '' THEN {$sinCeros} ELSE {$limpio} END";
            Schema::table($tabla, function (Blueprint $table) use ($expresion, $tabla): void {
                $table->string('documento_salida_normalizado', 100)->nullable()->virtualAs($expresion);
                $table->index(['temporada_id', 'cliente_id', 'documento_salida_normalizado'], $tabla === 'recepciones_romana' ? 'romana_documento_salida_idx' : 'guia_documento_salida_idx');
            });
        }
        // Mutex transaccional por documento, compartido por Romana y Despacho.
        Schema::create('documentos_salida_envases', function (Blueprint $table): void {
            $table->char('clave', 64)->primary();
            $table->foreignUuid('temporada_id')->constrained('temporadas');
            $table->foreignUuid('cliente_id')->constrained('clientes');
            $table->string('numero', 20);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_salida_envases');
        foreach (['recepciones_romana' => 'romana_documento_salida_idx', 'guias_despacho_envases' => 'guia_documento_salida_idx'] as $tabla => $indice) {
            Schema::table($tabla, function (Blueprint $table) use ($indice): void {
                $table->dropIndex($indice);
                $table->dropColumn('documento_salida_normalizado');
            });
        }
    }
};

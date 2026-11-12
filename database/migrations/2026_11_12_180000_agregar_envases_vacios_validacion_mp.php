<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envases que llegan vacíos en una recepción con fruta (por ejemplo, totes sin fruta dentro
 * de los bins). Cuentan como recibidos para la cuenta corriente y la tara del destare, pero
 * no para el peso promedio por envase ni para los lotes.
 *
 * - En el detalle de Romana, `cantidad_validada` sigue siendo todo lo contado y
 *   `cantidad_vacia` la parte que llegó sin fruta.
 * - En el segmento, `cantidad` son los envases con fruta que pasan al lote y
 *   `cantidad_vacia` los vacíos de ese segmento (y de su guía).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detalles_envases_recepcion_romana', function (Blueprint $table): void {
            $table->unsignedInteger('cantidad_vacia')->default(0)->after('cantidad_validada');
        });
        Schema::table('segmentos_envases_validacion_mp', function (Blueprint $table): void {
            $table->unsignedInteger('cantidad_vacia')->default(0)->after('cantidad');
        });
    }

    public function down(): void
    {
        Schema::table('segmentos_envases_validacion_mp', fn (Blueprint $table) => $table->dropColumn('cantidad_vacia'));
        Schema::table('detalles_envases_recepcion_romana', fn (Blueprint $table) => $table->dropColumn('cantidad_vacia'));
    }
};

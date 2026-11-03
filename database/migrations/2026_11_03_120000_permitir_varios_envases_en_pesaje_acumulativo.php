<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El pesaje acumulativo admite uno o más tipos de envase y cada tanda puede
 * mezclarlos. La tanda guarda su detalle por tipo (cantidad, tara y neto); las
 * columnas existentes conservan los totales de la lectura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pesajes_envases_recepcion_romana', function (Blueprint $table): void {
            $table->json('envases')->nullable()->after('cantidad_envases');
        });

        // Las tandas anteriores tenían un solo tipo: su detalle es la propia lectura.
        DB::table('pesajes_envases_recepcion_romana')
            ->whereNull('envases')
            ->chunkById(500, function ($pesajes): void {
                foreach ($pesajes as $pesaje) {
                    DB::table('pesajes_envases_recepcion_romana')->where('id', $pesaje->id)->update([
                        'envases' => json_encode([[
                            'tipo_envase' => $pesaje->tipo_envase,
                            'cantidad' => (int) $pesaje->cantidad_envases,
                            'tara_unitaria' => round((float) $pesaje->tara_unitaria_envase, 3),
                            'peso_tara' => round((float) $pesaje->peso_tara, 3),
                            'peso_neto' => round((float) $pesaje->peso_neto, 3),
                        ]], JSON_THROW_ON_ERROR),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('pesajes_envases_recepcion_romana', function (Blueprint $table): void {
            $table->dropColumn('envases');
        });
    }
};

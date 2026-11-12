<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El set fotográfico provisional de la R-24 se reemplaza por el oficial del equipo de
 * consolidación. Las fotos ya subidas pasan a la categoría nueva equivalente; las que no
 * tienen equivalente quedan en «Otras fotos del despacho». Los PDF ya armados no cambian.
 */
return new class extends Migration
{
    private const EQUIVALENCIAS = [
        'patente' => 'camion_patentes',
        'temperatura_corte' => 'seteo_equipo',
        'primer_bloque' => 'mitad_carga',
        'termografos' => 'general',
        'altura_carga' => 'general',
    ];

    public function up(): void
    {
        foreach (self::EQUIVALENCIAS as $anterior => $nuevo) {
            DB::table('fotos_control_despacho')->where('tipo', $anterior)->update(['tipo' => $nuevo]);
        }
    }

    public function down(): void
    {
        DB::table('fotos_control_despacho')->where('tipo', 'camion_patentes')->update(['tipo' => 'patente']);
        DB::table('fotos_control_despacho')->where('tipo', 'seteo_equipo')->update(['tipo' => 'temperatura_corte']);
        DB::table('fotos_control_despacho')->where('tipo', 'mitad_carga')->update(['tipo' => 'primer_bloque']);
    }
};

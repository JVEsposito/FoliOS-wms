<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patente de la rampla (carro) del camión que se lleva los envases, como en Romana: la cuenta corriente
 * de envases la muestra junto al chofer y la patente del camión. Opcional; las guías anteriores quedan vacías.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guias_despacho_envases', function (Blueprint $table): void {
            $table->string('patente_carro', 10)->nullable()->after('patente_camion');
        });
    }

    public function down(): void
    {
        Schema::table('guias_despacho_envases', function (Blueprint $table): void {
            $table->dropColumn('patente_carro');
        });
    }
};

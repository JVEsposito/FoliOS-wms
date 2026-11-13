<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos del registro de prefrío POEMP R-41 que no salen de la operación del túnel:
 * T° objetivo, supervisor de prefrío, jefe de frío y servicio. Las lecturas por sector
 * viven en los eventos «lectura» y en la lectura final de «verificacion_final».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procesos_prefrio', function (Blueprint $table): void {
            $table->json('registro_r41')->nullable()->after('observacion');
        });
    }

    public function down(): void
    {
        Schema::table('procesos_prefrio', fn (Blueprint $table) => $table->dropColumn('registro_r41'));
    }
};

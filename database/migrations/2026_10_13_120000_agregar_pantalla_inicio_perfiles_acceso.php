<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perfiles_acceso', function (Blueprint $tabla): void {
            // Ruta de Oficina a la que llega el usuario al entrar a /FoliOS. Null: la del rol.
            $tabla->string('pantalla_inicio', 120)->nullable()->after('modulos_tablet');
        });
    }

    public function down(): void
    {
        Schema::table('perfiles_acceso', function (Blueprint $tabla): void {
            $tabla->dropColumn('pantalla_inicio');
        });
    }
};

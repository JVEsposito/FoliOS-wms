<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perfiles_acceso', function (Blueprint $table): void {
            // Null conserva la matriz anterior. [] deniega las acciones
            // de este catálogo; la selección explícita autoriza solo esas acciones.
            $table->json('permisos_materiales')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('perfiles_acceso', fn (Blueprint $table) => $table->dropColumn('permisos_materiales'));
    }
};

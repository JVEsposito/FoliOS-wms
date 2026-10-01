<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos_lote_materia_prima', function (Blueprint $table): void {
            // Los cambios hechos por un comando se auditan sin atribuirlos al digitador anterior.
            $table->foreignId('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('eventos_lote_materia_prima')->whereNull('user_id')->exists()) {
            throw new RuntimeException('Existen eventos de sistema; no es seguro exigir un usuario al revertir.');
        }
        Schema::table('eventos_lote_materia_prima', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};

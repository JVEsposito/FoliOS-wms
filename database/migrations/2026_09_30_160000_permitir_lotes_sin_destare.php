<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lotes_materia_prima', function (Blueprint $table): void {
            $table->decimal('kilos_brutos', 12, 3)->nullable()->change();
            $table->decimal('kilos_netos_calculados', 12, 3)->nullable()->change();
            $table->decimal('kilos_netos_confirmados', 12, 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Los borradores sin destare pueden tener los tres pesos en NULL.
        // No se puede restaurar NOT NULL sin inventar pesos para esos lotes.
    }
};

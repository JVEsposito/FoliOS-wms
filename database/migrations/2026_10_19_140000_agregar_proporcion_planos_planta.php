<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planos_planta', function (Blueprint $table): void {
            // Ancho dividido por alto del dibujo; nulo conserva el formato apaisado 16:9.
            $table->decimal('proporcion', 6, 4)->nullable()->after('nombre');
        });
    }

    public function down(): void
    {
        Schema::table('planos_planta', function (Blueprint $table): void {
            $table->dropColumn('proporcion');
        });
    }
};

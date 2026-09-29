<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lotes_materia_prima', function (Blueprint $table): void {
            $table->char('ggn', 13)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Un GGN omitido es válido desde esta versión; no se puede volver a NOT NULL
        // sin inventar identificadores para los lotes creados después del despliegue.
    }
};

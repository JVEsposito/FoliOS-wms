<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos_lote_materia_prima', function (Blueprint $table) {
            $table->index(['tipo', 'ocurrido_at'], 'evento_lote_mp_tipo_fecha_index');
        });
    }

    public function down(): void
    {
        Schema::table('eventos_lote_materia_prima', function (Blueprint $table) {
            $table->dropIndex('evento_lote_mp_tipo_fecha_index');
        });
    }
};

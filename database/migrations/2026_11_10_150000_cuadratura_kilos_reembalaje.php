<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repaletizajes', function (Blueprint $table): void {
            foreach (['kilos_entrada', 'kilos_salida', 'kilos_merma'] as $campo) {
                $table->decimal($campo, 16, 4)->nullable();
            }
            $table->string('motivo_merma', 1000)->nullable();
        });
        Schema::table('repaletizaje_detalles', fn (Blueprint $table) => $table->decimal('kilos_aportados', 16, 4)->nullable());
        Schema::table('repaletizaje_resultados', fn (Blueprint $table) => $table->decimal('kilos_netos_por_caja', 12, 4)->nullable());
    }

    public function down(): void
    {
        Schema::table('repaletizaje_resultados', fn (Blueprint $table) => $table->dropColumn('kilos_netos_por_caja'));
        Schema::table('repaletizaje_detalles', fn (Blueprint $table) => $table->dropColumn('kilos_aportados'));
        Schema::table('repaletizajes', fn (Blueprint $table) => $table->dropColumn(['kilos_entrada', 'kilos_salida', 'kilos_merma', 'motivo_merma']));
    }
};

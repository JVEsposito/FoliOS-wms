<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['bins_retorno_packing', 'sublotes_retorno_packing', 'despachos_comerciales_retorno_bins'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table): void {
                $table->string('tipo_envase', 20)->default('bins');
                $table->unsignedInteger('cantidad_envases')->default(1);
            });
        }
        DB::table('sublotes_retorno_packing')->update(['cantidad_envases' => DB::raw('cantidad_bins')]);
    }

    public function down(): void
    {
        $tablas = ['bins_retorno_packing', 'sublotes_retorno_packing', 'despachos_comerciales_retorno_bins'];
        foreach ($tablas as $tabla) {
            if (DB::table($tabla)->where('tipo_envase', '!=', 'bins')->exists()) {
                throw new LogicException('Existen retornos en cajas 3/4; no se pueden eliminar sus unidades de medida.');
            }
        }
        foreach ($tablas as $tabla) {
            Schema::table($tabla, fn (Blueprint $table) => $table->dropColumn(['tipo_envase', 'cantidad_envases']));
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carga_folios', function (Blueprint $table): void {
            $table->foreignUuid('instructivo_embarque_id')->nullable()
                ->constrained('instructivos_embarque')->restrictOnDelete();
            $table->index(['carga_id', 'instructivo_embarque_id'], 'carga_folios_instructivo_index');
        });

        // Solo se puede inferir la pertenencia cuando el embarque tiene un instructivo.
        // Las cargas históricas con varios conservan sus folios sin clasificación.
        DB::table('embarques as e')->join('instructivos_embarque as i', 'i.embarque_id', '=', 'e.id')
            ->whereNotNull('e.carga_id')->groupBy('e.carga_id')->havingRaw('COUNT(*) = 1')
            ->selectRaw('e.carga_id, MIN(i.id) as instructivo_id')->get()
            ->each(fn (object $fila) => DB::table('carga_folios')->where('carga_id', $fila->carga_id)
                ->update(['instructivo_embarque_id' => $fila->instructivo_id]));
    }

    public function down(): void
    {
        Schema::table('carga_folios', function (Blueprint $table): void {
            $table->dropForeign(['instructivo_embarque_id']);
            $table->dropIndex('carga_folios_instructivo_index');
            $table->dropColumn('instructivo_embarque_id');
        });
    }
};

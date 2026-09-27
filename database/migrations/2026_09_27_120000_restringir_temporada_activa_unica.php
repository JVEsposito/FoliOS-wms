<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicadas = DB::table('temporadas')
            ->where('activa', true)
            ->orderBy('codigo')
            ->pluck('codigo');

        if ($duplicadas->count() > 1) {
            throw new RuntimeException('Hay varias temporadas activas: '.$duplicadas->implode(', ').'. Resuélvelas en Accesos antes de migrar.');
        }

        $expresion = 'CASE WHEN activa = 1 THEN 1 ELSE NULL END';
        if (DB::getDriverName() === 'mysql') {
            // Un solo ALTER deja la columna y el índice juntos si falla el DDL.
            DB::statement("ALTER TABLE `temporadas` ADD COLUMN `activa_unica` TINYINT GENERATED ALWAYS AS ({$expresion}) STORED, ADD UNIQUE INDEX `temporadas_activa_unica_unique` (`activa_unica`)");

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("ALTER TABLE temporadas ADD COLUMN activa_unica INTEGER GENERATED ALWAYS AS ({$expresion}) VIRTUAL");
            Schema::table('temporadas', fn (Blueprint $tabla) => $tabla->unique('activa_unica', 'temporadas_activa_unica_unique'));

            return;
        }

        throw new RuntimeException('La unicidad de temporada activa requiere MySQL o SQLite.');
    }

    public function down(): void
    {
        Schema::table('temporadas', function (Blueprint $tabla): void {
            $tabla->dropUnique('temporadas_activa_unica_unique');
            $tabla->dropColumn('activa_unica');
        });
    }
};

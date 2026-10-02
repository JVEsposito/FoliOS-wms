<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Solo modifica los folios que realmente llegaron al andén. El filtro de
        // estado hace que la migración sea segura al ejecutarla de nuevo.
        DB::table('carga_folios')
            ->where('estado', 'en_anden')
            ->whereIn('carga_id', DB::table('cargas')
                ->select('id')
                ->whereIn('estado', ['despachada', 'cerrada']))
            ->update(['estado' => 'despachado']);
    }

    public function down(): void
    {
        // No revertir estados de negocio ya confirmados.
    }
};

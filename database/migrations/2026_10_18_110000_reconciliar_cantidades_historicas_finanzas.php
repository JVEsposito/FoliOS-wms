<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'finanzas';

    public function up(): void
    {
        // La próxima captura reconstruye todo el historial y anula estadías duplicadas.
        // La lectura no se ejecuta durante el despliegue ni altera las versiones anteriores.
        DB::connection('finanzas')->table('fin_cursores')->whereIn('fuente', ['prefrio', 'estadia'])
            ->update(['conciliada_at' => null]);
    }

    public function down(): void {}
};

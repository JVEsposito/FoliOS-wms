<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // El índice único ya existe. La clave nueva reserva temporada+número,
        // sin cliente. Si había duplicados históricos, solo uno reserva la clave
        // global; los demás conservan su clave anterior hasta corregirlos por ID.
        // No se borran ni se renumeran registros para poder desplegar la solución.
        DB::table('lotes_materia_prima')->where('estado', '!=', 'anulado')->orderBy('id')
            ->chunkById(500, function ($lotes): void {
                foreach ($lotes as $lote) {
                    $clave = hash('sha256', implode('|', ['temporada', $lote->temporada_id, mb_strtoupper(trim($lote->numero_lote))]));
                    if (! DB::table('lotes_materia_prima')->where('clave_numero_vigente', $clave)->exists()) {
                        DB::table('lotes_materia_prima')->where('id', $lote->id)->update(['clave_numero_vigente' => $clave]);
                    }
                }
            }, 'id');
    }

    public function down(): void
    {
        DB::table('lotes_materia_prima')->where('estado', '!=', 'anulado')->orderBy('id')
            ->chunkById(500, function ($lotes): void {
                foreach ($lotes as $lote) {
                    DB::table('lotes_materia_prima')->where('id', $lote->id)->update(['clave_numero_vigente' => hash('sha256',
                        implode('|', [$lote->temporada_id, $lote->cliente_id, mb_strtoupper(trim($lote->numero_lote))]))]);
                }
            }, 'id');
    }
};

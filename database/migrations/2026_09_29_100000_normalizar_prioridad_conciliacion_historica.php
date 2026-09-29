<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // El rezago histórico solo ocupa capacidad sobrante. Limitar el cambio
        // a planes vivos evita reescribir el historial de planes finalizados.
        $planes = static fn () => DB::table('planes_operacionales')
            ->select('id')
            ->where('referencia_tipo', 'folio_pendiente_ubicacion')
            ->whereNotIn('estado', ['completado', 'cancelado']);

        foreach (['maniobras_operacionales', 'tareas_movimiento'] as $tabla) {
            DB::table($tabla)
                ->whereIn('plan_operacional_id', $planes())
                ->where('prioridad', '!=', 'normal')
                ->update(['prioridad' => 'normal', 'version' => DB::raw('version + 1')]);
        }

        DB::table('planes_operacionales')
            ->where('referencia_tipo', 'folio_pendiente_ubicacion')
            ->whereNotIn('estado', ['completado', 'cancelado'])
            ->where('prioridad', '!=', 'normal')
            ->update(['prioridad' => 'normal', 'version' => DB::raw('version + 1')]);
    }

    public function down(): void
    {
        // No se restituye una prioridad que ya sería incorrecta.
    }
};

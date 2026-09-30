<?php

namespace App\Services\MateriaPrima;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** La migración solo proyecta el resumen histórico en el detalle; nunca corrige datos de negocio. */
class RellenoEnvasesLotes
{
    public function copiarResumenExistente(): void
    {
        DB::table('lotes_materia_prima')->orderBy('id')->chunk(100, function ($lotes): void {
            foreach ($lotes as $lote) {
                $this->copiar($lote, $lote->envase_primario, (int) $lote->cantidad_envases_primarios);
                $this->copiar($lote, $lote->envase_secundario, (int) $lote->cantidad_envases_secundarios);
            }
        });
    }

    private function copiar(object $lote, ?string $tipo, int $cantidad): void
    {
        if (! $tipo || $cantidad < 1) {
            return;
        }
        $tara = DB::table('detalles_envases_recepcion_romana')
            ->where('recepcion_romana_id', $lote->recepcion_romana_id)
            ->where('tipo_envase', $tipo)->value('tara_unitaria_salida');
        DB::table('lotes_materia_prima_envases')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'lote_materia_prima_id' => $lote->id,
            'tipo_envase' => $tipo,
            'cantidad' => $cantidad,
            'tara_unitaria' => $tara,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

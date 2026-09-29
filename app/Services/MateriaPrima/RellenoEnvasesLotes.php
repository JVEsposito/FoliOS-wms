<?php

namespace App\Services\MateriaPrima;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Backfill transaccional e idempotente: los segmentos históricos divididos quedan intactos. */
class RellenoEnvasesLotes
{
    /** @return list<string> IDs de segmentos con varios lotes activos que requieren revisión manual. */
    public function ejecutar(): array
    {
        DB::table('lotes_materia_prima')->orderBy('id')->chunk(100, function ($lotes): void {
            foreach ($lotes as $lote) {
                $this->insertarEnvase($lote, $lote->envase_primario, (int) $lote->cantidad_envases_primarios);
                if ($lote->envase_secundario && $lote->cantidad_envases_secundarios > 0) {
                    $this->insertarEnvase($lote, $lote->envase_secundario, (int) $lote->cantidad_envases_secundarios);
                }
            }
        });

        $multiples = [];
        DB::table('segmentos_validacion_mp')->orderBy('id')->chunk(100, function ($segmentos) use (&$multiples): void {
            foreach ($segmentos as $segmento) {
                $activos = DB::table('lotes_materia_prima')
                    ->where('segmento_validacion_mp_id', $segmento->id)
                    ->where('estado', '!=', 'anulado')->get();
                if ($activos->count() > 1) {
                    $multiples[] = $segmento->id;

                    continue;
                }
                if ($activos->isEmpty()) {
                    continue;
                }

                $lote = $activos->first();
                $envases = DB::table('segmentos_envases_validacion_mp')
                    ->where('segmento_validacion_mp_id', $segmento->id)
                    ->where('cantidad', '>', 0)->get();
                foreach ($envases as $envase) {
                    $this->insertarEnvase($lote, $envase->tipo_envase, (int) $envase->cantidad);
                    DB::table('lotes_materia_prima_envases')
                        ->where('lote_materia_prima_id', $lote->id)
                        ->where('tipo_envase', $envase->tipo_envase)
                        ->where('cantidad', '!=', $envase->cantidad)
                        ->update(['cantidad' => $envase->cantidad, 'updated_at' => now()]);
                }
                $primarios = $envases->firstWhere('tipo_envase', $lote->envase_primario)?->cantidad
                    ?? $lote->cantidad_envases_primarios;
                $secundarios = $lote->envase_secundario
                    ? ($envases->firstWhere('tipo_envase', $lote->envase_secundario)?->cantidad
                        ?? $lote->cantidad_envases_secundarios)
                    : 0;
                if ($primarios != $lote->cantidad_envases_primarios || $secundarios != $lote->cantidad_envases_secundarios) {
                    DB::table('lotes_materia_prima')->where('id', $lote->id)->update([
                        'cantidad_envases_primarios' => $primarios, 'cantidad_envases_secundarios' => $secundarios,
                        'updated_at' => now(),
                    ]);
                }
                if ($segmento->estado !== 'lotizado') {
                    DB::table('segmentos_validacion_mp')->where('id', $segmento->id)->update([
                        'estado' => 'lotizado', 'updated_at' => now(),
                    ]);
                }

                $taras = DB::table('lotes_materia_prima_envases')
                    ->where('lote_materia_prima_id', $lote->id)->get();
                if ($taras->every(fn ($item): bool => $item->tara_unitaria !== null)) {
                    $brutos = round((float) $lote->kilos_netos_calculados
                        + $taras->sum(fn ($item): float => $item->cantidad * (float) $item->tara_unitaria), 3);
                    if (abs((float) $lote->kilos_brutos - $brutos) > 0.0001) {
                        DB::table('lotes_materia_prima')->where('id', $lote->id)->update([
                            'kilos_brutos' => $brutos, 'updated_at' => now(),
                        ]);
                    }
                }
            }
        });

        return $multiples;
    }

    private function insertarEnvase(object $lote, ?string $tipo, int $cantidad): void
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

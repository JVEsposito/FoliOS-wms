<?php

namespace App\Services\InspeccionSag;

use App\Models\Folio;
use App\Services\Temporadas\ServicioTemporadaActiva;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioExistenciasSag
{
    /** Registra diferencias; volver a ejecutar el mismo corte no duplica entradas. */
    public function sincronizar(array $ids, string $actividad, string $referencia): void
    {
        DB::transaction(function () use ($ids, $actividad, $referencia): void {
            $temporada = app(ServicioTemporadaActiva::class)->buscarConBloqueoCompartido();
            if (! $temporada) {
                throw new DomainException('Activa una temporada para registrar existencias SAG.');
            }
            $folios = Folio::query()->whereIn('id', $ids)->orderBy('id')->with(['autorizacionesSagActivas', 'condicionSag'])->lockForUpdate()->get();
            foreach ($folios as $folio) {
                if ($folio->temporada_id !== $temporada->id) {
                    throw new DomainException('El folio no pertenece a la temporada activa.');
                }
                $datos = $folio->datos_externos ?? [];
                $aprobado = $folio->condicion_sag_id !== null || $folio->autorizacionesSagActivas->isNotEmpty();
                $enPlanta = $folio->activo && ! in_array($folio->estado_operacional->value, ['despachado', 'anulado', 'agotado', 'retirado_definitivo'], true);
                $cajas = $aprobado && $enPlanta ? max(0, (int) ($datos['cantidad_cajas'] ?? 0)) : 0;
                $snapshot = [
                    'folio' => $folio->numero_folio, 'especie' => $datos['especie'] ?? '',
                    'cliente' => $folio->exportadora, 'variedad' => $folio->variedad,
                    'csg' => $datos['csg'] ?? '', 'condicion' => $folio->condicionSag?->nombre,
                    'destinos' => $folio->autorizacionesSagActivas->pluck('destino_snapshot')->all(),
                ];
                $ahora = now();
                DB::table('saldos_existencia_sag')->insertOrIgnore([
                    'folio_id' => $folio->id, 'temporada_id' => $temporada->id,
                    'cajas' => 0, 'unidades' => 0, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
                $saldo = DB::table('saldos_existencia_sag')->where('folio_id', $folio->id)->lockForUpdate()->first();
                $unidades = $cajas > 0 ? 1 : 0;
                if ($cajas !== (int) $saldo->cajas || $unidades !== (int) $saldo->unidades) {
                    DB::table('movimientos_existencia_sag')->insert([
                        'id' => (string) Str::uuid(), 'folio_id' => $folio->id, 'temporada_id' => $temporada->id,
                        'actividad' => $actividad, 'referencia' => $referencia,
                        'variacion_cajas' => $cajas - $saldo->cajas, 'variacion_unidades' => $unidades - $saldo->unidades,
                        'saldo_cajas' => $cajas, 'saldo_unidades' => $unidades,
                        'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'created_at' => $ahora,
                    ]);
                }
                DB::table('saldos_existencia_sag')->where('folio_id', $folio->id)->update([
                    'cajas' => $cajas, 'unidades' => $unidades,
                    'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'updated_at' => $ahora,
                ]);
            }
        }, 3);
    }

    public function corteInicial(): int
    {
        $temporada = app(ServicioTemporadaActiva::class)->obtener();
        $cantidad = 0;
        Folio::query()->where('temporada_id', $temporada->id)
            ->where(fn ($q) => $q->whereNotNull('condicion_sag_id')->orWhereHas('autorizacionesSagActivas'))
            ->select('id')->chunkById(100, function ($folios) use (&$cantidad): void {
                $this->sincronizar($folios->pluck('id')->all(), 'corte_inicial', 'Corte inicial de existencias');
                $cantidad += $folios->count();
            });

        return $cantidad;
    }
}

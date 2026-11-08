<?php

namespace App\Services\Validacion;

use App\Enums\EstadoLoteMateriaPrima;
use App\Models\Folio;
use App\Models\LoteMateriaPrima;
use App\Models\TrazabilidadFolioOrigen;
use Illuminate\Support\Facades\DB;

/**
 * Mantiene trazabilidad_folio_origenes a partir de la composición guardada en el folio.
 * Validación, correcciones y repaletizajes solo modifican datos_externos; esta proyección
 * los vuelve consultables por lote de materia prima o proceso de packing.
 */
class ProyeccionTrazabilidadFolio
{
    /** Normaliza el número impreso en la etiqueta del pallet, o null si viene vacío. */
    public static function normalizarCodigo(mixed $valor): ?string
    {
        $texto = mb_strtoupper(trim((string) $valor));

        return $texto === '' ? null : $texto;
    }

    public function sincronizar(Folio $folio): void
    {
        $lineas = collect($folio->datos_externos['composicion'] ?? [])
            ->filter(fn (mixed $linea): bool => is_array($linea) && (int) ($linea['cantidad_cajas'] ?? 0) > 0)
            ->values();
        $origenes = $this->origenesValidados($lineas->pluck('origen_validacion_id')->filter()->unique()->all());

        DB::transaction(function () use ($folio, $lineas, $origenes): void {
            TrazabilidadFolioOrigen::query()->where('folio_id', $folio->id)->delete();

            foreach ($lineas as $linea) {
                $numeroLote = self::normalizarCodigo($linea['lote_materia_prima'] ?? null);
                $origen = $origenes[$linea['origen_validacion_id'] ?? ''] ?? null;
                $clienteId = $origen?->cliente_id;
                // La línea pertenece a la temporada en que se embaló; nunca se reinterpreta con
                // los lotes de otra temporada, aunque el folio se traslade.
                $temporadaId = $origen?->temporada_id ?? $folio->temporada_id;

                TrazabilidadFolioOrigen::create([
                    'folio_id' => $folio->id,
                    'temporada_id' => $temporadaId,
                    'csg' => self::normalizarCodigo($linea['csg'] ?? null),
                    'predio' => filled($linea['predio'] ?? null) ? trim((string) $linea['predio']) : null,
                    'fecha_embalaje' => filled($linea['fecha_embalaje'] ?? null) ? $linea['fecha_embalaje'] : null,
                    'numero_lote_materia_prima' => $numeroLote,
                    'cliente_id' => $clienteId,
                    'lote_materia_prima_id' => $this->loteRegistrado($temporadaId, $numeroLote, $clienteId),
                    'numero_proceso_packing' => self::normalizarCodigo($linea['proceso_packing'] ?? null),
                    'cantidad_cajas' => (int) $linea['cantidad_cajas'],
                ]);
            }
        });
    }

    /**
     * Busca el lote digitado en Materia Prima con el mismo número, temporada y cliente.
     * Sin cliente verificable, o con más de un lote vigente posible, no se afirma ningún
     * vínculo: la consulta muestra solo el número impreso en la etiqueta.
     */
    public function loteRegistrado(?string $temporadaId, ?string $numeroLote, ?string $clienteId): ?string
    {
        if ($temporadaId === null || $numeroLote === null || $clienteId === null) {
            return null;
        }

        $lotes = LoteMateriaPrima::query()
            ->where('temporada_id', $temporadaId)
            ->where('cliente_id', $clienteId)
            ->whereRaw('UPPER(TRIM(numero_lote)) = ?', [$numeroLote])
            ->where('estado', '!=', EstadoLoteMateriaPrima::Anulado->value)
            ->limit(2)
            ->pluck('id');

        if ($lotes->isNotEmpty()) {
            return $lotes->count() === 1 ? $lotes->first() : null;
        }
        // La etiqueta puede conservar el número anterior a una corrección
        // administrativa. Solo se resuelve por un alias auditado del mismo
        // cliente y temporada, nunca por coincidencia con otra exportadora.
        $corregidos = LoteMateriaPrima::query()->where('temporada_id', $temporadaId)->where('cliente_id', $clienteId)
            ->where('estado', '!=', EstadoLoteMateriaPrima::Anulado->value)
            ->whereHas('eventos', fn ($q) => $q->where('tipo', 'numero_lote_corregido')->where('datos->cliente_id', $clienteId)
                ->where('datos->temporada_id', $temporadaId)->where('datos->anterior', $numeroLote))
            ->limit(2)->pluck('id');

        return $corregidos->count() === 1 ? $corregidos->first() : null;
    }

    /**
     * Recalcula los vínculos afectados cuando un lote MP se digita, cambia de número o
     * cliente, o se anula. Cubre el caso habitual de un pallet validado antes que su lote.
     */
    public function conciliarLote(LoteMateriaPrima $lote): void
    {
        $numeros = collect([
            self::normalizarCodigo($lote->numero_lote),
            self::normalizarCodigo($lote->getOriginal('numero_lote')),
        ])->filter()->unique()->values()->all();
        $temporadas = collect([$lote->temporada_id, $lote->getOriginal('temporada_id')])
            ->filter()->unique()->values()->all();

        TrazabilidadFolioOrigen::query()
            ->where(fn ($consulta) => $consulta
                ->where('lote_materia_prima_id', $lote->id)
                ->orWhere(fn ($porNumero) => $porNumero
                    ->whereIn('temporada_id', $temporadas)
                    ->whereIn('numero_lote_materia_prima', $numeros)))
            ->select(['temporada_id', 'numero_lote_materia_prima', 'cliente_id'])
            ->distinct()
            ->get()
            ->each(function (TrazabilidadFolioOrigen $grupo): void {
                TrazabilidadFolioOrigen::query()
                    ->where('temporada_id', $grupo->temporada_id)
                    ->where('numero_lote_materia_prima', $grupo->numero_lote_materia_prima)
                    ->where('cliente_id', $grupo->cliente_id)
                    ->update(['lote_materia_prima_id' => $this->loteRegistrado(
                        $grupo->temporada_id,
                        $grupo->numero_lote_materia_prima,
                        $grupo->cliente_id,
                    )]);
            });
    }

    /**
     * Temporada y cliente global de cada origen validado de la composición.
     *
     * @param  array<int, string>  $origenIds
     * @return array<string, object{temporada_id: string, cliente_id: ?string}>
     */
    private function origenesValidados(array $origenIds): array
    {
        if ($origenIds === []) {
            return [];
        }

        return DB::table('origenes_validacion as origen')
            ->leftJoin('clientes_validacion as cliente', 'cliente.id', '=', 'origen.cliente_validacion_id')
            ->whereIn('origen.id', $origenIds)
            ->get(['origen.id', 'origen.temporada_id', 'cliente.cliente_id'])
            ->keyBy('id')
            ->all();
    }
}

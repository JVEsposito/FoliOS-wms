<?php

namespace App\Services\MateriaPrima;

use App\Exceptions\ConflictoOperacion;
use App\Models\BinRetornoPacking;
use App\Models\DespachoComercialRetorno;
use App\Models\User;
use App\Services\Secuencias\ServicioSecuenciaDocumento;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Support\Facades\DB;

class ServicioDespachoComercialRetorno
{
    public function __construct(
        private readonly ServicioSecuenciaDocumento $secuencias,
        private readonly ServicioTemporadaActiva $temporadas,
    ) {}

    /** @param array{destinatario:string, bins:array<int,string>, observacion?:string|null} $datos */
    public function crear(array $datos, User $usuario): DespachoComercialRetorno
    {
        return DB::transaction(function () use ($datos, $usuario): DespachoComercialRetorno {
            $temporadaId = $this->temporadas->obtener()->id;
            $ids = collect($datos['bins'])->unique()->sort()->values();
            if ($ids->count() !== count($datos['bins'])) {
                throw new ConflictoOperacion('Un mismo bin no puede figurar dos veces en el despacho.');
            }

            $bins = BinRetornoPacking::query()->whereIn('id', $ids)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($bins->count() !== $ids->count()) {
                throw new ConflictoOperacion('Uno de los bins seleccionados ya no existe.');
            }

            foreach ($bins as $bin) {
                $this->exigirDisponible($bin, $temporadaId);
            }

            $despacho = DespachoComercialRetorno::create([
                'temporada_id' => $temporadaId,
                'numero' => sprintf('DC-%06d', $this->secuencias->reservarSiguiente('despachos_comerciales_retorno')),
                'destinatario' => trim($datos['destinatario']),
                'estado' => 'borrador',
                'observacion' => trim((string) ($datos['observacion'] ?? '')) ?: null,
                'creado_por_user_id' => $usuario->id,
            ]);

            foreach ($ids as $id) {
                $bin = $bins->get($id);
                $despacho->bins()->create([
                    'bin_retorno_packing_id' => $id,
                    'folio_definitivo' => $bin->folio_definitivo_vigente,
                    'clasificacion' => $bin->nombre_resultado ?: $bin->tipoResultado?->nombre ?: 'Sin clasificación',
                    'kilos_definitivos' => $bin->kilos_totales_definitivos,
                ]);
                $bin->update(['despacho_comercial_id' => $despacho->id]);
            }

            return $despacho->load('bins');
        }, attempts: 3);
    }

    public function confirmar(DespachoComercialRetorno $despacho, string $guia, User $usuario): DespachoComercialRetorno
    {
        return DB::transaction(function () use ($despacho, $guia, $usuario): DespachoComercialRetorno {
            $despacho = DespachoComercialRetorno::query()->lockForUpdate()->findOrFail($despacho->id);
            $this->exigirTemporada($despacho);
            if ($despacho->estado === 'confirmado' && $despacho->numero_guia_sii === $guia) {
                return $despacho->load('bins');
            }
            if ($despacho->estado !== 'borrador') {
                throw new ConflictoOperacion('El despacho ya fue confirmado o cancelado.');
            }
            if (DespachoComercialRetorno::query()
                ->where('temporada_id', $despacho->temporada_id)
                ->where('numero_guia_sii', $guia)->exists()) {
                throw new ConflictoOperacion('El número de guía SII ya está vinculado a otro despacho de la temporada.');
            }

            $detalles = $despacho->bins()->orderBy('bin_retorno_packing_id')->get();
            $bins = BinRetornoPacking::query()->whereIn('id', $detalles->pluck('bin_retorno_packing_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($detalles as $detalle) {
                $bin = $bins->get($detalle->bin_retorno_packing_id);
                if (! $bin || $bin->despacho_comercial_id !== $despacho->id
                    || $bin->estado !== 'regularizado' || $bin->anulado_at !== null
                    || $bin->folio_definitivo_vigente !== $detalle->folio_definitivo
                    || (string) $bin->kilos_totales_definitivos !== (string) $detalle->kilos_definitivos
                    || ($bin->nombre_resultado ?: $bin->tipoResultado?->nombre ?: 'Sin clasificación') !== $detalle->clasificacion) {
                    throw new ConflictoOperacion('Un bin cambió desde la reserva; revise el despacho antes de confirmar.');
                }
            }

            $despacho->update([
                'estado' => 'confirmado',
                'numero_guia_sii' => $guia,
                'confirmado_por_user_id' => $usuario->id,
                'confirmado_at' => now(),
            ]);

            return $despacho->load('bins');
        }, attempts: 3);
    }

    public function cancelar(DespachoComercialRetorno $despacho, User $usuario): DespachoComercialRetorno
    {
        return DB::transaction(function () use ($despacho, $usuario): DespachoComercialRetorno {
            $despacho = DespachoComercialRetorno::query()->lockForUpdate()->findOrFail($despacho->id);
            $this->exigirTemporada($despacho);
            if ($despacho->estado === 'cancelado') {
                return $despacho->load('bins');
            }
            if ($despacho->estado !== 'borrador') {
                throw new ConflictoOperacion('Una salida confirmada con guía SII no se puede cancelar desde FoliOS.');
            }

            BinRetornoPacking::query()->where('despacho_comercial_id', $despacho->id)
                ->update(['despacho_comercial_id' => null]);
            $despacho->update([
                'estado' => 'cancelado',
                'cancelado_por_user_id' => $usuario->id,
                'cancelado_at' => now(),
            ]);

            return $despacho->load('bins');
        }, attempts: 3);
    }

    private function exigirDisponible(BinRetornoPacking $bin, string $temporadaId): void
    {
        if ($bin->temporada_id !== $temporadaId || $bin->estado !== 'regularizado'
            || $bin->anulado_at !== null || $bin->despacho_comercial_id !== null
            || ! $bin->folio_definitivo_vigente || $bin->kilos_totales_definitivos === null
            || ! $bin->nombre_resultado && ! $bin->tipo_resultado_packing_id) {
            throw new ConflictoOperacion('Solo se pueden despachar bins regularizados, disponibles y de la temporada activa.');
        }
    }

    private function exigirTemporada(DespachoComercialRetorno $despacho): void
    {
        if ($despacho->temporada_id !== $this->temporadas->obtener()->id) {
            throw new ConflictoOperacion('Solo se puede operar un despacho de la temporada activa.');
        }
    }
}

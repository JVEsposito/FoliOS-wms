<?php

namespace App\Services\MateriaPrima;

use App\Models\Camara;
use App\Models\EntregaFrutaProceso;
use App\Models\EventoLoteMateriaPrima;
use App\Models\LoteMateriaPrima;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RegistroMovimientosCamaraMp
{
    public const TIPOS = [
        'camara_asignada' => 'Ingreso a cámara',
        'camara_trasladada' => 'Traslado entre cámaras',
        'fruta_entregada_proceso' => 'Salida a Packing',
        'entrega_fruta_proceso_anulada' => 'Anulación de salida a Packing',
        'sublote_packing_ubicado' => 'Ingreso de sublote desde Packing',
    ];

    public function consulta(array $filtros): Builder
    {
        $zona = config('app.operational_timezone', 'America/Santiago');

        return EventoLoteMateriaPrima::query()
            ->whereIn('tipo', array_keys(self::TIPOS))
            ->whereHas('lote', fn (Builder $lote) => $lote
                ->where('temporada_id', app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->when(filled($filtros['lote_id'] ?? null) || filled($filtros['buscar'] ?? null),
                fn (Builder $q) => $this->filtrarLotes($q, $filtros))
            ->when(filled($filtros['tipo'] ?? null), fn (Builder $q) => $q->where('tipo', $filtros['tipo']))
            ->when(filled($filtros['desde'] ?? null), fn (Builder $q) => $q->where('ocurrido_at', '>=',
                CarbonImmutable::parse($filtros['desde'], $zona)->startOfDay()->setTimezone(config('app.timezone'))))
            ->when(filled($filtros['hasta'] ?? null), fn (Builder $q) => $q->where('ocurrido_at', '<',
                CarbonImmutable::parse($filtros['hasta'], $zona)->addDay()->startOfDay()->setTimezone(config('app.timezone'))))
            ->when(filled($filtros['camara_id'] ?? null), function (Builder $q) use ($filtros) {
                $q->where(fn (Builder $c) => $c->where('datos->camara_origen_id', $filtros['camara_id'])
                    ->orWhere('datos->camara_destino_id', $filtros['camara_id'])
                    ->orWhere('datos->camara_id', $filtros['camara_id'])
                    ->orWhere(fn (Builder $a) => $a->where('tipo', 'entrega_fruta_proceso_anulada')
                        ->whereNull('datos->camara_destino_id')
                        ->whereIn('datos->entrega_id', EntregaFrutaProceso::where('camara_id', $filtros['camara_id'])->select('id'))));
            })
            ->with(['lote.cliente', 'lote.recepcion', 'usuario'])
            ->orderByDesc('ocurrido_at')->orderByDesc('created_at')->orderByDesc('id');
    }

    /** Recorre el corte por cursor, sin desplazar páginas al llegar nuevos eventos. */
    public function filasExportacion(array $filtros, ?\DateTimeInterface $corte = null): \Generator
    {
        $consulta = $this->consulta($filtros)->where('created_at', '<=', $corte ?? now());
        $cursor = null;
        do {
            $pagina = (clone $consulta)->cursorPaginate(200, ['*'], 'cursor', $cursor);
            foreach ($this->filas($pagina->getCollection()) as $fila) {
                yield $fila;
            }
            $cursor = $pagina->nextCursor();
        } while ($cursor !== null);
    }

    /** Completa referencias históricas por bloque, conservando las cámaras del evento. */
    public function filas(Collection $eventos): Collection
    {
        $entregas = EntregaFrutaProceso::whereIn('id', $eventos->pluck('datos.entrega_id')->filter()->unique())
            ->get()->keyBy('id');
        $sublotes = DB::table('sublotes_retorno_packing')->whereIn('id', $eventos->pluck('datos.sublote_id')->filter()->unique())
            ->get()->keyBy('id');
        $origenes = DB::table('retorno_packing_entregas as origen')
            ->join('entregas_fruta_proceso as entrega', 'entrega.id', '=', 'origen.entrega_fruta_proceso_id')
            ->join('lotes_materia_prima as lote', 'lote.id', '=', 'entrega.lote_materia_prima_id')
            ->join('clientes as cliente', 'cliente.id', '=', 'lote.cliente_id')
            ->whereIn('origen.retorno_packing_id', $eventos->pluck('datos.retorno_id')->filter()->unique())
            ->get(['origen.retorno_packing_id', 'lote.numero_lote', 'lote.csg_snapshot', 'lote.variedad_snapshot', 'cliente.nombre as cliente'])
            ->groupBy('retorno_packing_id');
        $ids = $eventos->flatMap(fn ($e) => collect($e->datos)->only(['camara_id', 'camara_origen_id', 'camara_destino_id'])->values())
            ->merge($entregas->pluck('camara_id'))->filter()->unique();
        $camaras = Camara::whereIn('id', $ids)->get(['id', 'codigo', 'nombre'])->keyBy('id');

        return $eventos->map(function (EventoLoteMateriaPrima $evento) use ($entregas, $sublotes, $camaras, $origenes) {
            $datos = $evento->datos ?? [];
            $lote = $evento->lote;
            $entrega = $entregas->get($datos['entrega_id'] ?? '');
            $sublote = $sublotes->get($datos['sublote_id'] ?? '');
            $entrada = in_array($evento->tipo, ['camara_asignada', 'sublote_packing_ubicado'], true);
            $salida = $evento->tipo === 'fruta_entregada_proceso';
            $anulacion = $evento->tipo === 'entrega_fruta_proceso_anulada';
            $origenId = $datos['camara_origen_id'] ?? ($salida ? ($datos['camara_id'] ?? $entrega?->camara_id) : null);
            $destinoId = $datos['camara_destino_id'] ?? ($entrada ? ($datos['camara_id'] ?? null) : ($anulacion ? $entrega?->camara_id : null));
            $origen = $this->camara($origenId, $datos, 'origen', $camaras);
            $destino = $this->camara($destinoId, $datos, 'destino', $camaras);
            $linea = $datos['linea_proceso'] ?? $entrega?->linea_proceso;
            $packing = 'Packing'.(filled($linea) ? ' · '.$linea : '');
            $cantidad = $datos['cantidad_envases'] ?? $sublote?->cantidad_bins ?? $entrega?->cantidad_envases
                ?? ($evento->tipo === 'camara_asignada' ? $lote->cantidad_envases_primarios : null);
            $fuentes = $origenes->get($datos['retorno_id'] ?? '', collect());
            $lotesOrigen = $fuentes
                ->map(fn ($o) => $o->numero_lote.' · '.$o->cliente)->unique()->values();

            return [
                'id' => $evento->id, 'lote_id' => $lote->id, 'tipo' => $evento->tipo,
                'movimiento' => self::TIPOS[$evento->tipo], 'ocurrido_at' => $evento->ocurrido_at?->toAtomString(),
                'fecha' => $evento->ocurrido_at?->copy()->setTimezone(config('app.operational_timezone', 'America/Santiago'))->format('Y-m-d H:i:s'),
                'numero_lote' => $lote->numero_lote, 'numero_sublote' => $datos['numero_sublote'] ?? $sublote?->numero_sublote,
                'lotes_origen' => $lotesOrigen->implode(' / '),
                'cliente' => $lote->cliente?->nombre, 'recepcion' => $lote->recepcion?->numero_recepcion,
                'csg' => $fuentes->pluck('csg_snapshot')->filter()->unique()->implode(' / ') ?: $lote->csg_snapshot,
                'variedad' => $fuentes->pluck('variedad_snapshot')->filter()->unique()->implode(' / ') ?: $lote->variedad_snapshot,
                'cantidad_envases' => $cantidad !== null ? (int) $cantidad : null,
                'tipo_envase' => $datos['tipo_envase'] ?? ($sublote ? 'bins' : $lote->envase_primario->value),
                'camara_origen_id' => $origenId, 'camara_destino_id' => $destinoId,
                'origen' => $origen ?? ($anulacion || $evento->tipo === 'sublote_packing_ubicado'
                    ? $packing : ($datos['origen_operacional'] ?? ($lote->requiere_hidrocooler ? 'Hidrocooler' : 'Recepción MP'))),
                'destino' => $destino ?? ($salida ? $packing : 'Sin cámara registrada'),
                'usuario' => $datos['usuario_nombre'] ?? $evento->usuario?->name ?? 'Sistema',
                'observacion' => $datos['motivo'] ?? $datos['observacion'] ?? $entrega?->observacion,
                'es_correccion' => $anulacion,
                'detalle' => $anulacion ? 'Corrección del registro; no acredita un traslado físico.'
                    : ($salida && $entrega?->anulado_at !== null ? 'Salida anulada: se conserva el registro original.'
                        : ($lotesOrigen->count() > 1 ? 'Cantidad del sublote completo; sin reparto supuesto entre lotes de origen.'
                            : ($cantidad === null ? 'Cantidad no registrada en el evento histórico.' : null))),
            ];
        });
    }

    private function filtrarLotes(Builder $eventos, array $filtros): void
    {
        $lotes = LoteMateriaPrima::query()
            ->where('temporada_id', app(ServicioTemporadaActiva::class)->buscar()?->id)
            ->when(filled($filtros['lote_id'] ?? null), fn (Builder $q) => $q->whereKey($filtros['lote_id']))
            ->when(filled($filtros['buscar'] ?? null), function (Builder $q) use ($filtros) {
                $valor = '%'.$filtros['buscar'].'%';
                $q->where(fn (Builder $b) => $b->where('numero_lote', 'like', $valor)->orWhere('csg_snapshot', 'like', $valor)
                    ->orWhereHas('cliente', fn (Builder $c) => $c->where('nombre', 'like', $valor)));
            })->select('id');
        $eventos->where(fn (Builder $q) => $q->whereIn('lote_materia_prima_id', clone $lotes)
            ->orWhere(fn (Builder $r) => $r->where('tipo', 'sublote_packing_ubicado')
                ->whereIn('datos->retorno_id', DB::table('retorno_packing_entregas as origen')
                    ->join('entregas_fruta_proceso as entrega', 'entrega.id', '=', 'origen.entrega_fruta_proceso_id')
                    ->whereIn('entrega.lote_materia_prima_id', clone $lotes)->select('origen.retorno_packing_id'))));
    }

    private function camara(?string $id, array $datos, string $lado, Collection $camaras): ?string
    {
        if (! $id) {
            return null;
        }
        $camara = $camaras->get($id);
        $codigo = $datos['camara_'.$lado.'_codigo'] ?? $datos['camara_codigo'] ?? $camara?->codigo;
        $nombre = $datos['camara_'.$lado.'_nombre'] ?? $datos['camara_nombre'] ?? $camara?->nombre;

        return implode(' · ', array_filter([$codigo, $nombre])) ?: 'Cámara '.$id;
    }
}

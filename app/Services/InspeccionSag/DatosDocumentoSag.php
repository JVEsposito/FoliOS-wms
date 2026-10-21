<?php

namespace App\Services\InspeccionSag;

use App\Models\Folio;
use App\Models\LoteInspeccionSag;
use App\Models\Repaletizaje;
use App\Services\Temporadas\ServicioTemporadaActiva;
use DomainException;

class DatosDocumentoSag
{
    public function folio(string $numero): array
    {
        $temporada = app(ServicioTemporadaActiva::class)->obtener();
        $folio = Folio::query()->where('temporada_id', $temporada->id)->where('numero_folio', $numero)->firstOrFail();

        return $this->linea($folio);
    }

    public function linea(Folio $folio, ?string $excluirLote = null): array
    {
        $folio->loadMissing(['condicionSag', 'autorizacionesSagActivas', 'inspeccionesSag.lote']);
        $datos = $folio->datos_externos ?? [];
        $actividad = $folio->inspeccionesSag->filter(fn ($a) => $a->resuelto_at !== null && $a->lote_inspeccion_sag_id !== $excluirLote)->sortByDesc('resuelto_at')->first()?->lote;

        return [
            'folio_id' => $folio->id, 'folio' => $folio->numero_folio, 'especie' => $datos['especie'] ?? '',
            'variedad' => $folio->variedad ?? '', 'csg' => $datos['csg'] ?? '', 'csp' => $datos['csp'] ?? '',
            'cliente' => $folio->exportadora ?? '', 'cantidad' => (int) ($datos['cantidad_cajas'] ?? 0),
            'envase' => $datos['envase'] ?? 'CAJAS', 'condicion' => 'FRESCO', 'producto' => 'FRUTO',
            'lote' => $datos['lote'] ?? '',
            'tipo_actividad' => $actividad?->tipo->etiqueta() ?? '',
            'numero_actividad' => $actividad?->numero_inspeccion_sag ?: ($actividad?->codigo ?? ''),
            'con_condicion' => $folio->condicion_sag_id !== null || $folio->autorizacionesSagActivas->isNotEmpty(),
            'destinos_anteriores' => $folio->autorizacionesSagActivas->map(fn ($a) => $a->destino_snapshot['nombre'] ?? $a->destino_snapshot['codigo'] ?? '')->unique()->values()->all(),
        ];
    }

    public function repaletizaje(string $id): array
    {
        $repa = Repaletizaje::with(['detalles.folioOrigen', 'folioResultante', 'resultados.folio'])
            ->where(fn ($q) => $q->where('id', $id)->orWhere('codigo', $id))->firstOrFail();
        $temporada = app(ServicioTemporadaActiva::class)->obtener();
        if ($repa->temporadaOperacionalId() !== $temporada->id || $repa->estado !== 'confirmado') {
            throw new DomainException('Selecciona una repa confirmada de la temporada activa.');
        }
        $originales = $repa->detalles->map(function ($d): array {
            $linea = $this->linea($d->folioOrigen);
            $antes = $d->snapshot_antes['atributos'] ?? [];
            $datos = $antes['datos_externos'] ?? [];

            return [...$linea, 'cantidad' => $d->cajas_aportadas, 'especie' => $datos['especie'] ?? $linea['especie']];
        })->values()->all();
        $resultados = $repa->resultados->isNotEmpty()
            ? $repa->resultados->map(fn ($r) => ['folio_id' => $r->folio_id, 'folio' => $r->folio->numero_folio, 'cantidad' => $r->cantidad_resultante])->all()
            : [['folio_id' => $repa->folio_resultante_id, 'folio' => $repa->folioResultante->numero_folio, 'cantidad' => $repa->cantidad_resultante]];

        return ['repaletizaje_id' => $repa->id, 'fecha_actividad' => $repa->fecha_operacional?->toDateString() ?? $repa->confirmado_at->toDateString(),
            'solicitud' => $repa->codigo, 'originales' => $originales, 'resultados' => $resultados];
    }

    public function cambioMercado(string $referencia): array
    {
        $lote = LoteInspeccionSag::with(['folios.folio', 'destinos'])
            ->where(fn ($q) => $q->where('id', $referencia)->orWhere('codigo', $referencia))->firstOrFail();
        if ($lote->temporada_id !== app(ServicioTemporadaActiva::class)->obtener()->id || $lote->tipo->value !== 'cambio_mercado' || $lote->estado->value === 'cancelado') {
            throw new DomainException('Selecciona un lote de cambio de mercado vigente de la temporada activa.');
        }
        $destino = $lote->destinos->map(fn ($d) => $d->destino_snapshot['nombre'] ?? $d->destino_snapshot['codigo'] ?? '')->filter()->unique()->implode(' - ');
        $kilos = app(KilosDocumentoSag::class);
        $lineas = $lote->folios->map(function ($asignacion) use ($lote, $destino, $kilos): array {
            $linea = $this->linea($asignacion->folio, $lote->id);
            $anteriores = collect($asignacion->estado_sag_anterior['destinos'] ?? [])
                ->map(fn ($d) => is_string($d) ? $d : ($d['destino_snapshot']['nombre'] ?? $d['nombre'] ?? $d['destino_snapshot']['codigo'] ?? $d['codigo'] ?? ''))
                ->filter()->unique()->implode(' - ');

            return [...$linea, 'kilos' => $kilos->calcular($asignacion->folio), 'mercados_anteriores' => $anteriores, 'nuevo_destino' => $destino];
        })->values()->all();

        return ['lote_inspeccion_sag_id' => $lote->id, 'solicitud' => $lote->codigo,
            'fecha_actividad' => $lote->created_at->timezone(config('app.operational_timezone', 'America/Santiago'))->toDateString(), 'lineas' => $lineas];
    }

    public function preparar(string $tipo, array $datos): array
    {
        if ($tipo === 'despacho') {
            return app(DatosDespachoSag::class)->preparar($datos);
        }
        if ($tipo === 'cambio_mercado') {
            $fuente = $this->cambioMercado($datos['lote_inspeccion_sag_id']);
            foreach ($fuente['lineas'] as &$linea) {
                $id = $linea['folio_id'];
                $actividad = $datos['actividades'][$id] ?? [];
                $linea['tipo_actividad'] = $actividad['tipo'] ?? $linea['tipo_actividad'];
                $linea['numero_actividad'] = $actividad['numero'] ?? $linea['numero_actividad'];
                $linea['kilos'] = isset($datos['kilos'][$id]) ? (float) $datos['kilos'][$id] : $linea['kilos'];
                $linea['mercados_anteriores'] = $datos['mercados_anteriores'][$id] ?? $linea['mercados_anteriores'];
            }

            return $fuente;
        }
        if ($tipo === 'repaletizaje') {
            $fuente = $this->repaletizaje($datos['repaletizaje_id']);
            if (! collect($fuente['originales'])->contains('con_condicion', true)) {
                throw new DomainException('Esta repa no tiene fruta con condición SAG. Utiliza su registro RRPL-01.');
            }
            foreach ($fuente['originales'] as &$linea) {
                $actividad = $datos['actividades'][$linea['folio_id']] ?? [];
                $linea['tipo_actividad'] = $actividad['tipo'] ?? $linea['tipo_actividad'];
                $linea['numero_actividad'] = $actividad['numero'] ?? $linea['numero_actividad'];
            }

            return $fuente;
        }
        if ($tipo === 'anulacion') {
            $temporada = app(ServicioTemporadaActiva::class)->obtener();
            $lineas = [];
            foreach ($datos['folios'] ?? [] as $seleccion) {
                $folio = Folio::findOrFail($seleccion['folio_id']);
                $linea = $this->linea($folio);
                if ($folio->temporada_id !== $temporada->id || ! $linea['con_condicion']) {
                    throw new DomainException('Selecciona folios de la temporada activa con condición SAG.');
                }
                if ($seleccion['cantidad'] > $linea['cantidad']) {
                    throw new DomainException('La cantidad indicada supera las cajas del folio '.$folio->numero_folio.'.');
                }
                $lineas[] = [...$linea, 'cantidad' => $seleccion['cantidad'], 'causal' => $seleccion['causal'],
                    'tipo_actividad' => $seleccion['tipo_actividad'] ?? $linea['tipo_actividad'],
                    'numero_actividad' => $seleccion['numero_actividad'] ?? $linea['numero_actividad']];
            }
            if ($lineas === [] && (int) ($datos['encabezado']['certificados'] ?? 0) === 0) {
                throw new DomainException('Indica productos o certificados para devolver.');
            }

            return ['lineas' => $lineas];
        }

        throw new DomainException('Tipo de documento SAG no disponible.');
    }
}

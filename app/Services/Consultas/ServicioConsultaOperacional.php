<?php

namespace App\Services\Consultas;

use App\Models\Folio;
use App\Models\LoteMateriaPrima;
use App\Models\ProductorCsg;
use App\Models\RecepcionRomana;
use App\Models\TrazabilidadFolioOrigen;
use App\Services\Temporadas\ServicioTemporadaActiva;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;

class ServicioConsultaOperacional
{
    /** La búsqueda es una vista rápida: no reemplaza el alcance completo de Trazabilidad de lotes. */
    public const LIMITE_POR_CATEGORIA = 20;

    /** @return array<string, mixed> */
    public function buscar(string $termino, string $tipo = 'todos'): array
    {
        $patron = '%'.trim($termino).'%';

        return [
            'termino' => trim($termino),
            'limite_por_categoria' => self::LIMITE_POR_CATEGORIA,
            'folios' => in_array($tipo, ['todos', 'folios'], true)
                ? $this->buscarFolios($termino)
                : [],
            'lotes' => in_array($tipo, ['todos', 'lotes'], true)
                ? $this->buscarLotes($patron)
                : [],
            'productores' => in_array($tipo, ['todos', 'productores'], true)
                ? $this->buscarProductores($patron)
                : [],
            'recepciones' => in_array($tipo, ['todos', 'recepciones'], true)
                ? $this->buscarRecepciones($patron)
                : [],
        ];
    }

    /**
     * Folios por número exacto (cualquier temporada), por fragmento del número (por ejemplo
     * los últimos dígitos) en la temporada activa, o por lote MP / proceso de packing de su
     * composición. Cada criterio usa un índice: con cientos de miles de folios por temporada
     * no se recorre la tabla ni se busca dentro del JSON de datos externos.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buscarFolios(string $termino): array
    {
        $termino = trim($termino);
        $temporadaActiva = app(ServicioTemporadaActiva::class)->buscar()?->id;
        $ids = Folio::query()->where('numero_folio', $termino)->pluck('id');

        if ($temporadaActiva !== null) {
            $ids = $ids
                ->merge(Folio::query()
                    ->where('temporada_id', $temporadaActiva)
                    ->where('numero_folio', 'like', '%'.$termino.'%')
                    ->orderByDesc('fecha_ingreso')
                    ->limit(self::LIMITE_POR_CATEGORIA)
                    ->pluck('id'))
                ->merge(TrazabilidadFolioOrigen::query()
                    ->where('temporada_id', $temporadaActiva)
                    ->where(fn (Builder $consulta) => $consulta
                        ->where('numero_lote_materia_prima', mb_strtoupper($termino))
                        ->orWhere('numero_proceso_packing', mb_strtoupper($termino)))
                    ->limit(self::LIMITE_POR_CATEGORIA)
                    ->pluck('folio_id'));
        }

        return Folio::query()
            ->whereIn('id', $ids->unique()->values()->all())
            ->with(['temporada', 'ubicacionActual.posicion.camara'])
            ->latest('fecha_ingreso')
            ->limit(self::LIMITE_POR_CATEGORIA)
            ->get()
            ->map(function (Folio $folio): array {
                $posicion = $folio->ubicacionActual?->posicion;

                return [
                    'id' => $folio->id,
                    'numero' => $folio->numero_folio,
                    'tipo_bulto' => $this->valor($folio->tipo_bulto),
                    'estado' => $this->valor($folio->estado_operacional),
                    'condicion_termica' => $this->valor($folio->condicion_termica),
                    'habilitacion_almacenamiento' => $this->valor($folio->habilitacion_almacenamiento),
                    'variedad' => $folio->variedad,
                    'calibre' => $folio->calibre,
                    'marca' => $folio->marca,
                    'exportadora' => $folio->exportadora,
                    'fecha_ingreso' => $folio->fecha_ingreso?->toIso8601String(),
                    'temporada' => $folio->temporada?->codigo,
                    'ubicacion' => $posicion ? [
                        'camara' => $posicion->camara?->codigo,
                        'posicion' => $posicion->etiqueta,
                    ] : null,
                ];
            })
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function buscarLotes(string $patron): array
    {
        return LoteMateriaPrima::query()
            ->where(function (Builder $consulta) use ($patron): void {
                $consulta->where('numero_lote', 'like', $patron)
                    ->orWhere('csg_snapshot', 'like', $patron)
                    ->orWhere('sdp', 'like', $patron)
                    ->orWhere('ggn', 'like', $patron)
                    ->orWhere('predio', 'like', $patron)
                    ->orWhere('especie_snapshot', 'like', $patron)
                    ->orWhere('variedad_snapshot', 'like', $patron)
                    ->orWhereHas('recepcion', fn (Builder $recepcion) => $recepcion
                        ->where('numero_recepcion', 'like', $patron)
                        ->orWhere('numero_guia_despacho', 'like', $patron));
            })
            ->with(['temporada', 'cliente', 'recepcion', 'asignacionCamara.camara', 'hidrocooler'])
            ->latest()
            ->limit(self::LIMITE_POR_CATEGORIA)
            ->get()
            ->map(fn (LoteMateriaPrima $lote): array => [
                'id' => $lote->id,
                'numero' => $lote->numero_lote,
                'estado' => $this->valor($lote->estado),
                'cliente' => $lote->cliente?->nombre,
                'recepcion' => $lote->recepcion?->numero_recepcion,
                'guia' => $lote->recepcion?->numero_guia_despacho,
                'csg' => $lote->csg_snapshot,
                'sdp' => $lote->sdp,
                'ggn' => $lote->ggn,
                'predio' => $lote->predio,
                'especie' => $lote->especie_snapshot,
                'variedad' => $lote->variedad_snapshot,
                'kilos_netos' => (float) $lote->kilos_netos_confirmados,
                'temporada' => $lote->temporada?->codigo,
                'camara' => $lote->asignacionCamara?->camara?->codigo,
                'hidrocooler' => $lote->hidrocooler ? [
                    'estado' => $this->valor($lote->hidrocooler->estado),
                    'equipo' => $lote->hidrocooler->equipo,
                ] : null,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function buscarProductores(string $patron): array
    {
        return ProductorCsg::query()
            ->where(function (Builder $consulta) use ($patron): void {
                $consulta->where('codigo', 'like', $patron)
                    ->orWhere('rut', 'like', $patron)
                    ->orWhere('razon_social', 'like', $patron)
                    ->orWhere('predio', 'like', $patron)
                    ->orWhere('direccion', 'like', $patron);
            })
            ->with('clientes')
            ->latest('ultima_verificacion_at')
            ->limit(self::LIMITE_POR_CATEGORIA)
            ->get()
            ->map(fn (ProductorCsg $productor): array => [
                'id' => $productor->id,
                'codigo' => $productor->codigo,
                'rut' => $productor->rut,
                'razon_social' => $productor->razon_social,
                'predio' => $productor->predio,
                'estado_sag' => $productor->estado_sag,
                'estado_asociacion' => $productor->estado_asociacion,
                'clientes' => $productor->clientes
                    ->filter(fn ($cliente): bool => (bool) $cliente->pivot->activo)
                    ->pluck('nombre')
                    ->values()
                    ->all(),
                'ultima_verificacion_at' => $productor->ultima_verificacion_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function buscarRecepciones(string $patron): array
    {
        return RecepcionRomana::query()
            ->where(function (Builder $consulta) use ($patron): void {
                $consulta->where('numero_recepcion', 'like', $patron)
                    ->orWhere('numero_guia_despacho', 'like', $patron)
                    ->orWhere('patente_camion', 'like', $patron)
                    ->orWhere('patente_carro', 'like', $patron)
                    ->orWhere('rut_conductor', 'like', $patron)
                    ->orWhere('nombre_conductor', 'like', $patron)
                    ->orWhere('cliente_nombre_snapshot', 'like', $patron);
            })
            ->with(['temporada', 'cliente'])
            ->withCount('lotesMateriaPrima')
            ->latest('ingreso_at')
            ->limit(self::LIMITE_POR_CATEGORIA)
            ->get()
            ->map(fn (RecepcionRomana $recepcion): array => [
                'id' => $recepcion->id,
                'numero' => $recepcion->numero_recepcion,
                'guia' => $recepcion->numero_guia_despacho,
                'estado' => $this->valor($recepcion->estado),
                'cliente' => $recepcion->cliente?->nombre ?? $recepcion->cliente_nombre_snapshot,
                'patente_camion' => $recepcion->patente_camion,
                'conductor' => $recepcion->nombre_conductor,
                'rut_conductor' => $recepcion->rut_conductor,
                'peso_neto' => $recepcion->peso_neto !== null ? (float) $recepcion->peso_neto : null,
                'ingreso_at' => $recepcion->ingreso_at?->toIso8601String(),
                'temporada' => $recepcion->temporada?->codigo,
                'lotes' => $recepcion->lotes_materia_prima_count,
            ])
            ->all();
    }

    private function valor(mixed $valor): mixed
    {
        return $valor instanceof BackedEnum ? $valor->value : $valor;
    }
}

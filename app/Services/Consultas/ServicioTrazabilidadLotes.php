<?php

namespace App\Services\Consultas;

use App\Models\EntregaFrutaProceso;
use App\Models\Folio;
use App\Models\LoteMateriaPrima;
use App\Models\Temporada;
use App\Models\TrazabilidadFolioOrigen;
use App\Services\Validacion\ProyeccionTrazabilidadFolio;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Responde en ambos sentidos: qué folios contienen un lote o proceso de packing
 * (retiro de mercado) y de qué lotes y recepciones proviene un folio (auditoría).
 */
class ServicioTrazabilidadLotes
{
    public const POR_PAGINA = 50;

    /** @var array<string, array<int, string>> */
    private array $idsAfectados = [];

    /**
     * El resumen cuenta todos los folios y cajas afectados; el listado se pagina. Para un
     * retiro de mercado se usa la exportación, que incluye todos los folios sin límite.
     *
     * @return array<string, mixed>
     */
    public function consultar(string $termino, Temporada $temporada, int $pagina = 1): array
    {
        $codigo = ProyeccionTrazabilidadFolio::normalizarCodigo($termino) ?? '';
        $totalFolios = $this->foliosAfectados($codigo, $temporada)->count();
        $paginas = max(1, (int) ceil($totalFolios / self::POR_PAGINA));
        $pagina = min(max(1, $pagina), $paginas);

        $folios = $this->foliosAfectados($codigo, $temporada)
            ->with($this->relacionesFolio())
            ->orderBy('numero_folio')
            ->forPage($pagina, self::POR_PAGINA)
            ->get();
        $lineas = $this->lineasPorFolio($folios->modelKeys());

        $lotesConsultados = LoteMateriaPrima::query()
            ->where('temporada_id', $temporada->id)
            ->whereRaw('UPPER(TRIM(numero_lote)) = ?', [$codigo])
            ->with(['recepcion', 'cliente', 'temporada'])
            ->get();

        return [
            'termino' => $codigo,
            'temporada' => $this->temporada($temporada),
            'temporadas' => Temporada::query()
                ->productivas()
                ->orderByDesc('activa')
                ->orderByDesc('codigo')
                ->get()
                ->map(fn (Temporada $opcion): array => $this->temporada($opcion))
                ->all(),
            'lotes' => $lotesConsultados->map(fn (LoteMateriaPrima $lote): array => $this->lote($lote))->all(),
            'entregas_proceso' => $this->entregasProceso($codigo, $temporada),
            'folios' => $folios->map(fn (Folio $folio): array => $this->folio(
                $folio,
                $lineas->get($folio->id, collect()),
                $codigo,
            ))->all(),
            'resumen' => [
                'folios' => $totalFolios,
                'folios_activos' => $this->foliosAfectados($codigo, $temporada)->where('activo', true)->count(),
                'cajas_coincidentes' => (int) $this->lineasCoincidentes($codigo, $temporada)->sum('cantidad_cajas'),
                'lineas_sin_lote_verificado' => $this->lineasCoincidentes($codigo, $temporada)
                    ->where('numero_lote_materia_prima', $codigo)
                    ->whereNull('lote_materia_prima_id')
                    ->count(),
            ],
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => self::POR_PAGINA,
                'paginas' => $paginas,
                'total' => $totalFolios,
            ],
        ];
    }

    /** @return array<int, array{clave:string,titulo:string,ancho?:int,tipo?:string}> */
    public function columnasExportacion(): array
    {
        return [
            ['clave' => 'folio', 'titulo' => 'Folio', 'ancho' => 18],
            ['clave' => 'activo', 'titulo' => 'Activo', 'ancho' => 9],
            ['clave' => 'estado', 'titulo' => 'Estado', 'ancho' => 18],
            ['clave' => 'temporada', 'titulo' => 'Temporada', 'ancho' => 12],
            ['clave' => 'exportadora', 'titulo' => 'Cliente', 'ancho' => 24],
            ['clave' => 'variedad', 'titulo' => 'Variedad', 'ancho' => 16],
            ['clave' => 'calibre', 'titulo' => 'Calibre', 'ancho' => 10],
            ['clave' => 'camara', 'titulo' => 'Cámara', 'ancho' => 12],
            ['clave' => 'posicion', 'titulo' => 'Posición', 'ancho' => 14],
            ['clave' => 'carga', 'titulo' => 'Carga', 'ancho' => 14],
            ['clave' => 'estado_carga', 'titulo' => 'Estado carga', 'ancho' => 16],
            ['clave' => 'csg', 'titulo' => 'CSG', 'ancho' => 12],
            ['clave' => 'predio', 'titulo' => 'Predio', 'ancho' => 22],
            ['clave' => 'fecha_embalaje', 'titulo' => 'Fecha embalaje', 'ancho' => 14],
            ['clave' => 'lote_materia_prima', 'titulo' => 'Lote MP (etiqueta)', 'ancho' => 18],
            ['clave' => 'lote_materia_prima_actual', 'titulo' => 'Lote MP actual', 'ancho' => 18],
            ['clave' => 'recepcion', 'titulo' => 'Recepción MP verificada', 'ancho' => 22],
            ['clave' => 'proceso_packing', 'titulo' => 'Proceso packing', 'ancho' => 16],
            ['clave' => 'cantidad_cajas', 'titulo' => 'Cajas', 'ancho' => 10, 'tipo' => 'numero'],
            ['clave' => 'coincide', 'titulo' => 'Línea buscada', 'ancho' => 13],
        ];
    }

    /**
     * Una fila por línea de composición de cada folio afectado, sin límite de folios.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function filasExportacion(string $termino, Temporada $temporada): LazyCollection
    {
        $codigo = ProyeccionTrazabilidadFolio::normalizarCodigo($termino) ?? '';

        return $this->foliosAfectados($codigo, $temporada)
            ->with($this->relacionesFolio())
            ->lazyById(200)
            ->chunk(200)
            ->flatMap(function (LazyCollection $folios) use ($codigo): array {
                $lineas = $this->lineasPorFolio($folios->map(fn (Folio $folio): string => $folio->id)->all());
                $filas = [];

                foreach ($folios as $folio) {
                    $base = $this->folio($folio, collect(), $codigo);
                    $lineasFolio = $lineas->get($folio->id, collect());
                    foreach ($lineasFolio->isEmpty() ? [null] : $lineasFolio as $linea) {
                        $filas[] = [
                            'folio' => $base['numero'],
                            'activo' => $base['activo'] ? 'Sí' : 'No',
                            'estado' => $base['estado'],
                            'temporada' => $base['temporada'],
                            'exportadora' => $base['exportadora'],
                            'variedad' => $base['variedad'],
                            'calibre' => $base['calibre'],
                            'camara' => $base['ubicacion']['camara'] ?? null,
                            'posicion' => $base['ubicacion']['posicion'] ?? null,
                            'carga' => $base['carga']['codigo'] ?? null,
                            'estado_carga' => $base['carga']['estado'] ?? null,
                            'csg' => $linea?->csg,
                            'predio' => $linea?->predio,
                            'fecha_embalaje' => $linea?->fecha_embalaje?->toDateString(),
                            'lote_materia_prima' => $linea?->numero_lote_materia_prima,
                            'lote_materia_prima_actual' => $linea?->loteMateriaPrima?->numero_lote,
                            'recepcion' => $linea?->loteMateriaPrima?->recepcion?->numero_recepcion,
                            'proceso_packing' => $linea?->numero_proceso_packing,
                            'cantidad_cajas' => $linea?->cantidad_cajas,
                            'coincide' => $linea && $this->coincide($linea, $codigo) ? 'Sí' : 'No',
                        ];
                    }
                }

                return $filas;
            });
    }

    /**
     * Folios de la temporada cuya composición informa el lote o proceso buscado, o cuyo
     * número coincide. Los números de lote se repiten entre temporadas: nunca se mezclan.
     */
    private function foliosAfectados(string $codigo, Temporada $temporada): Builder
    {
        return Folio::query()->whereIn('id', $this->idsFoliosAfectados($codigo, $temporada));
    }

    /**
     * Los IDs se resuelven primero con los índices de la trazabilidad y del número de folio.
     * Un «id IN (subconsulta) OR numero_folio = ?» obliga a MySQL a recorrer todos los folios
     * (segundos con 150.000 folios por temporada); se calculan una vez por consulta.
     *
     * @return array<int, string>
     */
    private function idsFoliosAfectados(string $codigo, Temporada $temporada): array
    {
        return $this->idsAfectados[$temporada->id.'|'.$codigo] ??= $this->lineasCoincidentes($codigo, $temporada)
            ->distinct()
            ->pluck('folio_id')
            ->merge(Folio::query()
                ->where('numero_folio', $codigo)
                ->where('temporada_id', $temporada->id)
                ->pluck('id'))
            ->unique()
            ->values()
            ->all();
    }

    private function lineasCoincidentes(string $codigo, Temporada $temporada): Builder
    {
        return TrazabilidadFolioOrigen::query()
            ->where('temporada_id', $temporada->id)
            ->where(fn (Builder $consulta) => $consulta
                ->where('numero_lote_materia_prima', $codigo)
                ->orWhere('numero_proceso_packing', $codigo)
                ->orWhereHas('loteMateriaPrima', fn (Builder $lote) => $lote->where('temporada_id', $temporada->id)
                    ->whereRaw('UPPER(TRIM(numero_lote)) = ?', [$codigo])));
    }

    /** @param array<int, string> $folioIds */
    private function lineasPorFolio(array $folioIds): Collection
    {
        return TrazabilidadFolioOrigen::query()
            ->whereIn('folio_id', $folioIds)
            ->with('loteMateriaPrima.recepcion')
            ->orderBy('created_at')
            ->get()
            ->groupBy('folio_id');
    }

    /** @return array<int|string, mixed> */
    private function relacionesFolio(): array
    {
        return [
            'temporada',
            'ubicacionActual.posicion.camara',
            'asignacionesCarga' => fn ($consulta) => $consulta->latest()->with('carga'),
        ];
    }

    /** @return array<string, mixed> */
    private function folio(Folio $folio, Collection $lineas, string $codigo): array
    {
        $posicion = $folio->ubicacionActual?->posicion;
        $carga = $folio->asignacionesCarga->first()?->carga;

        return [
            'id' => $folio->id,
            'numero' => $folio->numero_folio,
            'activo' => (bool) $folio->activo,
            'estado' => $this->valor($folio->estado_operacional),
            'temporada' => $folio->temporada?->codigo,
            'variedad' => $folio->variedad,
            'calibre' => $folio->calibre,
            'exportadora' => $folio->exportadora,
            'ubicacion' => $posicion ? [
                'camara' => $posicion->camara?->codigo,
                'posicion' => $posicion->etiqueta,
            ] : null,
            'carga' => $carga ? [
                'codigo' => $carga->codigo,
                'estado' => $this->valor($carga->estado),
            ] : null,
            'lineas' => $lineas->map(fn (TrazabilidadFolioOrigen $linea): array => [
                'csg' => $linea->csg,
                'predio' => $linea->predio,
                'fecha_embalaje' => $linea->fecha_embalaje?->toDateString(),
                'lote_materia_prima' => $linea->numero_lote_materia_prima,
                'lote_materia_prima_actual' => $linea->loteMateriaPrima?->numero_lote,
                'lote_verificado' => $linea->lote_materia_prima_id !== null,
                'recepcion' => $linea->loteMateriaPrima?->recepcion?->numero_recepcion,
                'proceso_packing' => $linea->numero_proceso_packing,
                'cantidad_cajas' => $linea->cantidad_cajas,
                'coincide' => $this->coincide($linea, $codigo),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function lote(LoteMateriaPrima $lote): array
    {
        return [
            'id' => $lote->id,
            'numero' => $lote->numero_lote,
            'estado' => $this->valor($lote->estado),
            'cliente' => $lote->cliente?->nombre,
            'temporada' => $lote->temporada?->codigo,
            'recepcion' => $lote->recepcion?->numero_recepcion,
            'guia' => $lote->recepcion?->numero_guia_despacho,
            'csg' => $lote->csg_snapshot,
            'predio' => $lote->predio,
            'variedad' => $lote->variedad_snapshot,
            'fecha_cosecha' => $lote->fecha_cosecha?->toDateString(),
            'kilos_netos' => (float) $lote->kilos_netos_confirmados,
        ];
    }

    /**
     * El proceso de packing impreso en el pallet es el número de orden de Fruta a Proceso:
     * lotes MP entregados a esa orden en la temporada, sin las entregas anuladas.
     *
     * @return array<int, array<string, mixed>>
     */
    private function entregasProceso(string $codigo, Temporada $temporada): array
    {
        return EntregaFrutaProceso::query()
            ->whereRaw('UPPER(TRIM(numero_orden)) = ?', [$codigo])
            ->whereNull('anulado_at')
            ->whereHas('lote', fn (Builder $lote) => $lote->where('temporada_id', $temporada->id))
            ->with(['lote.recepcion', 'lote.cliente'])
            ->orderBy('entregado_at')
            ->get()
            ->map(fn (EntregaFrutaProceso $entrega): array => [
                'numero_orden' => $entrega->numero_orden,
                'entregado_at' => $entrega->entregado_at?->toAtomString(),
                'linea_proceso' => $entrega->linea_proceso,
                'turno' => $entrega->turno,
                'lote' => $entrega->lote?->numero_lote,
                'cliente' => $entrega->lote?->cliente?->nombre,
                'recepcion' => $entrega->lote?->recepcion?->numero_recepcion,
                'envases' => $entrega->cantidad_envases,
                'kilos' => $entrega->kilos_enviados !== null ? (float) $entrega->kilos_enviados : null,
            ])
            ->all();
    }

    /** @return array{id: string, codigo: string, nombre: ?string, activa: bool} */
    private function temporada(Temporada $temporada): array
    {
        return [
            'id' => $temporada->id,
            'codigo' => $temporada->codigo,
            'nombre' => $temporada->nombre,
            'activa' => (bool) $temporada->activa,
        ];
    }

    private function coincide(TrazabilidadFolioOrigen $linea, string $codigo): bool
    {
        return $linea->numero_lote_materia_prima === $codigo || $linea->numero_proceso_packing === $codigo
            || ProyeccionTrazabilidadFolio::normalizarCodigo($linea->loteMateriaPrima?->numero_lote) === $codigo;
    }

    private function valor(mixed $valor): mixed
    {
        return $valor instanceof BackedEnum ? $valor->value : $valor;
    }
}

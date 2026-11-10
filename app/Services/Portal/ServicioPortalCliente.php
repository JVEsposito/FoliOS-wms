<?php

namespace App\Services\Portal;

use App\Enums\EstadoCargaFolio;
use App\Enums\EstadoEmbarque;
use App\Enums\EstadoLoteMateriaPrima;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoRecepcionRomana;
use App\Enums\TipoBulto;
use App\Enums\TipoRecepcionRomana;
use App\Models\Cliente;
use App\Models\ClienteMaterial;
use App\Models\Embarque;
use App\Models\EspecieValidacion;
use App\Models\Folio;
use App\Models\GuiaRecepcionRomana;
use App\Models\LoteMateriaPrima;
use App\Models\RecepcionRomana;
use App\Models\SegmentoValidacionMp;
use App\Models\SolicitudCargaCliente;
use App\Models\Temporada;
use App\Services\Materiales\ServicioConsultaInventarioMaterial;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Consultas de solo lectura del portal de clientes. Cada una recibe un cliente ya
 * autorizado por AlcancePortalCliente y filtra por él antes de cualquier otro criterio.
 * No expone ubicación física, personas ni datos de otros clientes.
 */
class ServicioPortalCliente
{
    public const EN_PLANTA = [
        EstadoOperacionalFolio::PendientePrefrio, EstadoOperacionalFolio::PendienteUbicacion,
        EstadoOperacionalFolio::Disponible, EstadoOperacionalFolio::Bloqueado,
    ];

    public function __construct(
        private readonly AlcancePortalCliente $alcance,
        private readonly ServicioConsultaInventarioMaterial $inventarioMateriales,
    ) {}

    public function temporada(): ?Temporada
    {
        return app(ServicioTemporadaActiva::class)->buscar();
    }

    /** @return array<string, mixed> */
    public function resumen(Cliente $cliente): array
    {
        $temporada = $this->temporada();
        $recepciones = $this->recepcionesBase($cliente, $temporada);
        $folios = $this->foliosBase($cliente, $temporada)->whereIn('estado_operacional', array_map(fn ($e) => $e->value, self::EN_PLANTA));
        $hoy = CarbonImmutable::now('America/Santiago')->toDateString();

        return [
            'kilos_temporada' => round((float) (clone $recepciones)->sum('peso_neto'), 1),
            'recepciones_temporada' => (clone $recepciones)->count(),
            'kilos_ultimos_7_dias' => round((float) (clone $recepciones)->where('ingreso_at', '>=', now()->subDays(7))->sum('peso_neto'), 1),
            'folios_en_planta' => (clone $folios)->count(),
            'cajas_en_planta' => (int) (clone $folios)->get(['datos_externos'])->sum(fn (Folio $f): int => (int) ($f->datos_externos['cantidad_cajas'] ?? 0)),
            'embarques_proximos' => Embarque::where('cliente_id', $cliente->id)->where('temporada_id', $temporada?->id)
                ->where('estado', '!=', EstadoEmbarque::Cancelado->value)->where('fecha_programada', '>=', $hoy)->count(),
            'solicitudes_pendientes' => SolicitudCargaCliente::where('cliente_id', $cliente->id)
                ->where('estado', SolicitudCargaCliente::SOLICITADA)->count(),
            'items_materiales' => count($this->materiales($cliente)),
        ];
    }

    /** @param array<string, mixed> $filtros */
    public function recepciones(Cliente $cliente, array $filtros): array
    {
        $temporada = $this->temporada();
        $base = $this->recepcionesBase($cliente, $temporada)
            ->when($filtros['desde'] ?? null, fn (Builder $q, string $d) => $q->where('ingreso_at', '>=', $this->inicioDia($d)))
            ->when($filtros['hasta'] ?? null, fn (Builder $q, string $h) => $q->where('ingreso_at', '<', $this->inicioDia($h)->addDay()));
        $especies = EspecieValidacion::whereIn('id', (clone $base)->select('especie_validacion_id'))->pluck('nombre', 'id');
        $totales = (clone $base)->selectRaw('especie_validacion_id, COUNT(*) as recepciones, SUM(peso_neto) as kilos')
            ->groupBy('especie_validacion_id')->get()
            ->map(fn ($fila): array => ['especie' => $especies[$fila->especie_validacion_id] ?? 'Sin especie',
                'recepciones' => (int) $fila->recepciones, 'kilos' => round((float) $fila->kilos, 1)])->values();
        $pagina = (clone $base)->orderByDesc('ingreso_at')->paginate(min(50, (int) ($filtros['por_pagina'] ?? 20)));
        $ids = $pagina->getCollection()->pluck('id');
        $guias = GuiaRecepcionRomana::whereIn('recepcion_romana_id', $ids)->whereNotNull('vigente')->orderBy('secuencia')
            ->get(['id', 'recepcion_romana_id', 'numero_guia_despacho'])->groupBy('recepcion_romana_id');
        $segmentos = SegmentoValidacionMp::whereIn('id', LoteMateriaPrima::whereIn('recepcion_romana_id', $ids)->select('segmento_validacion_mp_id'))
            ->pluck('guia_recepcion_romana_id', 'id');
        $lotes = LoteMateriaPrima::whereIn('recepcion_romana_id', $ids)->where('cliente_id', $cliente->id)
            ->where('estado', '!=', EstadoLoteMateriaPrima::Anulado->value)->orderBy('numero_lote')
            ->get(['recepcion_romana_id', 'segmento_validacion_mp_id', 'numero_lote', 'variedad_snapshot', 'csg_snapshot',
                'kilos_netos_confirmados', 'kilos_netos_calculados'])->groupBy('recepcion_romana_id');

        return [
            'totales' => ['kilos' => round((float) $totales->sum('kilos'), 1), 'recepciones' => $totales->sum('recepciones'), 'por_especie' => $totales],
            'recepciones' => [
                'data' => $pagina->getCollection()->map(function (RecepcionRomana $r) use ($guias, $lotes, $segmentos, $especies): array {
                    $guiasRecepcion = $guias->get($r->id, collect());
                    $numeroGuia = $guiasRecepcion->pluck('numero_guia_despacho', 'id');

                    return [
                        'id' => $r->id, 'numero_recepcion' => $r->numero_recepcion, 'ingreso_at' => $r->ingreso_at?->toAtomString(),
                        'especie' => $especies[$r->especie_validacion_id] ?? null, 'kilos_netos' => round((float) $r->peso_neto, 1),
                        'guias' => $guiasRecepcion->isNotEmpty() ? $guiasRecepcion->pluck('numero_guia_despacho')->values()
                            : collect([$r->numero_guia_despacho])->filter()->values(),
                        'lotes' => $lotes->get($r->id, collect())->map(fn (LoteMateriaPrima $l): array => [
                            'numero_lote' => $l->numero_lote, 'variedad' => $l->variedad_snapshot, 'csg' => $l->csg_snapshot,
                            'kilos' => round((float) ($l->kilos_netos_confirmados ?? $l->kilos_netos_calculados ?? 0), 1),
                            'guia' => $numeroGuia[$segmentos[$l->segmento_validacion_mp_id] ?? null] ?? null,
                        ])->values(),
                    ];
                })->values(),
                'pagina_actual' => $pagina->currentPage(), 'ultima_pagina' => $pagina->lastPage(), 'total' => $pagina->total(),
            ],
        ];
    }

    /** @param array<string, mixed> $filtros */
    public function embarques(Cliente $cliente, array $filtros): array
    {
        $temporada = $this->temporada();

        return Embarque::where('cliente_id', $cliente->id)->where('temporada_id', $temporada?->id)
            ->when($filtros['desde'] ?? null, fn (Builder $q, string $d) => $q->where('fecha_programada', '>=', $d))
            ->when($filtros['hasta'] ?? null, fn (Builder $q, string $h) => $q->where('fecha_programada', '<=', $h))
            ->with(['instructivos.paisDestino:id,nombre', 'instructivos.puertoDestino:id,nombre',
                'carga' => fn ($q) => $q->withCount(['asignacionesActuales as pallets_asignados',
                    'asignacionesActuales as pallets_cargados' => fn ($a) => $a->whereIn('estado', [EstadoCargaFolio::Cargado->value, EstadoCargaFolio::Despachado->value])])])
            ->orderByDesc('fecha_programada')->orderByDesc('hora_programada')->limit(200)->get()
            ->map(fn (Embarque $e): array => [
                'codigo' => $e->codigo, 'fecha' => $e->fecha_programada->toDateString(), 'hora' => substr((string) $e->hora_programada, 0, 5),
                'estado' => $e->estado->value, 'modalidad' => $e->modalidad->value, 'nave_vuelo' => $e->nave_vuelo,
                'puerto_embarque' => $e->puerto_embarque, 'contenedor' => $e->contenedor, 'referencia' => $e->referencia_correo,
                'instructivos' => $e->instructivos->map(fn ($i): array => [
                    'numero' => $i->numero_externo, 'booking' => $i->booking, 'recibidor' => $i->recibidor,
                    'destino' => collect([$i->puertoDestino?->nombre ?? $i->destino_ciudad, $i->paisDestino?->nombre ?? $i->destino_pais])->filter()->implode(', ') ?: null,
                    'pallets' => $i->cantidad_pallets, 'cajas' => $i->cantidad_cajas,
                ])->values(),
                'carga' => $e->carga ? ['estado' => $e->carga->estado->value, 'pallets_asignados' => (int) $e->carga->pallets_asignados,
                    'pallets_cargados' => (int) $e->carga->pallets_cargados] : null,
            ])->values()->all();
    }

    /** @param array<string, mixed> $filtros */
    public function folios(Cliente $cliente, array $filtros): LengthAwarePaginator
    {
        $situacion = $filtros['situacion'] ?? 'en_planta';
        $q = trim((string) ($filtros['q'] ?? ''));
        $pagina = $this->foliosBase($cliente, $this->temporada())
            ->when($situacion === 'en_planta', fn (Builder $c) => $c->whereIn('estado_operacional', array_map(fn ($e) => $e->value, self::EN_PLANTA)))
            ->when($situacion === 'despachados', fn (Builder $c) => $c->where('estado_operacional', EstadoOperacionalFolio::Despachado->value))
            ->when($q !== '', fn (Builder $c) => $c->where('numero_folio', 'like', addcslashes($q, '%_\\').'%'))
            ->orderByDesc('fecha_ingreso')->orderBy('numero_folio')
            ->paginate(min(100, (int) ($filtros['por_pagina'] ?? 25)));
        $pagina->setCollection($pagina->getCollection()->map(fn (Folio $f): array => $this->folio($f)));

        return $pagina;
    }

    /** @return list<array<string, mixed>> */
    public function materiales(Cliente $cliente): array
    {
        return ClienteMaterial::where('cliente_id', $cliente->id)
            ->whereHas('temporada', fn (Builder $t) => $t->where('activa', true))->pluck('id')
            ->flatMap(fn (string $id): array => collect($this->inventarioMateriales->resumen($id)['resumen_items'] ?? [])->all())
            ->map(fn (array $i): array => [
                'codigo' => $i['item']['codigo'], 'nombre' => $i['item']['nombre'], 'categoria' => $i['categoria_operacional'],
                'unidad' => $i['unidad_medida'], 'cantidad' => $i['cantidad_actual'],
                'reservada' => $i['cantidad_reservada'], 'disponible' => $i['cantidad_disponible'],
            ])->sortBy('codigo')->values()->all();
    }

    private function recepcionesBase(Cliente $cliente, ?Temporada $temporada): Builder
    {
        return RecepcionRomana::where('cliente_id', $cliente->id)->where('temporada_id', $temporada?->id)
            ->where('estado', EstadoRecepcionRomana::Cerrado->value)->whereNull('eliminada_at')
            ->where('tipo_recepcion', '!=', TipoRecepcionRomana::SoloEnvases->value);
    }

    private function foliosBase(Cliente $cliente, ?Temporada $temporada): Builder
    {
        $nombres = $this->alcance->nombresExportadora($cliente);

        return Folio::where('temporada_id', $temporada?->id)
            ->whereIn('tipo_bulto', [TipoBulto::Pallet->value, TipoBulto::Saldo->value])
            ->when($nombres === [], fn (Builder $c) => $c->whereRaw('1 = 0'))
            ->when($nombres !== [], fn (Builder $c) => $c->whereRaw(
                'UPPER(TRIM(exportadora)) IN ('.implode(', ', array_fill(0, count($nombres), '?')).')', $nombres,
            ));
    }

    /** @return array<string, mixed> */
    private function folio(Folio $f): array
    {
        $d = $f->datos_externos ?? [];

        return [
            'numero_folio' => $f->numero_folio, 'tipo' => $f->tipo_bulto->value, 'estado' => $f->estado_operacional->value,
            'especie' => $d['especie'] ?? null, 'variedad' => $f->variedad, 'calibre' => $f->calibre, 'marca' => $f->marca,
            'envase' => $d['envase'] ?? null, 'categoria' => $d['categoria'] ?? null, 'cajas' => (int) ($d['cantidad_cajas'] ?? 0),
            'csg' => collect($d['composicion'] ?? [])->groupBy(fn ($l) => (string) ($l['csg'] ?? ''))
                ->map(fn ($lineas, $csg): array => ['csg' => $csg, 'cajas' => (int) $lineas->sum('cantidad_cajas')])->values(),
            'fecha_embalaje' => $d['fecha_embalaje'] ?? null, 'fecha_proceso' => $f->fecha_proceso_pt?->toDateString(),
            'en_planta_desde' => $f->fecha_ingreso?->toAtomString(),
        ];
    }

    private function inicioDia(string $fecha): CarbonImmutable
    {
        return CarbonImmutable::parse($fecha, 'America/Santiago')->startOfDay()->utc();
    }
}

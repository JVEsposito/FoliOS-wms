<?php

namespace App\Services\ControlDespacho;

use App\Enums\EstadoCarga;
use App\Enums\EstadoCargaFolio;
use App\Enums\ModalidadEmbarque;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\ControlDespacho;
use App\Models\PosicionControlDespacho;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Arma la planilla R-24 de una carga: lo que FoliOS ya sabe (sugerencias), lo que el equipo
 * de consolidación confirmó y la lista de pallets con su posición en el camión.
 */
class PlanillaControlDespacho
{
    /** Estados de asignación que forman parte del despacho. */
    private const ESTADOS_INCLUIDOS = [
        EstadoCargaFolio::Pendiente,
        EstadoCargaFolio::ConIncidencia,
        EstadoCargaFolio::EnAnden,
        EstadoCargaFolio::Cargado,
        EstadoCargaFolio::Despachado,
    ];

    /** Cargas que el equipo de consolidación ve en la PDA. */
    public function cargasEnAnden(string $temporadaId): Collection
    {
        $operacion = array_map(fn (EstadoCarga $estado): string => $estado->value, [...EstadoCarga::visiblesEnOperacion(), EstadoCarga::Despachada]);

        return Carga::query()
            ->where('temporada_id', $temporadaId)
            ->where(fn (Builder $consulta): Builder => $consulta
                ->where(fn (Builder $enAnden): Builder => $enAnden
                    ->whereIn('estado', $operacion)
                    ->where(fn (Builder $presencia): Builder => $presencia
                        ->whereHas('presenciaAndenActiva')
                        ->orWhereHas('asignacionesActuales', fn (Builder $asignacion): Builder => $asignacion
                            ->whereIn('estado', EstadoCargaFolio::valoresFueraDeCamara()))))
                // Una planilla abierta se puede terminar aunque el despacho ya se haya cerrado.
                ->orWhereHas('controlDespacho', fn (Builder $control): Builder => $control->where('estado', ControlDespacho::BORRADOR)))
            ->with(['embarque.cliente:id,nombre', 'presenciaAndenActiva.anden:id,codigo,nombre', 'andenPrevisto:id,codigo,nombre', 'controlDespacho'])
            ->withCount([
                'asignacionesActuales as pallets_activos',
                'asignacionesActuales as pallets_en_anden' => fn (Builder $asignacion): Builder => $asignacion->where('estado', EstadoCargaFolio::EnAnden->value),
                'asignacionesActuales as pallets_cargados' => fn (Builder $asignacion): Builder => $asignacion->where('estado', EstadoCargaFolio::Cargado->value),
            ])
            ->orderByDesc('updated_at')
            ->limit(60)
            ->get();
    }

    /** @return array<string, mixed> */
    public function resumenCarga(Carga $carga): array
    {
        $control = $carga->controlDespacho;
        $presencia = $carga->presenciaAndenActiva;

        return [
            'id' => $carga->id,
            'codigo' => $carga->codigo,
            'estado' => $carga->estado->value,
            'exportadora' => $carga->embarque?->cliente?->nombre,
            'embarque' => $carga->embarque?->codigo,
            'contenedor' => $carga->embarque?->contenedor,
            'anden' => $presencia?->anden?->codigo ?? $carga->andenPrevisto?->codigo,
            'patente' => $presencia?->patente ?? $carga->patente,
            'pallets' => (int) ($carga->pallets_activos ?? 0),
            'en_anden' => (int) ($carga->pallets_en_anden ?? 0),
            'cargados' => (int) ($carga->pallets_cargados ?? 0),
            'control' => $control ? [
                'id' => $control->id,
                'estado' => $control->estado,
                'posicionados' => $control->posiciones()->count(),
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    public function detalle(ControlDespacho $control): array
    {
        $control->loadMissing(['iniciadoPor:id,name', 'cerradoPor:id,name']);
        $carga = Carga::query()
            ->with([
                'embarque.cliente:id,nombre',
                'embarque.puertoEmbarque:id,nombre',
                'embarque.instructivos.paisDestino:id,nombre',
                'embarque.instructivos.puertoDestino:id,nombre',
                'presenciaAndenActiva.anden:id,codigo,nombre',
                'andenPrevisto:id,codigo,nombre',
            ])
            ->findOrFail($control->carga_id);
        $posiciones = PosicionControlDespacho::query()
            ->where('control_despacho_id', $control->id)
            ->with('registradoPor:id,name')
            ->get();
        $pallets = $this->pallets($carga, $posiciones);
        $sugerencias = $control->estaCerrado() ? ['encabezado' => [], 'cierre' => []] : $this->sugerencias($carga, $pallets);
        $encabezado = $this->combinar(FormularioControlDespacho::encabezado(), $sugerencias['encabezado'], $control->encabezado ?? []);
        $cierre = $this->combinar(FormularioControlDespacho::cierre(), $sugerencias['cierre'], $control->cierre ?? []);
        $inspeccion = collect(FormularioControlDespacho::inspeccion())
            ->mapWithKeys(fn (array $campo, string $clave): array => [$clave => ($control->inspeccion ?? [])[$clave] ?? null])
            ->all();
        $totales = $this->totales($pallets);

        $detalle = [
            'id' => $control->id,
            'estado' => $control->estado,
            'version' => $control->version,
            'carga' => [
                'id' => $carga->id,
                'codigo' => $carga->codigo,
                'estado' => $carga->estado->value,
                'embarque' => $carga->embarque?->codigo,
                'anden' => $carga->presenciaAndenActiva?->anden?->codigo ?? $carga->andenPrevisto?->codigo,
                'patente' => $carga->presenciaAndenActiva?->patente ?? $carga->patente,
            ],
            'encabezado' => $encabezado,
            'sugerido' => $sugerencias,
            'inspeccion' => $inspeccion,
            'cierre' => $cierre,
            'pallets' => $pallets->values()->all(),
            'totales' => $totales,
            'iniciado_at' => $control->iniciado_at?->toAtomString(),
            'iniciado_por' => $control->iniciadoPor?->name,
            'cerrado_at' => $control->cerrado_at?->toAtomString(),
            'cerrado_por' => $control->cerradoPor?->name,
            'reaperturas' => $control->reaperturas ?? [],
            'fotos' => $control->fotos()->get()->map->representar()->values()->all(),
            // Set fotográfico archivado en PDF (planilla cerrada).
            'fotos_pdf' => $control->fotos_pdf_ruta ? [
                'url' => '/api/control-despacho/controles/'.$control->id.'/fotos.pdf',
                'bytes' => $control->fotos_pdf_bytes,
                'cantidad' => $control->fotos_pdf_cantidad,
                'generado_at' => $control->fotos_pdf_generado_at?->toAtomString(),
            ] : null,
        ];
        $detalle['fotos_pendientes'] = FormularioControlDespacho::fotosPendientes(
            $encabezado,
            collect($detalle['fotos'])->countBy('tipo')->all(),
        );
        $detalle['faltantes_cierre'] = $control->estaCerrado() ? [] : $this->faltantes($detalle);
        $detalle['faltantes_impresion'] = $this->faltantesImpresion($detalle);

        return $detalle;
    }

    /**
     * Pallets de la carga con su posición. Incluye los que todavía están en cámara para que
     * la planilla muestre cuánto falta; los descartados y reemplazados solo si alcanzaron a
     * tener posición.
     *
     * @param  Collection<int, PosicionControlDespacho>  $posiciones
     * @return Collection<int, array<string, mixed>>
     */
    public function pallets(Carga $carga, Collection $posiciones): Collection
    {
        $porAsignacion = $posiciones->keyBy('carga_folio_id');

        return CargaFolio::query()
            ->where('carga_id', $carga->id)
            ->where(fn (Builder $consulta): Builder => $consulta
                ->whereIn('estado', array_map(fn (EstadoCargaFolio $estado): string => $estado->value, self::ESTADOS_INCLUIDOS))
                ->orWhereIn('id', $porAsignacion->keys()->all()))
            ->with('folio:id,numero_folio,variedad,datos_externos')
            ->orderBy('asignado_at')
            ->get()
            // Un folio que volvió a asignarse a la carga se muestra una vez: la asignación vigente.
            ->groupBy('folio_id')
            ->map(fn (Collection $grupo): CargaFolio => $grupo->first(fn (CargaFolio $a): bool => $porAsignacion->has($a->id))
                ?? $grupo->sortByDesc('asignado_at')->first())
            ->map(function (CargaFolio $asignacion) use ($porAsignacion): array {
                $posicion = $porAsignacion->get($asignacion->id);
                $datos = $asignacion->folio?->datos_externos ?? [];

                return [
                    'asignacion_id' => $asignacion->id,
                    'folio_id' => $asignacion->folio_id,
                    'numero_folio' => $asignacion->folio?->numero_folio,
                    'estado_carga' => $asignacion->estado->value,
                    'cargado_at' => $asignacion->cargado_at?->toAtomString(),
                    'especie' => $datos['especie'] ?? null,
                    'variedad' => $asignacion->folio?->variedad ?? ($datos['variedad'] ?? null),
                    'cajas_folio' => max(0, (int) ($datos['cantidad_cajas'] ?? 0)),
                    'posicion' => $posicion ? [
                        'id' => $posicion->id,
                        'posicion' => $posicion->posicion,
                        'codigo_pe' => $posicion->codigo_pe,
                        'cajas' => $posicion->cajas,
                        'temperatura' => $posicion->temperatura === null ? null : (float) $posicion->temperatura,
                        'registrado_at' => $posicion->registrado_at?->toAtomString(),
                        'registrado_por' => $posicion->registradoPor?->name,
                    ] : null,
                ];
            })
            ->sortBy(fn (array $pallet): string => sprintf('%d-%03d-%s', $pallet['posicion'] ? 0 : 1, $pallet['posicion']['posicion'] ?? 0, $pallet['numero_folio']))
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $pallets
     * @return array{encabezado: array<string, mixed>, cierre: array<string, mixed>}
     */
    public function sugerencias(Carga $carga, Collection $pallets): array
    {
        $embarque = $carga->embarque;
        $instructivos = $embarque?->instructivos ?? collect();
        $presencia = $carga->presenciaAndenActiva;
        $comun = function (Collection $valores): ?string {
            $unicos = $valores->map(fn ($valor): string => trim((string) $valor))->filter()->unique()->values();

            return $unicos->count() === 1 ? $unicos->first() : null;
        };
        $zona = config('app.operational_timezone', config('app.timezone'));
        $cargados = $pallets->pluck('cargado_at')->filter()->map(fn (string $fecha) => CarbonImmutable::parse($fecha)->setTimezone($zona))->sort()->values();
        $todosArriba = $pallets->isNotEmpty() && $pallets->every(fn (array $p): bool => in_array($p['estado_carga'], [EstadoCargaFolio::Cargado->value, EstadoCargaFolio::Despachado->value], true));
        $inicio = $cargados->first() ?? $presencia?->ingresada_at?->setTimezone($zona);
        $exportadoras = $carga->asignacionesHistoricas()->with('folio:id,exportadora')->get()->pluck('folio.exportadora');
        $destinos = $instructivos->map(fn ($i): string => collect([$i->destino_ciudad, $i->paisDestino?->nombre ?? $i->destino_pais])
            ->map(fn ($valor): string => trim((string) $valor))->filter()->implode(', '));

        return [
            'encabezado' => array_filter([
                'frigorifico_tipo' => 'agrorosario',
                'frigorifico' => 'Agrorosario',
                'tipo_operacion' => match ($embarque?->modalidad) {
                    ModalidadEmbarque::Maritimo => 'Exportación marítima',
                    ModalidadEmbarque::Aereo => 'Exportación aérea',
                    ModalidadEmbarque::Terrestre => 'Exportación terrestre',
                    default => null,
                },
                'tipo_camion' => filled($embarque?->contenedor) ? 'contenedor' : null,
                'exportadora' => $embarque?->cliente?->nombre ?? $comun($exportadoras),
                'referencia_embarque' => $comun($instructivos->pluck('numero_externo')) ?? $carga->numero_orden_externa ?? $embarque?->codigo,
                'fecha_instructivo' => $embarque?->fecha_programada?->toDateString(),
                'hora_instructivo' => $embarque?->hora_programada ? substr((string) $embarque->hora_programada, 0, 5) : null,
                'fecha_carga' => ($inicio ?? now()->setTimezone($zona))->format('Y-m-d'),
                'hora_inicio' => $inicio instanceof CarbonInterface ? $inicio->format('H:i') : null,
                'hora_termino' => $todosArriba ? $cargados->last()?->format('H:i') : null,
                'numero_guia' => ($carga->datos_embarque_cierre ?? [])['numero_guia'] ?? null,
                'recibidor' => $comun($instructivos->pluck('recibidor')),
                'destino' => $comun($destinos),
                'nave' => $embarque?->nave_vuelo,
                'puerto_zarpe' => $embarque?->puertoEmbarque?->nombre ?? $embarque?->puerto_embarque,
                'puerto_destino' => $comun($instructivos->map(fn ($i): ?string => $i->puertoDestino?->nombre ?? $i->destino_ciudad)),
                'especie' => $pallets->pluck('especie')->filter()->unique()->sort()->implode(', ') ?: null,
                'empresa_transporte' => $embarque?->transportista,
                'conductor' => $presencia?->conductor ?? $carga->conductor,
                'patente_camion' => $presencia?->patente ?? $carga->patente ?? $embarque?->patente_camion,
                'patente_carro' => $embarque?->patente_trasera,
                'contenedor' => $embarque?->contenedor ?? (($carga->datos_embarque_cierre ?? [])['contenedor'] ?? null),
                'sello_naviera' => $embarque?->sello,
            ], fn ($valor): bool => $valor !== null && $valor !== ''),
            'cierre' => array_filter([
                'cajas_guia' => $pallets->isEmpty() ? null : (int) $pallets->sum('cajas_folio'),
            ], fn ($valor): bool => $valor !== null),
        ];
    }

    /** @return array<string, mixed> */
    public function totales(Collection $pallets): array
    {
        $estado = fn (array $estados): int => $pallets->whereIn('estado_carga', array_map(fn (EstadoCargaFolio $e): string => $e->value, $estados))->count();

        return [
            'pallets' => $pallets->count(),
            'cargados' => $estado([EstadoCargaFolio::Cargado, EstadoCargaFolio::Despachado]),
            'en_anden' => $estado([EstadoCargaFolio::EnAnden]),
            'en_camara' => $estado([EstadoCargaFolio::Pendiente, EstadoCargaFolio::ConIncidencia]),
            'posicionados' => $pallets->whereNotNull('posicion')->count(),
            'con_temperatura' => $pallets->filter(fn (array $p): bool => ($p['posicion']['temperatura'] ?? null) !== null)->count(),
            'cajas_pcd' => (int) $pallets->sum(fn (array $p): int => (int) ($p['posicion']['cajas'] ?? 0)),
            'cajas_carga' => (int) $pallets->sum('cajas_folio'),
        ];
    }

    /**
     * Lo que impide cerrar la planilla, en palabras para el equipo.
     *
     * @param  array<string, mixed>  $detalle
     * @return array<int, string>
     */
    public function faltantes(array $detalle): array
    {
        $faltantes = [];
        if (blank($detalle['encabezado']['tipo_camion'] ?? null)) {
            $faltantes[] = 'Indica si es contenedor o thermo.';
        }
        $sinResponder = collect($detalle['inspeccion'])->filter(fn ($valor): bool => blank($valor))->count();
        if ($sinResponder > 0) {
            $faltantes[] = "Responde la inspección visual: faltan {$sinResponder} puntos.";
        }
        $pallets = collect($detalle['pallets']);
        $sinSubir = $pallets->whereIn('estado_carga', [EstadoCargaFolio::Pendiente->value, EstadoCargaFolio::ConIncidencia->value, EstadoCargaFolio::EnAnden->value]);
        if ($sinSubir->isNotEmpty()) {
            $faltantes[] = sprintf('Faltan %d pallets por subir: %s.', $sinSubir->count(), $this->lista($sinSubir));
        }
        $sinPosicion = $pallets->whereIn('estado_carga', [EstadoCargaFolio::Cargado->value, EstadoCargaFolio::Despachado->value])->whereNull('posicion');
        if ($sinPosicion->isNotEmpty()) {
            $faltantes[] = sprintf('Indica la posición de %d pallets cargados: %s.', $sinPosicion->count(), $this->lista($sinPosicion));
        }
        if ($pallets->whereNotNull('posicion')->isEmpty()) {
            $faltantes[] = 'La planilla no tiene pallets con posición.';
        }
        if (FormularioControlDespacho::exigeFotos() && ($detalle['fotos_pendientes'] ?? []) !== []) {
            $faltantes[] = 'Faltan fotos: '.collect($detalle['fotos_pendientes'])
                ->map(fn (array $foto): string => $foto['requeridas'] > 1 ? "{$foto['nombre']} ({$foto['tomadas']} de {$foto['requeridas']})" : $foto['nombre'])
                ->implode(', ').'.';
        }

        return $faltantes;
    }

    /**
     * Lo que la planilla impresa exige y el cierre no: la T° de pulpa de cada pallet con
     * posición. Se pide completar antes de imprimir.
     *
     * @param  array<string, mixed>  $detalle
     * @return array<int, string>
     */
    public function faltantesImpresion(array $detalle): array
    {
        $sinTemperatura = collect($detalle['pallets'])->filter(fn (array $p): bool => $p['posicion'] !== null && $p['posicion']['temperatura'] === null);

        return $sinTemperatura->isEmpty() ? [] : [sprintf('Falta la T° de pulpa de %d pallets: %s.', $sinTemperatura->count(), $this->lista($sinTemperatura))];
    }

    /**
     * Valores visibles: lo guardado manda; un campo sin valor guardado muestra la sugerencia.
     *
     * @param  array<string, array<string, mixed>>  $campos
     * @param  array<string, mixed>  $sugeridos
     * @param  array<string, mixed>  $guardados
     * @return array<string, mixed>
     */
    private function combinar(array $campos, array $sugeridos, array $guardados): array
    {
        return collect($campos)
            ->mapWithKeys(fn (array $campo, string $clave): array => [
                $clave => isset($guardados[$clave]) && $guardados[$clave] !== '' ? $guardados[$clave] : ($sugeridos[$clave] ?? null),
            ])
            ->all();
    }

    private function lista(Collection $pallets): string
    {
        $folios = $pallets->pluck('numero_folio')->filter()->values();

        return $folios->take(5)->implode(', ').($folios->count() > 5 ? ' y '.($folios->count() - 5).' más' : '');
    }
}

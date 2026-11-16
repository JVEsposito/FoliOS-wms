<?php

namespace App\Services\InspeccionSag;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoLoteInspeccionSag;
use App\Enums\EstadoPlanOperacional;
use App\Enums\EstadoPosicion;
use App\Enums\ModoBandaOperacional;
use App\Enums\PrioridadOperacional;
use App\Enums\TipoEspacioPreparacionSag;
use App\Enums\TipoLoteInspeccionSag;
use App\Enums\TipoPlanOperacional;
use App\Enums\UsoBandaOperacional;
use App\Models\BandaOperacional;
use App\Models\Camara;
use App\Models\LoteInspeccionSag;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\PresenciaCargaAnden;
use App\Models\ProcesoPrefrio;
use App\Models\ProcesoPrefrioFolio;
use App\Models\ReservaPosicionInspeccionSag;
use App\Models\ReservaTareaMovimiento;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Cargas\ServicioPlanDespachoDirecto;
use App\Services\Planificador\ServicioDesplieguePlanificador;
use App\Services\Prefrio\ServicioGeneracionRecepcionTunel;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioPreparacionFisicaSag
{
    public const FACTOR_CAPACIDAD = 1.5;

    public const NIVEL_RESERVADO = 1;

    public const REFERENCIA_PLAN = 'lote_inspeccion_sag_preparacion';

    public function __construct(
        private readonly ServicioDesplieguePlanificador $despliegue,
        private readonly ServicioTrasladosPreparacionSag $traslados,
    ) {}

    public function sincronizar(
        LoteInspeccionSag $lote,
        User $usuario,
    ): ?PlanOperacional {
        if (! $this->planificadorDirigidoActivo()) {
            return $this->planExistente($lote->id);
        }

        return DB::transaction(function () use ($lote, $usuario): ?PlanOperacional {
            $lote = LoteInspeccionSag::query()
                ->with('folios:id,lote_inspeccion_sag_id,folio_id')
                ->lockForUpdate()
                ->findOrFail($lote->id);

            if (! $lote->tipo->requierePreparacionFisica()
                || $lote->estado !== EstadoLoteInspeccionSag::Preparacion) {
                return $this->planExistente($lote->id, bloquear: true);
            }

            $plan = $this->asegurarPlan($lote, $usuario);
            $folioIds = $lote->folios->pluck('folio_id');
            $requeridos = $this->espaciosRequeridos($folioIds->count());
            $reservas = $this->reservasActivas($lote->id, bloquear: true);

            if ($reservas->isNotEmpty()
                && ! $this->reservaCompleta($reservas, $folioIds->count(), $requeridos)) {
                throw new DomainException(
                    'La preparación SAG posee una reserva física incompleta y requiere revisión.',
                );
            }

            if ($reservas->isEmpty()) {
                $sector = $this->seleccionarSector($folioIds);

                if ($sector !== null) {
                    $this->reservarSector($lote, $plan, $sector);
                    $reservas = $this->reservasActivas($lote->id, bloquear: true);
                }
            }

            $this->actualizarContexto($plan, $lote, $reservas);
            $this->traslados->sincronizar($lote, $plan, $usuario);

            return $plan->refresh();
        }, attempts: 3);
    }

    public function sincronizarDesdePrefrio(ProcesoPrefrio $proceso, User $usuario): void
    {
        $lotes = $this->lotesPendientes()
            ->where('temporada_id', $proceso->temporada_id)
            ->whereHas('folios', fn ($consulta) => $consulta
                ->whereIn('folio_id', $proceso->folios()->conAprobacion()->select('folio_id')))
            ->orderBy('id')
            ->get();

        foreach ($lotes as $lote) {
            $this->sincronizar($lote, $usuario);
        }
    }

    public function lotesPendientes(): Builder
    {
        return LoteInspeccionSag::query()
            ->where('estado', EstadoLoteInspeccionSag::Preparacion->value)
            ->whereIn('tipo', collect(TipoLoteInspeccionSag::cases())
                ->filter->requierePreparacionFisica()
                ->map->value)
            ->whereHas('temporada', fn ($consulta) => $consulta->where('activa', true));
    }

    public function completar(LoteInspeccionSag $lote, User $usuario): void
    {
        DB::transaction(function () use ($lote, $usuario): void {
            $plan = $this->planExistente($lote->id, bloquear: true);
            if (! $plan || $plan->estado->esFinal()) {
                return;
            }

            $posicionesPallet = ReservaPosicionInspeccionSag::query()
                ->where('lote_inspeccion_sag_id', $lote->id)
                ->where('tipo_espacio', TipoEspacioPreparacionSag::Pallet->value)
                ->whereNotNull('clave_bloqueo')
                ->lockForUpdate()
                ->pluck('posicion_id');
            $folioIds = $lote->folios()->lockForUpdate()->pluck('folio_id');
            $preparados = UbicacionActual::query()
                ->whereIn('folio_id', $folioIds)
                ->whereIn('posicion_id', $posicionesPallet)
                ->lockForUpdate()
                ->count();

            if ($folioIds->isEmpty()
                || $posicionesPallet->count() !== $folioIds->count()
                || $preparados !== $folioIds->count()) {
                throw new DomainException(
                    'La inspección no puede comenzar hasta ubicar todos sus pallets en el sector SAG reservado.',
                );
            }

            $this->traslados->cancelarPendientes($plan, $usuario);
            $plan->refresh();
            $ahora = now();
            $plan->update([
                'estado' => EstadoPlanOperacional::Completado,
                'iniciado_por_user_id' => $plan->iniciado_por_user_id ?? $usuario->id,
                'iniciado_at' => $plan->iniciado_at ?? $ahora,
                'completado_por_user_id' => $usuario->id,
                'completado_at' => $ahora,
                'contexto' => [
                    ...($plan->contexto ?? []),
                    'porcentaje_preparado' => 100,
                    'preparacion_confirmada_at' => $ahora->toAtomString(),
                ],
                'version' => $plan->version + 1,
            ]);
        }, attempts: 3);
    }

    public function liberar(
        LoteInspeccionSag $lote,
        User $usuario,
        string $motivo,
        bool $cancelarObjetivo = false,
    ): void {
        DB::transaction(function () use (
            $lote,
            $usuario,
            $motivo,
            $cancelarObjetivo,
        ): void {
            $plan = $this->planExistente($lote->id, bloquear: true);
            if ($plan) {
                $this->traslados->cancelarPendientes($plan, $usuario);
                $plan->refresh();
            }
            $motivo = Str::limit(trim($motivo), 255, '');
            $reservas = $this->reservasActivas($lote->id, bloquear: true);
            $camaraIds = $reservas
                ->map(fn (ReservaPosicionInspeccionSag $reserva): ?string => $reserva
                    ->posicion()
                    ->value('camara_id'))
                ->filter()
                ->unique()
                ->values();
            $ahora = now();

            foreach ($reservas as $reserva) {
                $reserva->update([
                    'clave_bloqueo' => null,
                    'liberada_at' => $ahora,
                    'motivo_liberacion' => $motivo,
                ]);
            }
            $this->incrementarRevisionReservas($camaraIds);

            if (! $plan) {
                return;
            }

            $atributos = [
                'contexto' => [
                    ...($plan->contexto ?? []),
                    'capacidad_estado' => 'liberada',
                    'espacios_reservados' => 0,
                    'capacidad_liberada_at' => $ahora->toAtomString(),
                    'motivo_liberacion' => $motivo,
                ],
                'version' => $plan->version + 1,
            ];
            if ($cancelarObjetivo && ! $plan->estado->esFinal()) {
                $atributos = [
                    ...$atributos,
                    'estado' => EstadoPlanOperacional::Cancelado,
                    'cancelado_por_user_id' => $usuario->id,
                    'cancelado_at' => $ahora,
                    'motivo_cancelacion' => $motivo,
                ];
            }
            $plan->update($atributos);

            if ($cancelarObjetivo) {
                $this->restaurarRecepciones($lote, $usuario);
            }
        }, attempts: 3);
    }

    private function restaurarRecepciones(LoteInspeccionSag $lote, User $usuario): void
    {
        $this->restaurarFolios($lote->folios()->pluck('folio_id'), $usuario, $lote->temporada_id);
    }

    public function restaurarFolios(Collection $folioIds, User $usuario, string $temporadaId): void
    {
        if ($folioIds->isEmpty()) {
            return;
        }
        $procesoIds = ProcesoPrefrioFolio::query()
            ->whereIn('folio_id', $folioIds)
            ->latest('created_at')
            ->orderByDesc('id')
            ->get()
            ->unique('folio_id')
            ->pluck('proceso_prefrio_id');
        $procesos = ProcesoPrefrio::query()
            ->where('temporada_id', $temporadaId)
            ->where('estado', 'aprobado')
            ->whereIn('id', $procesoIds)
            ->orderBy('id')
            ->get();

        // Resolución diferida: recepción utiliza este servicio para priorizar SAG.
        foreach ($procesos as $proceso) {
            app(ServicioGeneracionRecepcionTunel::class)->generar($proceso, $usuario);
        }
        $presencias = PresenciaCargaAnden::query()
            ->whereNotNull('bloqueo_carga_id')
            ->whereHas('carga.asignacionesActuales', fn ($consulta) => $consulta->whereIn('folio_id', $folioIds))
            ->get();
        foreach ($presencias as $presencia) {
            app(ServicioPlanDespachoDirecto::class)->sincronizar($presencia, $usuario);
        }
    }

    public function espaciosRequeridos(int $pallets): int
    {
        return (int) ceil(max(0, $pallets) * self::FACTOR_CAPACIDAD);
    }

    private function planificadorDirigidoActivo(): bool
    {
        $camaras = $this->despliegue->idsCamarasDirigidas();

        return $camaras === null || $camaras !== [];
    }

    private function asegurarPlan(
        LoteInspeccionSag $lote,
        User $usuario,
    ): PlanOperacional {
        $existente = $this->planExistente($lote->id, bloquear: true);
        if ($existente) {
            return $existente;
        }

        return PlanOperacional::query()->create([
            'temporada_id' => $lote->temporada_id,
            'tipo' => TipoPlanOperacional::PreparacionInspeccion,
            'estado' => EstadoPlanOperacional::Programado,
            'prioridad' => PrioridadOperacional::Alta,
            'titulo' => "Preparar inspección {$lote->codigo}",
            'motivo' => 'Reservar un sector accesible para pallets e inspectores SAG.',
            'referencia_tipo' => self::REFERENCIA_PLAN,
            'referencia_id' => $lote->id,
            'contexto' => [
                'planner_horizon' => 'rolling',
                'planner_compute' => config('planificador.compute'),
                'objetivo' => 'preparar_inspeccion_sag',
                'lote_inspeccion_sag_id' => $lote->id,
                'factor_capacidad' => self::FACTOR_CAPACIDAD,
                'nivel_reservado' => self::NIVEL_RESERVADO,
                'porcentaje_preparado' => 0,
            ],
            'creado_por_user_id' => $usuario->id,
            'programado_at' => now(),
        ]);
    }

    /**
     * Elige el sector dentro de una sola cámara. Si el lote no cabe en una banda, lo
     * reparte en bandas de esa cámara: en cada una sus pallets van al fondo, delante de
     * cualquier pallet ajeno, y su separación queda al frente.
     *
     * @param  Collection<int, string>  $folioIds
     * @return list<array{posiciones: Collection<int, Posicion>, pallets: array<string, true>}>|null
     */
    private function seleccionarSector(Collection $folioIds): ?array
    {
        $pallets = $folioIds->count();
        if ($pallets === 0) {
            return [];
        }
        $camarasDirigidas = $this->despliegue->idsCamarasDirigidas();
        if ($camarasDirigidas === []) {
            return null;
        }

        $bandas = BandaOperacional::query()
            ->with('camara:id,codigo,contenido,estado,cantidad_bandas,posiciones_por_banda')
            ->where('modo', ModoBandaOperacional::Operativa->value)
            ->whereHas('camara', fn ($consulta) => $consulta
                ->where('estado', EstadoCamara::Activa->value)
                ->where('contenido', ContenidoCamara::Productos->value))
            ->when(
                $camarasDirigidas !== null,
                fn (Builder $consulta) => $consulta->whereIn('camara_id', $camarasDirigidas),
            )
            ->orderBy('camara_id')
            ->orderBy('numero')
            ->lockForUpdate()
            ->get()
            ->filter(fn (BandaOperacional $banda): bool => $banda->numero <= $banda->camara->cantidad_bandas
                && in_array(
                    UsoBandaOperacional::Inspeccion->value,
                    $banda->usos_permitidos ?? [],
                    true,
                ));

        if ($bandas->isEmpty()) {
            return null;
        }

        $camaras = $bandas->pluck('camara')->unique('id')->keyBy('id');
        $clavesBandas = $bandas
            ->mapWithKeys(fn (BandaOperacional $banda): array => [
                $this->claveBanda($banda->camara_id, $banda->numero) => true,
            ]);
        // También se leen las posiciones fuera de servicio: un pallet ajeno sobre ellas
        // sigue tapando el fondo de la banda.
        $posiciones = Posicion::query()
            ->whereIn('camara_id', $camaras->keys())
            ->where('nivel', self::NIVEL_RESERVADO)
            ->orderBy('camara_id')
            ->orderBy('banda')
            ->orderBy('posicion')
            ->lockForUpdate()
            ->get()
            ->filter(function (Posicion $posicion) use ($camaras, $clavesBandas): bool {
                $camara = $camaras->get($posicion->camara_id);

                return $camara !== null
                    && $posicion->banda <= $camara->cantidad_bandas
                    && $posicion->posicion <= $camara->posiciones_por_banda
                    && $clavesBandas->has($this->claveBanda(
                        $posicion->camara_id,
                        $posicion->banda,
                    ));
            })
            ->values();

        $posicionIds = $posiciones->pluck('id');
        $ocupaciones = UbicacionActual::query()
            ->whereIn('posicion_id', $posicionIds)
            ->lockForUpdate()
            ->get(['id', 'folio_id', 'posicion_id'])
            ->groupBy('posicion_id');
        $reservadasPorTarea = ReservaTareaMovimiento::query()
            ->whereIn('bloqueo_posicion_id', $posicionIds)
            ->lockForUpdate()
            ->pluck('bloqueo_posicion_id')
            ->flip();
        $reservadasPorSag = ReservaPosicionInspeccionSag::query()
            ->whereIn('clave_bloqueo', $posicionIds)
            ->lockForUpdate()
            ->pluck('clave_bloqueo')
            ->flip();
        $folios = $folioIds->flip();

        $candidatos = collect();
        $analisis = $posiciones
            ->groupBy(fn (Posicion $posicion): string => $this->claveBanda(
                $posicion->camara_id,
                $posicion->banda,
            ))
            ->map(fn (Collection $grupo): ?array => $this->analizarBanda(
                $grupo,
                $ocupaciones,
                $reservadasPorTarea,
                $reservadasPorSag,
                $folios,
            ))
            ->filter()
            ->groupBy('camara_id');

        foreach ($analisis as $camaraId => $bandasCamara) {
            $ordenadas = $bandasCamara->sortBy('numero')->values();
            $total = $ordenadas->count();

            for ($inicio = 0; $inicio < $total; $inicio++) {
                $capacidad = 0;
                $fin = $inicio;
                while ($fin < $total && $capacidad < $pallets) {
                    $capacidad += $ordenadas[$fin]['capacidad'];
                    $fin++;
                }
                if ($capacidad < $pallets) {
                    break;
                }

                /** @var Collection<int, array<string, mixed>> $grupo */
                $grupo = $ordenadas->slice($inicio, $fin - $inicio)->values();
                $candidatos->push([
                    'bandas' => $grupo,
                    'presentes' => $grupo->sum(fn (array $banda): int => count($banda['presentes'])),
                    'extension' => $grupo->last()['numero'] - $grupo->first()['numero'],
                    'camara_codigo' => $camaras->get($camaraId)->codigo,
                    'banda' => $grupo->first()['numero'],
                ]);
            }
        }

        $seleccionado = $candidatos
            ->sort(function (array $izquierda, array $derecha): int {
                return ($izquierda['bandas']->count() <=> $derecha['bandas']->count())
                    ?: ($derecha['presentes'] <=> $izquierda['presentes'])
                    ?: ($izquierda['extension'] <=> $derecha['extension'])
                    ?: strcmp($izquierda['camara_codigo'], $derecha['camara_codigo'])
                    ?: ($izquierda['banda'] <=> $derecha['banda']);
            })
            ->first();

        return $seleccionado === null
            ? null
            : $this->distribuirSector($seleccionado['bandas'], $pallets);
    }

    /**
     * Lo utilizable de una banda es lo que se alcanza desde el frente sin mover pallets
     * ajenos. Los pallets del lote que ya están ahí se conservan y los nuevos van delante
     * de ellos, porque una banda no se carga detrás de un pallet.
     *
     * @param  Collection<int, Posicion>  $grupo
     * @return array<string, mixed>|null
     */
    private function analizarBanda(
        Collection $grupo,
        Collection $ocupaciones,
        Collection $reservadasPorTarea,
        Collection $reservadasPorSag,
        Collection $folios,
    ): ?array {
        $tramo = [];
        $anterior = null;
        foreach ($grupo->sortByDesc('posicion') as $posicion) {
            /** @var Posicion $posicion */
            $ocupantes = $ocupaciones->get($posicion->id, collect());
            if ($reservadasPorTarea->has($posicion->id)
                || $reservadasPorSag->has($posicion->id)
                || $ocupantes->contains(
                    fn (UbicacionActual $ubicacion): bool => ! $folios->has($ubicacion->folio_id),
                )) {
                break;
            }
            if ($posicion->estado !== EstadoPosicion::Activa) {
                // Una posición fuera de servicio y vacía al frente se cruza, pero no se usa.
                if ($tramo === [] && $ocupantes->isEmpty()) {
                    continue;
                }
                break;
            }
            if ($anterior !== null && $posicion->posicion !== $anterior - 1) {
                break;
            }
            $tramo[] = $posicion;
            $anterior = $posicion->posicion;
        }

        $tramo = array_reverse($tramo);
        $presentes = [];
        foreach ($tramo as $indice => $posicion) {
            if ($ocupaciones->get($posicion->id, collect())->isNotEmpty()) {
                $presentes[] = $indice;
            }
        }
        $desde = $presentes[0] ?? 0;
        $ultimo = $presentes === [] ? -1 : $presentes[count($presentes) - 1];
        $ocupado = $ultimo < 0 ? 0 : $ultimo - $desde + 1;
        $libres = count($tramo) - $ultimo - 1;
        $capacidad = min(
            count($presentes) + $libres,
            intdiv(2 * ($ocupado + $libres), 3),
        );

        if ($capacidad < max(1, count($presentes))) {
            return null;
        }

        return [
            'camara_id' => $tramo[0]->camara_id,
            'numero' => $tramo[0]->banda,
            'tramo' => $tramo,
            'presentes' => $presentes,
            'desde' => $desde,
            'ultimo' => $ultimo,
            'ocupado' => $ocupado,
            'capacidad' => $capacidad,
        ];
    }

    /**
     * Cada banda conserva primero los pallets del lote que ya tiene; el resto se asigna a
     * las bandas con más pallets presentes y, entre ellas, a la de menor número.
     *
     * @param  Collection<int, array<string, mixed>>  $bandas
     * @return list<array{posiciones: Collection<int, Posicion>, pallets: array<string, true>}>
     */
    private function distribuirSector(Collection $bandas, int $pallets): array
    {
        $asignados = $bandas->map(fn (array $banda): int => count($banda['presentes']))->all();
        $restantes = $pallets - array_sum($asignados);
        $prioridad = $bandas->keys()->all();
        usort($prioridad, fn (int $izquierda, int $derecha): int => (count($bandas[$derecha]['presentes'])
            <=> count($bandas[$izquierda]['presentes']))
            ?: ($bandas[$izquierda]['numero'] <=> $bandas[$derecha]['numero']));
        foreach ($prioridad as $indice) {
            $agregar = min($bandas[$indice]['capacidad'] - $asignados[$indice], $restantes);
            $asignados[$indice] += $agregar;
            $restantes -= $agregar;
        }

        $sector = [];
        foreach ($bandas as $indice => $banda) {
            $palletsBanda = $asignados[$indice];
            if ($palletsBanda === 0) {
                continue;
            }
            $nuevos = $palletsBanda - count($banda['presentes']);
            $espacios = max(
                $banda['ocupado'] + $nuevos,
                $this->espaciosRequeridos($palletsBanda),
            );
            $ventana = array_slice($banda['tramo'], $banda['desde'], $espacios);
            $posicionesPallet = [];
            $nuevasPosiciones = $nuevos > 0
                ? range($banda['ultimo'] + 1, $banda['ultimo'] + $nuevos)
                : [];
            foreach ([...$banda['presentes'], ...$nuevasPosiciones] as $indiceTramo) {
                $posicionesPallet[$banda['tramo'][$indiceTramo]->id] = true;
            }
            $sector[] = [
                'posiciones' => collect($ventana),
                'pallets' => $posicionesPallet,
            ];
        }

        return $sector;
    }

    /**
     * @param  list<array{posiciones: Collection<int, Posicion>, pallets: array<string, true>}>  $sector
     */
    private function reservarSector(
        LoteInspeccionSag $lote,
        PlanOperacional $plan,
        array $sector,
    ): void {
        $ahora = now();
        $orden = 0;
        $camaraIds = collect();
        foreach ($sector as $banda) {
            foreach ($banda['posiciones'] as $posicion) {
                ReservaPosicionInspeccionSag::query()->updateOrCreate([
                    'lote_inspeccion_sag_id' => $lote->id,
                    'posicion_id' => $posicion->id,
                ], [
                    'plan_operacional_id' => $plan->id,
                    'tipo_espacio' => isset($banda['pallets'][$posicion->id])
                        ? TipoEspacioPreparacionSag::Pallet
                        : TipoEspacioPreparacionSag::Separacion,
                    'orden' => ++$orden,
                    'clave_bloqueo' => $posicion->id,
                    'reservada_at' => $ahora,
                    'liberada_at' => null,
                    'motivo_liberacion' => null,
                ]);
                $camaraIds->push($posicion->camara_id);
            }
        }

        $this->incrementarRevisionReservas($camaraIds->unique()->values());
    }

    /** @param  Collection<int, ReservaPosicionInspeccionSag>  $reservas */
    private function reservaCompleta(Collection $reservas, int $pallets, int $requeridos): bool
    {
        return $pallets > 0
            && $reservas->count() >= $requeridos
            && $reservas->filter(
                fn (ReservaPosicionInspeccionSag $reserva): bool => $reserva->tipo_espacio === TipoEspacioPreparacionSag::Pallet,
            )->count() === $pallets;
    }

    /**
     * @param  Collection<int, ReservaPosicionInspeccionSag>  $reservas
     */
    private function actualizarContexto(
        PlanOperacional $plan,
        LoteInspeccionSag $lote,
        Collection $reservas,
    ): void {
        $reservas->loadMissing('posicion:id,camara_id,banda,posicion,nivel', 'posicion.camara:id,codigo');
        $pallets = $lote->folios->count();
        $requeridos = $this->espaciosRequeridos($pallets);
        $completa = $this->reservaCompleta($reservas, $pallets, $requeridos);
        $conPosicion = $reservas->filter(fn (ReservaPosicionInspeccionSag $reserva): bool => $reserva->posicion !== null);
        $separacion = $conPosicion->filter(
            fn (ReservaPosicionInspeccionSag $reserva): bool => $reserva->tipo_espacio === TipoEspacioPreparacionSag::Separacion,
        )->count();
        $bandas = $conPosicion
            ->groupBy(fn (ReservaPosicionInspeccionSag $reserva): int => $reserva->posicion->banda)
            ->sortKeys()
            ->map(fn (Collection $grupo, int $banda): array => [
                'banda' => $banda,
                'posicion_desde' => $grupo->min('posicion.posicion'),
                'posicion_hasta' => $grupo->max('posicion.posicion'),
                'pallets' => $grupo->filter(
                    fn (ReservaPosicionInspeccionSag $reserva): bool => $reserva->tipo_espacio === TipoEspacioPreparacionSag::Pallet,
                )->count(),
                'separacion' => $grupo->filter(
                    fn (ReservaPosicionInspeccionSag $reserva): bool => $reserva->tipo_espacio === TipoEspacioPreparacionSag::Separacion,
                )->count(),
            ])
            ->values()
            ->all();
        $primera = $conPosicion->first()?->posicion;
        $contexto = [
            ...($plan->contexto ?? []),
            'pallets_objetivo' => $pallets,
            'espacios_requeridos' => $requeridos,
            'espacios_pallet' => $pallets,
            'espacios_separacion' => $completa ? $separacion : $requeridos - $pallets,
            'espacios_reservados' => $reservas->count(),
            'capacidad_estado' => $completa ? 'reservada' : 'pendiente',
            'motivo_capacidad_pendiente' => $completa
                ? null
                : sprintf(
                    'No hay espacio libre y accesible en bandas de inspección de nivel 1 de una misma cámara para %d %s y %d %s de separación.',
                    $pallets,
                    $pallets === 1 ? 'pallet' : 'pallets',
                    $requeridos - $pallets,
                    $requeridos - $pallets === 1 ? 'espacio' : 'espacios',
                ),
            'sector' => $completa ? [
                'camara_id' => $primera?->camara_id,
                'camara_codigo' => $primera?->camara?->codigo,
                'banda' => $bandas[0]['banda'] ?? null,
                'nivel' => self::NIVEL_RESERVADO,
                'posicion_desde' => $conPosicion->min('posicion.posicion'),
                'posicion_hasta' => $conPosicion->max('posicion.posicion'),
                'bandas' => $bandas,
            ] : null,
        ];

        if ($plan->contexto === $contexto) {
            return;
        }
        $plan->update([
            'contexto' => $contexto,
            'version' => $plan->version + 1,
        ]);
    }

    /** @return Collection<int, ReservaPosicionInspeccionSag> */
    private function reservasActivas(
        string $loteId,
        bool $bloquear = false,
    ): Collection {
        $consulta = ReservaPosicionInspeccionSag::query()
            ->where('lote_inspeccion_sag_id', $loteId)
            ->whereNotNull('clave_bloqueo')
            ->orderBy('orden');

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->get();
    }

    private function planExistente(
        string $loteId,
        bool $bloquear = false,
    ): ?PlanOperacional {
        $consulta = PlanOperacional::query()
            ->where('referencia_tipo', self::REFERENCIA_PLAN)
            ->where('referencia_id', $loteId);

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->first();
    }

    /** @param Collection<int, string> $camaraIds */
    private function incrementarRevisionReservas(Collection $camaraIds): void
    {
        foreach ($camaraIds as $camaraId) {
            Camara::query()->whereKey($camaraId)->increment('revision_reservas');
        }
    }

    private function claveBanda(string $camaraId, int $banda): string
    {
        return $camaraId.':'.$banda;
    }
}

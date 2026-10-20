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
            $requeridos = $this->espaciosRequeridos($lote->folios->count());
            $reservas = $this->reservasActivas($lote->id, bloquear: true);

            if ($reservas->isNotEmpty() && $reservas->count() !== $requeridos) {
                throw new DomainException(
                    'La preparación SAG posee una reserva física incompleta y requiere revisión.',
                );
            }

            if ($reservas->isEmpty()) {
                $tramo = $this->seleccionarTramo(
                    $lote->folios->pluck('folio_id'),
                    $requeridos,
                );

                if ($tramo !== null) {
                    $this->reservarTramo(
                        $lote,
                        $plan,
                        $tramo,
                        $lote->folios->pluck('folio_id'),
                    );
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
                ->whereIn('folio_id', $proceso->folios()->pluck('folio_id')))
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
        $folioIds = $lote->folios()->pluck('folio_id');
        $procesoIds = ProcesoPrefrioFolio::query()
            ->whereIn('folio_id', $folioIds)
            ->latest('created_at')
            ->orderByDesc('id')
            ->get()
            ->unique('folio_id')
            ->pluck('proceso_prefrio_id');
        $procesos = ProcesoPrefrio::query()
            ->where('temporada_id', $lote->temporada_id)
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
            ->whereHas('carga.folios', fn ($consulta) => $consulta->whereIn('folio_id', $folioIds))
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
                'planner_compute' => 'tablet',
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
     * @param  Collection<int, string>  $folioIds
     * @return Collection<int, Posicion>|null
     */
    private function seleccionarTramo(
        Collection $folioIds,
        int $requeridos,
    ): ?Collection {
        if ($requeridos === 0) {
            return collect();
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
        $posiciones = Posicion::query()
            ->whereIn('camara_id', $camaras->keys())
            ->where('nivel', self::NIVEL_RESERVADO)
            ->where('estado', EstadoPosicion::Activa->value)
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

        if ($posiciones->count() < $requeridos) {
            return null;
        }

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
        $disponibles = $posiciones
            ->filter(function (Posicion $posicion) use (
                $ocupaciones,
                $reservadasPorTarea,
                $reservadasPorSag,
                $folios,
            ): bool {
                if ($reservadasPorTarea->has($posicion->id)
                    || $reservadasPorSag->has($posicion->id)) {
                    return false;
                }

                $ocupantes = $ocupaciones->get($posicion->id, collect());

                return $ocupantes->isEmpty()
                    || $ocupantes->every(
                        fn (UbicacionActual $ubicacion): bool => $folios->has($ubicacion->folio_id),
                    );
            })
            ->groupBy(fn (Posicion $posicion): string => $this->claveBanda(
                $posicion->camara_id,
                $posicion->banda,
            ));

        $candidatos = collect();
        foreach ($disponibles as $grupo) {
            $corrida = collect();
            $anterior = null;

            foreach ($grupo->sortBy('posicion') as $posicion) {
                if ($anterior !== null && $posicion->posicion !== $anterior + 1) {
                    $corrida = collect();
                }
                $corrida->push($posicion);
                $anterior = $posicion->posicion;

                if ($corrida->count() < $requeridos) {
                    continue;
                }

                /** @var Collection<int, Posicion> $ventana */
                $ventana = $corrida->slice(-$requeridos)->values();
                $palletsPresentes = $ventana->filter(
                    fn (Posicion $candidata): bool => $ocupaciones
                        ->get($candidata->id, collect())
                        ->contains(fn (UbicacionActual $ubicacion): bool => $folios
                            ->has($ubicacion->folio_id)),
                )->count();
                $camara = $camaras->get($posicion->camara_id);
                $candidatos->push([
                    'tramo' => $ventana,
                    'pallets_presentes' => $palletsPresentes,
                    'camara_codigo' => $camara->codigo,
                    'banda' => $posicion->banda,
                    'inicio' => $ventana->first()->posicion,
                ]);
            }
        }

        $seleccionado = $candidatos
            ->sort(function (array $izquierda, array $derecha): int {
                return ($derecha['pallets_presentes'] <=> $izquierda['pallets_presentes'])
                    ?: strcmp($izquierda['camara_codigo'], $derecha['camara_codigo'])
                    ?: ($izquierda['banda'] <=> $derecha['banda'])
                    ?: ($izquierda['inicio'] <=> $derecha['inicio']);
            })
            ->first();

        return $seleccionado['tramo'] ?? null;
    }

    /**
     * @param  Collection<int, Posicion>  $tramo
     * @param  Collection<int, string>  $folioIds
     */
    private function reservarTramo(
        LoteInspeccionSag $lote,
        PlanOperacional $plan,
        Collection $tramo,
        Collection $folioIds,
    ): void {
        $ocupaciones = UbicacionActual::query()
            ->whereIn('posicion_id', $tramo->pluck('id'))
            ->whereIn('folio_id', $folioIds)
            ->get(['folio_id', 'posicion_id'])
            ->keyBy('posicion_id');
        $palletsPorReservar = $folioIds->count();
        $posicionesPallet = $tramo
            ->filter(fn (Posicion $posicion): bool => $ocupaciones->has($posicion->id))
            ->pluck('id')
            ->flip();

        foreach ($tramo as $posicion) {
            if ($posicionesPallet->count() >= $palletsPorReservar) {
                break;
            }
            $posicionesPallet->put($posicion->id, true);
        }

        $ahora = now();
        foreach ($tramo->values() as $indice => $posicion) {
            ReservaPosicionInspeccionSag::query()->create([
                'lote_inspeccion_sag_id' => $lote->id,
                'plan_operacional_id' => $plan->id,
                'posicion_id' => $posicion->id,
                'tipo_espacio' => $posicionesPallet->has($posicion->id)
                    ? TipoEspacioPreparacionSag::Pallet
                    : TipoEspacioPreparacionSag::Separacion,
                'orden' => $indice + 1,
                'clave_bloqueo' => $posicion->id,
                'reservada_at' => $ahora,
            ]);
        }

        $this->incrementarRevisionReservas($tramo->pluck('camara_id')->unique()->values());
    }

    /**
     * @param  Collection<int, ReservaPosicionInspeccionSag>  $reservas
     */
    private function actualizarContexto(
        PlanOperacional $plan,
        LoteInspeccionSag $lote,
        Collection $reservas,
    ): void {
        $reservas->loadMissing('posicion:id,camara_id,banda,posicion,nivel');
        $posiciones = $reservas
            ->pluck('posicion')
            ->filter()
            ->sortBy('posicion')
            ->values();
        $requeridos = $this->espaciosRequeridos($lote->folios->count());
        $completa = $reservas->count() === $requeridos;
        $contexto = [
            ...($plan->contexto ?? []),
            'pallets_objetivo' => $lote->folios->count(),
            'espacios_requeridos' => $requeridos,
            'espacios_pallet' => $lote->folios->count(),
            'espacios_separacion' => $requeridos - $lote->folios->count(),
            'espacios_reservados' => $reservas->count(),
            'capacidad_estado' => $completa ? 'reservada' : 'pendiente',
            'motivo_capacidad_pendiente' => $completa
                ? null
                : 'No existe un tramo contiguo suficiente en bandas de inspección de nivel 1.',
            'sector' => $completa ? [
                'camara_id' => $posiciones->first()?->camara_id,
                'banda' => $posiciones->first()?->banda,
                'nivel' => self::NIVEL_RESERVADO,
                'posicion_desde' => $posiciones->min('posicion'),
                'posicion_hasta' => $posiciones->max('posicion'),
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

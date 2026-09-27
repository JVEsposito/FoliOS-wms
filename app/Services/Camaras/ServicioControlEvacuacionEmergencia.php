<?php

namespace App\Services\Camaras;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoCustodiaTemporal;
use App\Enums\EstadoManiobraOperacional;
use App\Enums\EstadoPlanOperacional;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\ModoBandaOperacional;
use App\Enums\PrioridadOperacional;
use App\Enums\TipoPlanOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Models\BandaOperacional;
use App\Models\Camara;
use App\Models\ManiobraOperacional;
use App\Models\PlanOperacional;
use App\Models\TareaMovimiento;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Estiba\ServicioManiobrasOperacionales;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Planificador\ServicioDesplieguePlanificador;
use App\Services\Temporadas\ServicioTemporadaActiva;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioControlEvacuacionEmergencia
{
    public const REFERENCIA = InterbloqueoEvacuacionEmergencia::REFERENCIA;

    public function __construct(
        private readonly ServicioManiobrasOperacionales $maniobras,
        private readonly ServicioPlanesOperacionales $planes,
        private readonly ServicioDesocupacionProgramada $vaciado,
        private readonly ServicioDesplieguePlanificador $despliegue,
    ) {}

    public function declarar(
        Camara $camara,
        User $usuario,
        string $motivo,
        ?string $dispositivoId = null,
    ): PlanOperacional {
        $motivo = trim($motivo);
        $this->validarMotivo($motivo, 'La emergencia');
        $modo = $this->despliegue->modoParaCamara($camara);
        if ($modo === 'off') {
            throw new DomainException('El planificador está desactivado; no se declaró la emergencia.');
        }
        if ($modo === 'guided' && ! $this->despliegue->dirige([$camara])) {
            throw new DomainException(
                'La emergencia dirigida requiere generación automática, cálculo tablet y horizonte rolling.',
            );
        }

        $plan = DB::transaction(function () use (
            $camara,
            $usuario,
            $motivo,
            $dispositivoId,
            $modo,
        ): PlanOperacional {
            $camara = Camara::query()->lockForUpdate()->findOrFail($camara->id);
            $existente = $this->planActivo($camara->id, bloquear: true);
            if ($existente) {
                return $existente;
            }
            $ultimo = $this->ultimoPlan($camara->id, bloquear: true);

            $temporada = app(ServicioTemporadaActiva::class)->buscar(bloquear: true)
                ?? throw new DomainException('No existe una temporada activa para declarar la emergencia.');
            $this->validarCamara($camara);

            $trabajo = $this->trabajoAfectado($camara);
            $analisis = $this->anteponerEmergencia(
                $trabajo,
                $usuario,
                aplicar: $modo === 'guided',
            );
            $ahora = now();
            $plan = PlanOperacional::create([
                'temporada_id' => $temporada->id,
                'tipo' => TipoPlanOperacional::EvacuacionEmergencia,
                'estado' => $modo === 'shadow'
                    ? EstadoPlanOperacional::Programado
                    : EstadoPlanOperacional::EnEjecucion,
                'prioridad' => PrioridadOperacional::Critica,
                'titulo' => "Emergencia en {$camara->codigo}",
                'motivo' => $motivo,
                'referencia_tipo' => self::REFERENCIA,
                'referencia_id' => $camara->id,
                'ciclo_referencia' => ($ultimo?->ciclo_referencia ?? 0) + 1,
                'creado_por_user_id' => $usuario->id,
                'iniciado_por_user_id' => $modo === 'guided'
                    ? $usuario->id
                    : null,
                'programado_at' => $ahora,
                'iniciado_at' => $modo === 'guided' ? $ahora : null,
                'contexto' => [],
            ]);

            $bandasAnteriores = [];
            if ($modo === 'guided') {
                $bandasAnteriores = $this->bloquearIngreso($camara, $plan, $usuario, $motivo);
            }
            $totalPallets = UbicacionActual::query()->where('camara_id', $camara->id)->count();
            $plan->update([
                'contexto' => [
                    'alcance' => 'control_emergencia',
                    'estado_emergencia' => $modo === 'shadow'
                        ? 'shadow'
                        : 'declarada',
                    'planner_mode' => $modo,
                    'camara_id' => $camara->id,
                    'camara_codigo' => $camara->codigo,
                    'motivo_declaracion' => $motivo,
                    'declarado_por_user_id' => $usuario->id,
                    'declarado_desde_dispositivo_id' => $dispositivoId,
                    'declarado_at' => $ahora->toAtomString(),
                    'ingreso_bloqueado' => $modo === 'guided',
                    'genera_destinos' => $modo === 'guided',
                    'genera_tareas' => $modo === 'guided',
                    'requiere_ejecucion' => true,
                    'pallets_objetivo' => $totalPallets,
                    'pallets_restantes' => $totalPallets,
                    'pallets_evacuados' => 0,
                    'porcentaje_actual' => $totalPallets === 0 ? 100 : 0,
                    'umbral_porcentaje' => 100,
                    'una_maniobra_por_recalculo' => true,
                    'sin_custodia_temporal' => true,
                    'afinidad_relajada' => ['marca', 'formato'],
                    'bandas_anteriores' => $bandasAnteriores,
                    ...$analisis,
                ],
                'version' => $plan->version + 1,
            ]);

            return $plan->refresh();
        }, attempts: 3);

        $fueCreado = $plan->wasRecentlyCreated;
        if ($modo === 'guided' && ! $plan->estado->esFinal()) {
            $plan = $this->vaciado->sincronizarPlan($camara, $plan, $usuario);
        }

        $plan = $this->cargar($plan);
        $plan->wasRecentlyCreated = $fueCreado;

        return $plan;
    }

    public function cancelar(
        Camara $camara,
        User $usuario,
        string $motivo,
        ?string $dispositivoId = null,
    ): PlanOperacional {
        $motivo = trim($motivo);
        $this->validarMotivo($motivo, 'La cancelación');

        $plan = DB::transaction(function () use (
            $camara,
            $usuario,
            $motivo,
            $dispositivoId,
        ): PlanOperacional {
            $camara = Camara::query()->lockForUpdate()->findOrFail($camara->id);
            $plan = $this->ultimoPlan($camara->id, bloquear: true)
                ?? throw new DomainException('La cámara no posee una emergencia registrada.');
            if ($plan->estado === EstadoPlanOperacional::Cancelado) {
                return $plan;
            }
            if ($plan->estado === EstadoPlanOperacional::Completado) {
                throw new ConflictoOperacion('Una emergencia completada no puede cancelarse.');
            }

            $maniobras = $plan->maniobras()
                ->whereIn('estado', [
                    EstadoManiobraOperacional::Pendiente->value,
                    EstadoManiobraOperacional::EnEjecucion->value,
                    EstadoManiobraOperacional::PausadaDiscrepancia->value,
                ])
                ->lockForUpdate()
                ->get();
            foreach ($maniobras as $maniobra) {
                if ($maniobra->estado === EstadoManiobraOperacional::PausadaDiscrepancia
                    || ! $this->maniobras->cancelarReversible($maniobra, $usuario, $motivo)) {
                    throw new ConflictoOperacion(
                        'La evacuación ya modificó la realidad física; termine la maniobra en curso antes de cancelarla.',
                    );
                }
            }

            [$restauradas, $noRestauradas] = $this->restaurarBandas($camara, $plan, $usuario);
            $plan->update([
                'estado' => EstadoPlanOperacional::Cancelado,
                'cancelado_por_user_id' => $usuario->id,
                'cancelado_at' => now(),
                'motivo_cancelacion' => $motivo,
                'contexto' => [
                    ...($plan->contexto ?? []),
                    'estado_emergencia' => 'cancelada',
                    'ingreso_bloqueado' => false,
                    'bandas_restauradas' => $restauradas,
                    'bandas_no_restauradas' => $noRestauradas,
                    'movimientos_completados' => $plan->tareas()
                        ->where('estado', EstadoTareaMovimiento::Completada->value)
                        ->count(),
                    'cancelado_por_user_id' => $usuario->id,
                    'cancelado_desde_dispositivo_id' => $dispositivoId,
                    'cancelado_at' => now()->toAtomString(),
                ],
                'version' => $plan->version + 1,
            ]);

            return $plan->refresh();
        }, attempts: 3);

        return $this->cargar($plan);
    }

    /**
     * @param  Collection<int, TareaMovimiento>  $tareas
     * @return array<string, mixed>
     */
    private function anteponerEmergencia(Collection $tareas, User $usuario, bool $aplicar): array
    {
        $procesadas = [];
        $cancelables = 0;
        $canceladas = 0;
        $planesAfectados = [];
        $impedimentos = [];

        foreach ($tareas as $tarea) {
            if (isset($procesadas[$tarea->id])) {
                continue;
            }

            $maniobra = $tarea->maniobraOperacional;
            $grupo = $maniobra
                ? $maniobra->pasos()
                    ->whereIn('estado', $this->estadosActivosTarea())
                    ->lockForUpdate()
                    ->get()
                : collect([$tarea]);
            foreach ($grupo as $paso) {
                $procesadas[$paso->id] = true;
            }

            $motivoImpedimento = $this->motivoNoReversible($grupo, $maniobra);
            if ($motivoImpedimento !== null) {
                $impedimentos[] = $this->impedimento($tarea, $maniobra, $motivoImpedimento);

                continue;
            }

            $cancelables += $grupo->count();
            if (! $aplicar) {
                continue;
            }

            $motivoCancelacion = 'Emergencia declarada en la cámara; se antepone a la labor normal reversible.';
            $cancelada = $maniobra
                ? $this->maniobras->cancelarReversible($maniobra, $usuario, $motivoCancelacion)
                : $this->planes->cancelarPorReplanificacion($tarea, $usuario, $motivoCancelacion) !== null;
            if (! $cancelada) {
                $impedimentos[] = $this->impedimento($tarea, $maniobra, 'no_reversible');

                continue;
            }

            $canceladas += $grupo->count();
            $planesAfectados[$tarea->plan_operacional_id] = true;
        }

        return [
            'tareas_reversibles_detectadas' => $cancelables,
            'tareas_canceladas' => $canceladas,
            'planes_afectados' => array_keys($planesAfectados),
            'impedimentos' => $impedimentos,
            'total_impedimentos' => count($impedimentos),
        ];
    }

    /** @param Collection<int, TareaMovimiento> $tareas */
    private function motivoNoReversible(
        Collection $tareas,
        ?ManiobraOperacional $maniobra,
    ): ?string {
        if ($tareas->contains(
            fn (TareaMovimiento $tarea): bool => $tarea->estado === EstadoTareaMovimiento::EnProceso,
        )) {
            return 'en_proceso';
        }
        if ($maniobra?->estado === EstadoManiobraOperacional::PausadaDiscrepancia) {
            return 'discrepancia';
        }
        if ($maniobra?->custodiasTemporales()
            ->where('estado', EstadoCustodiaTemporal::Activa->value)
            ->lockForUpdate()
            ->exists()) {
            return 'custodia_temporal';
        }
        if ($tareas->contains(function (TareaMovimiento $tarea): bool {
            $prioridadPlan = $tarea->planOperacional?->prioridad?->peso() ?? 0;

            return max($tarea->prioridad->peso(), $prioridadPlan)
                >= PrioridadOperacional::Critica->peso();
        })) {
            return 'prioridad_critica';
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function impedimento(
        TareaMovimiento $tarea,
        ?ManiobraOperacional $maniobra,
        string $motivo,
    ): array {
        return [
            'motivo' => $motivo,
            'plan_operacional_id' => $tarea->plan_operacional_id,
            'maniobra_operacional_id' => $maniobra?->id,
            'tarea_movimiento_id' => $tarea->id,
            'estado' => $tarea->estado->value,
            'prioridad' => $tarea->prioridad->value,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function bloquearIngreso(
        Camara $camara,
        PlanOperacional $plan,
        User $usuario,
        string $motivo,
    ): array {
        $anteriores = [];
        $bandas = BandaOperacional::query()
            ->where('camara_id', $camara->id)
            ->orderBy('numero')
            ->lockForUpdate()
            ->get();
        foreach ($bandas as $banda) {
            $anteriores[] = [
                'id' => $banda->id,
                'modo' => $banda->modo->value,
                'motivo_estado' => $banda->motivo_estado,
            ];
            $banda->update([
                'modo' => $banda->modo === ModoBandaOperacional::Bloqueada
                    ? ModoBandaOperacional::Bloqueada
                    : ModoBandaOperacional::EnVaciado,
                'motivo_estado' => $this->marcaMotivo($plan, $motivo),
                'actualizado_por_user_id' => $usuario->id,
                'version' => $banda->version + 1,
            ]);
        }
        if ($bandas->isNotEmpty()) {
            $camara->update([
                'version_plano' => $camara->version_plano + 1,
                'actualizado_por_user_id' => $usuario->id,
            ]);
        }

        return $anteriores;
    }

    /** @return array{int, int} */
    private function restaurarBandas(
        Camara $camara,
        PlanOperacional $plan,
        User $usuario,
    ): array {
        $anteriores = collect($plan->contexto['bandas_anteriores'] ?? [])->keyBy('id');
        $restauradas = 0;
        $noRestauradas = 0;
        foreach ($anteriores as $bandaId => $anterior) {
            $banda = BandaOperacional::query()
                ->where('camara_id', $camara->id)
                ->lockForUpdate()
                ->find($bandaId);
            if (! $banda || ! str_starts_with(
                (string) $banda->motivo_estado,
                $this->prefijoMotivo($plan),
            )) {
                $noRestauradas++;

                continue;
            }
            $banda->update([
                'modo' => ModoBandaOperacional::from($anterior['modo']),
                'motivo_estado' => $anterior['motivo_estado'],
                'actualizado_por_user_id' => $usuario->id,
                'version' => $banda->version + 1,
            ]);
            $restauradas++;
        }
        if ($restauradas > 0) {
            $camara->update([
                'version_plano' => $camara->version_plano + 1,
                'actualizado_por_user_id' => $usuario->id,
            ]);
        }

        return [$restauradas, $noRestauradas];
    }

    /** @return Collection<int, TareaMovimiento> */
    private function trabajoAfectado(Camara $camara): Collection
    {
        return TareaMovimiento::query()
            ->whereIn('estado', $this->estadosActivosTarea())
            ->where(function ($consulta) use ($camara): void {
                $consulta->where('camara_origen_id', $camara->id)
                    ->orWhere('camara_destino_id', $camara->id);
            })
            ->with(['planOperacional', 'maniobraOperacional'])
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();
    }

    private function validarCamara(Camara $camara): void
    {
        if ($camara->contenido !== ContenidoCamara::Productos
            || $camara->estado !== EstadoCamara::Activa) {
            throw new DomainException('Solo puede declararse una emergencia en una cámara activa de producto terminado.');
        }
        if (! $camara->bandasOperacionales()->exists()) {
            throw new DomainException('La cámara no posee bandas operacionales para bloquear el ingreso.');
        }
    }

    private function validarMotivo(string $motivo, string $operacion): void
    {
        if (mb_strlen($motivo) < 3 || mb_strlen($motivo) > 500) {
            throw new DomainException("{$operacion} requiere un motivo de 3 a 500 caracteres.");
        }
    }

    private function planActivo(string $camaraId, bool $bloquear = false): ?PlanOperacional
    {
        $consulta = PlanOperacional::query()
            ->where('tipo', TipoPlanOperacional::EvacuacionEmergencia->value)
            ->where('referencia_tipo', self::REFERENCIA)
            ->where('referencia_id', $camaraId)
            ->whereNotIn('estado', [
                EstadoPlanOperacional::Completado->value,
                EstadoPlanOperacional::Cancelado->value,
            ])
            ->orderByDesc('ciclo_referencia');

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->first();
    }

    private function ultimoPlan(string $camaraId, bool $bloquear = false): ?PlanOperacional
    {
        $consulta = PlanOperacional::query()
            ->where('tipo', TipoPlanOperacional::EvacuacionEmergencia->value)
            ->where('referencia_tipo', self::REFERENCIA)
            ->where('referencia_id', $camaraId)
            ->orderByDesc('ciclo_referencia');

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->first();
    }

    private function cargar(PlanOperacional $plan): PlanOperacional
    {
        return $plan->refresh()->load([
            'temporada:id,codigo,nombre,activa',
            'creadoPor:id,name',
            'iniciadoPor:id,name',
            'tareas',
        ]);
    }

    /** @return array<int, string> */
    private function estadosActivosTarea(): array
    {
        return [
            EstadoTareaMovimiento::Bloqueada->value,
            EstadoTareaMovimiento::Pendiente->value,
            EstadoTareaMovimiento::Asumida->value,
            EstadoTareaMovimiento::EnProceso->value,
        ];
    }

    private function marcaMotivo(PlanOperacional $plan, string $motivo): string
    {
        return Str::limit($this->prefijoMotivo($plan).' '.$motivo, 500, '');
    }

    private function prefijoMotivo(PlanOperacional $plan): string
    {
        return 'Emergencia '.$plan->id.':';
    }
}

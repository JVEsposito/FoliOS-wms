<?php

namespace App\Services\Planificador;

use App\Enums\EstadoCarga;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\FuncionCamarero;
use App\Enums\PrioridadOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Dispositivo;
use App\Models\FuncionCamareroTurno;
use App\Models\LaborCamarero;
use App\Models\PlanOperacional;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\TurnoPlanificador;
use App\Models\User;
use App\Services\Cargas\CalculadorConcentracionCarga;
use App\Services\Cargas\ServicioAcopioCarga;
use App\Services\Estiba\ServicioExclusionPallet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ServicioLaboresTurno
{
    public function __construct(private readonly CatalogoLaborCamarero $catalogo) {}

    public function vigente(string $temporadaId): ?TurnoPlanificador
    {
        return TurnoPlanificador::query()->where('temporada_id', $temporadaId)
            ->where('inicio_at', '<=', now()->utc())->where('fin_at', '>', now()->utc())->first();
    }

    public function asignacion(string $temporadaId, int $usuarioId): ?FuncionCamareroTurno
    {
        $turno = $this->vigente($temporadaId);

        return $turno?->asignaciones()->where('user_id', $usuarioId)->with('turno')->first();
    }

    public function propias(int $usuarioId): Builder
    {
        $q = TareaMovimiento::query()->where('responsable_user_id', $usuarioId)
            ->whereIn('estado', [EstadoTareaMovimiento::Asumida, EstadoTareaMovimiento::EnProceso, EstadoTareaMovimiento::Bloqueada]);
        ServicioExclusionPallet::conCompromiso($q);

        return $q;
    }

    public function tomar(TareaMovimiento $tarea, User $usuario, Dispositivo $dispositivo): void
    {
        $datos = $this->catalogo->describir($tarea);
        $anclas = $this->bloquearAnclas($datos['clave'], $usuario->id);
        $bloqueo = $this->desdeAncla($anclas['labor:'.$datos['clave']] ?? null, 'bloqueo_labor', $datos['clave']);
        if ($bloqueo && ($bloqueo->user_id !== $usuario->id || $bloqueo->dispositivo_id !== $dispositivo->id)) {
            throw new ConflictoOperacion('Esta labor ya está asignada a otro camarero o tablet. Actualiza las labores disponibles.');
        }
        $asignacion = $this->asignacion($tarea->planOperacional->temporada_id, $usuario->id);
        if ($tarea->responsable_user_id === $usuario->id && $tarea->dispositivo_id === $dispositivo->id) {
            return;
        }
        if ($asignacion && ! $asignacion->disponible) {
            throw new ConflictoOperacion('Estás marcado como no disponible en este turno. Oficina debe habilitarte antes de tomar otra labor.');
        }
        $foco = $this->desdeAncla($anclas['usuario:'.$usuario->id] ?? null, 'bloqueo_camarero', (string) $usuario->id);
        if ($foco && ($foco->clave_labor !== $datos['clave'] || $foco->dispositivo_id !== $dispositivo->id)) {
            throw new ConflictoOperacion('Continúa tu labor actual o libérala antes de tomar otra. Si espera pallets de otra labor, quedará en espera automáticamente.');
        }
        $turnoId = $asignacion?->turno_id ?? $this->vigente($tarea->planOperacional->temporada_id)?->id;
        if (! $turnoId) {
            return;
        }
        if (! $foco && $this->propias($usuario->id)->exists()) {
            throw new ConflictoOperacion('Finaliza o libera las tareas que ya tenías tomadas antes de comenzar una nueva labor de turno.');
        }
        try {
            if (! $foco) {
                $foco = LaborCamarero::create(['turno_id' => $turnoId, 'user_id' => $usuario->id,
                    'dispositivo_id' => $dispositivo->id, 'clave_labor' => $datos['clave'], 'bloqueo_labor' => $datos['clave'],
                    'bloqueo_camarero' => (string) $usuario->id, 'funcion' => $datos['funcion'], 'titulo' => mb_substr($datos['titulo'], 0, 190),
                    'estado' => 'en_curso', 'contexto' => [...$datos['contexto'], 'asignacion_version' => $asignacion?->version],
                    'vence_at' => now()->addMinutes(15)]);
                DB::table('cerrojos_labores_turno')->whereIn('clave', array_keys($anclas))->update(['labor_id' => $foco->id]);
                $this->evento($foco->turno_id, $usuario->id, 'labor_tomada', ['labor_id' => $foco->id, 'clave' => $foco->clave_labor]);
            } else {
                $foco->update(['vence_at' => now()->addMinutes(15)]);
            }
        } catch (UniqueConstraintViolationException $e) {
            throw new ConflictoOperacion('La labor acaba de ser tomada en otra tablet. Actualiza antes de continuar.', previous: $e);
        }
    }

    /** Cerrojos por clave, adquiridos en un orden común; la operación no bloquea otros trabajos. */
    private function bloquearAnclas(string $labor, int $usuarioId): array
    {
        $claves = ['labor:'.$labor, 'usuario:'.$usuarioId];
        sort($claves, SORT_STRING);
        foreach ($claves as $clave) {
            // ON DUPLICATE KEY UPDATE toma un bloqueo exclusivo incluso si la fila ya existe.
            // INSERT IGNORE tomaría uno compartido y podría provocar una conversión cruzada.
            DB::table('cerrojos_labores_turno')->upsert([['clave' => $clave]], ['clave'], ['clave']);
        }

        return DB::table('cerrojos_labores_turno')->whereIn('clave', $claves)->orderBy('clave')->lockForUpdate()->pluck('labor_id', 'clave')->all();
    }

    private function bloquearUsuario(int $usuarioId): void
    {
        DB::table('cerrojos_labores_turno')->upsert([['clave' => 'usuario:'.$usuarioId]], ['clave'], ['clave']);
    }

    private function desdeAncla(?string $id, string $columna, string $valor): ?LaborCamarero
    {
        $fila = $id ? LaborCamarero::query()->lockForUpdate()->find($id) : null;

        return $fila && $fila->{$columna} === $valor ? $fila : null;
    }

    /** Transición pequeña y persistida, sin recalcular destinos ni reconstruir la temporada. */
    public function sincronizar(int $usuarioId, ?FuncionCamareroTurno $asignacion, ?LaborCamarero $foco = null): void
    {
        if (! $foco) {
            // Una lectura que aún no ve una toma concurrente puede esperar al próximo pulso.
            // Se bloquea solo una PK existente, nunca el hueco del índice de camareros.
            $id = LaborCamarero::query()->where('bloqueo_camarero', (string) $usuarioId)->value('id');
            $foco = $this->desdeAncla($id, 'bloqueo_camarero', (string) $usuarioId);
        }
        if (! $foco) {
            return;
        }
        if ($this->propias($usuarioId)->exists()) {
            if ($foco->vence_at->lte(now()->addMinutes(5))) {
                $foco->update(['vence_at' => now()->addMinutes(15)]);
            }

            return;
        }
        $cambio = ! $asignacion || $asignacion->turno_id !== $foco->turno_id || ! $asignacion->disponible
            || ($foco->contexto['asignacion_version'] ?? null) !== $asignacion->version;
        if ($cambio || $foco->vence_at->lte(now())) {
            $this->cerrar($foco, 'liberada', $cambio ? 'La nueva función se aplicará a la siguiente labor.' : 'La labor quedó sin actividad y sin pallet comprometido.');

            return;
        }
        if ($this->catalogo->tareas($foco)->whereIn('estado', [EstadoTareaMovimiento::Pendiente, EstadoTareaMovimiento::Asumida, EstadoTareaMovimiento::Bloqueada, EstadoTareaMovimiento::EnProceso])->exists()) {
            return;
        }
        $situacion = $this->situacionFinal($foco);
        $this->cerrar($foco, $situacion['estado'], $situacion['motivo'], $situacion['datos']);
    }

    private function situacionFinal(LaborCamarero $labor): array
    {
        if (($labor->contexto['tipo'] ?? null) === 'carga') {
            $carga = Carga::query()->find($labor->contexto['objeto_id']);
            if ($carga && in_array($carga->estado, EstadoCarga::visiblesEnOperacion(), true)) {
                $asignaciones = CargaFolio::query()->where('carga_id', $carga->id)->whereHas('reservaActiva')
                    ->with(['folio.ubicacionActual.posicion.camara', 'folio.tareasMovimiento' => fn ($q) => $q->whereIn('estado', ['pendiente', 'asumida', 'en_proceso'])->with('responsable'),
                        'folio.procesosPrefrio.proceso.tunel'])->get();
                $analisis = app(CalculadorConcentracionCarga::class)->analizar($asignaciones, $carga->camara_objetivo_id);
                $dependencias = app(ServicioAcopioCarga::class)->dependencias($asignaciones);
                if ($analisis['porcentaje'] < 100) {
                    return ['estado' => 'esperando_dependencias', 'motivo' => count($dependencias)
                        ? count($dependencias).' pallets pendientes de recepción. Puedes continuar otra carga.'
                        : 'No quedan movimientos útiles publicados para esta carga. Puedes continuar otra labor.',
                        'datos' => ['dependencias' => $dependencias, 'porcentaje_fisico' => $analisis['porcentaje']]];
                }
            }

            return ['estado' => 'completada', 'motivo' => 'La carga ya está concentrada o terminó su operación.', 'datos' => []];
        }
        $plan = PlanOperacional::query()->find($labor->contexto['plan_id']);
        $espera = $plan && ! $plan->estado->esFinal() && ! in_array($labor->funcion, ['recepcion_tuneles', 'recepcion_packing', 'recepcion_repa'], true);

        return ['estado' => $espera ? 'esperando_dependencias' : 'completada', 'motivo' => $espera
            ? 'No quedan movimientos útiles publicados. Puedes continuar otra labor.' : 'Los movimientos de esta labor terminaron.', 'datos' => []];
    }

    public function actualizarEsperasCarga(string $cargaId): void
    {
        $filas = LaborCamarero::query()->where('estado', 'esperando_dependencias')->where('contexto->tipo', 'carga')->where('contexto->objeto_id', $cargaId)->get();
        foreach ($filas as $fila) {
            $situacion = $this->situacionFinal($fila);
            if ($situacion['estado'] !== $fila->estado || $situacion['motivo'] !== $fila->motivo || ($fila->contexto['espera'] ?? []) !== $situacion['datos']) {
                $this->cerrar($fila, $situacion['estado'], $situacion['motivo'], $situacion['datos']);
            }
        }
    }

    private function cerrar(LaborCamarero $labor, string $estado, string $motivo, array $datos = []): void
    {
        $labor->update(['estado' => $estado, 'motivo' => $motivo, 'bloqueo_labor' => null, 'bloqueo_camarero' => null,
            'contexto' => [...$labor->contexto, 'espera' => $datos], 'version' => $labor->version + 1]);
        $this->evento($labor->turno_id, $labor->user_id, 'labor_'.$estado, ['labor_id' => $labor->id, 'motivo' => $motivo]);
    }

    public function liberar(LaborCamarero $labor, User $actor, int $version): void
    {
        DB::transaction(function () use ($labor, $actor, $version): void {
            $labor = LaborCamarero::query()->lockForUpdate()->findOrFail($labor->id);
            if ($labor->version !== $version) {
                throw new ConflictoOperacion('La labor cambió. Actualiza antes de liberarla.');
            }
            if ($this->propias($labor->user_id)->exists()) {
                throw new ConflictoOperacion('Existe una tarea reservada, un pallet en movimiento o custodia. Debe terminarse o liberarse su reserva antes de cambiar la labor.');
            }
            if ($labor->bloqueo_labor) {
                $this->cerrar($labor, 'liberada', 'Labor liberada por supervisión.');
            }
            $this->evento($labor->turno_id, $actor->id, 'liberacion_supervisada', ['labor_id' => $labor->id]);
        }, attempts: 3);
    }

    public function contexto(Temporada $temporada, User $usuario): array
    {
        return DB::transaction(function () use ($temporada, $usuario): array {
            $this->bloquearUsuario($usuario->id);
            $asignacion = $this->asignacion($temporada->id, $usuario->id);
            $this->sincronizar($usuario->id, $asignacion);
            $actual = LaborCamarero::query()->where('bloqueo_camarero', (string) $usuario->id)->first();
            $esperas = LaborCamarero::query()->where('user_id', $usuario->id)->where('estado', 'esperando_dependencias')
                ->whereHas('turno', fn ($q) => $q->where('temporada_id', $temporada->id))->latest()->limit(10)->get();

            return ['generado_at' => now()->toISOString(), 'turno' => $asignacion ? $this->turnoJson($asignacion->turno) : null,
                'funciones' => $asignacion ? ['principal' => $asignacion->funcion_principal, 'principal_nombre' => FuncionCamarero::from($asignacion->funcion_principal)->etiqueta(),
                    'apoyos' => $asignacion->funciones_apoyo, 'preferencia' => $asignacion->preferencia] : null,
                'disponible' => $asignacion?->disponible ?? true, 'labor_actual' => $actual ? $this->laborJson($actual) : null,
                'en_espera' => $esperas->map(fn ($l) => $this->laborJson($l))->all()];
        }, attempts: 3);
    }

    public function ordenar(Collection $tareas, int $usuarioId): Collection
    {
        if ($tareas->isEmpty()) {
            return $tareas;
        }
        $temporada = $tareas->first()->planOperacional->temporada_id;
        $asignacion = $this->asignacion($temporada, $usuarioId);
        if ($asignacion && ! $asignacion->disponible) {
            return $tareas->filter(fn ($t) => $t->responsable_user_id === $usuarioId)->values();
        }
        $bloqueos = LaborCamarero::query()->whereNotNull('bloqueo_labor')->get(['clave_labor', 'user_id', 'dispositivo_id'])->keyBy('clave_labor');
        $foco = LaborCamarero::query()->where('bloqueo_camarero', (string) $usuarioId)->first();

        return $tareas->filter(function ($t) use ($bloqueos, $usuarioId, $foco): bool {
            $d = $this->catalogo->describir($t);
            $propietario = $bloqueos->get($d['clave']);
            if ($propietario && $propietario->user_id !== $usuarioId) {
                return false;
            }

            return ! $foco || $foco->clave_labor === $d['clave'] || $t->responsable_user_id === $usuarioId;
        })->sort(function ($a, $b) use ($asignacion, $foco): int {
            return $b->prioridad->peso() <=> $a->prioridad->peso()
                ?: $this->catalogo->afinidad($this->catalogo->describir($b), $asignacion?->toArray(), $foco?->clave_labor)
                    <=> $this->catalogo->afinidad($this->catalogo->describir($a), $asignacion?->toArray(), $foco?->clave_labor);
        })->values();
    }

    /** Favorece cobertura de funciones entre urgencias iguales, sin cambiar recursos ni cupos físicos. */
    public function preferenciasArbitraje(Collection $maniobras, string $temporadaId): array
    {
        $turno = $this->vigente($temporadaId);
        if (! $turno) {
            return [];
        }
        $asignaciones = $turno->asignaciones()->where('disponible', true)->whereHas('usuario', fn ($u) => $u->where('activo', true))->orderBy('user_id')->get();
        $focos = LaborCamarero::query()->whereNotNull('bloqueo_labor')->get()->keyBy('clave_labor');
        $selecciones = [];
        $trabajos = [];
        $ocupados = [];
        foreach ($maniobras as $m) {
            if ($m->responsable_user_id && $m->estado->value !== 'pendiente') {
                $ocupados[$m->responsable_user_id] = true;
            }
        }
        foreach (array_reverse(PrioridadOperacional::cases()) as $nivelPrioridad) {
            $pares = [];
            foreach ($asignaciones as $asignacion) {
                if (isset($ocupados[$asignacion->user_id])) {
                    continue;
                }
                $miFoco = $focos->firstWhere('user_id', $asignacion->user_id);
                foreach ($maniobras as $m) {
                    if ($m->estado->value !== 'pendiente' || $m->prioridad !== $nivelPrioridad) {
                        continue;
                    }
                    $paso = $m->pasos->sortBy('secuencia_maniobra')->first();
                    if (! $paso) {
                        continue;
                    }
                    $paso->setRelation('planOperacional', $m->planOperacional);
                    $d = $this->catalogo->describir($paso);
                    $foco = $focos->get($d['clave']);
                    if (($foco && $foco->user_id !== $asignacion->user_id) || ($miFoco && $miFoco->clave_labor !== $d['clave'])) {
                        continue;
                    }
                    $pares[] = ['maniobra' => $m, 'usuario' => $asignacion->user_id, 'clave' => $d['clave'],
                        'peso' => $this->catalogo->afinidad($d, $asignacion->toArray(), $miFoco?->clave_labor)];
                }
            }
            usort($pares, fn ($a, $b) => $b['peso'] <=> $a['peso']
                ?: $a['maniobra']->created_at <=> $b['maniobra']->created_at
                ?: strcmp($a['maniobra']->id, $b['maniobra']->id) ?: $a['usuario'] <=> $b['usuario']);
            foreach ($pares as $par) {
                if (isset($ocupados[$par['usuario']]) || isset($trabajos[$par['clave']]) || isset($selecciones[$par['maniobra']->id])) {
                    continue;
                }
                $selecciones[$par['maniobra']->id] = 1000 - count($selecciones);
                $trabajos[$par['clave']] = true;
                $ocupados[$par['usuario']] = true;
            }
        }

        return $selecciones;
    }

    public function versionArbitraje(string $temporadaId): array
    {
        $turno = $this->vigente($temporadaId);

        return [$turno?->id, $turno?->version,
            $turno?->asignaciones()->orderBy('user_id')->get(['user_id', 'version', 'disponible'])->toArray(),
            LaborCamarero::query()->whereNotNull('bloqueo_labor')->orderBy('id')->get(['id', 'clave_labor', 'user_id', 'estado', 'version'])->toArray()];
    }

    public function pulso(Temporada $temporada): void
    {
        LaborCamarero::query()->whereNotNull('bloqueo_camarero')->whereHas('turno', fn ($q) => $q->where('temporada_id', $temporada->id))
            ->get()->each(function (LaborCamarero $labor) use ($temporada): void {
                DB::transaction(function () use ($labor, $temporada): void {
                    $this->bloquearUsuario($labor->user_id);
                    $this->sincronizar($labor->user_id, $this->asignacion($temporada->id, $labor->user_id));
                }, attempts: 3);
            });
        $turno = $this->vigente($temporada->id);
        $version = $turno ? $turno->id.':'.$turno->version : 'sin_turno';
        $clave = 'planificador:turno-vigente:'.$temporada->id;
        if (Cache::get($clave) !== $version) {
            app(ServicioEstadoArbitrajePlanificador::class)->solicitar($temporada, 'cambio_turno');
            Cache::put($clave, $version, now()->addDays(2));
        }
    }

    public function turnoJson(TurnoPlanificador $turno): array
    {
        return ['id' => $turno->id, 'nombre' => $turno->nombre, 'inicio_at' => $turno->inicio_at->toISOString(),
            'fin_at' => $turno->fin_at->toISOString(), 'version' => $turno->version, 'zona_horaria' => config('app.operational_timezone')];
    }

    public function laborJson(LaborCamarero $labor): array
    {
        return ['id' => $labor->id, 'user_id' => $labor->user_id, 'dispositivo_id' => $labor->dispositivo_id,
            'funcion' => $labor->funcion, 'titulo' => $labor->titulo, 'estado' => $labor->estado, 'motivo' => $labor->motivo,
            'version' => $labor->version, 'espera' => $labor->contexto['espera'] ?? []];
    }

    public function evento(string $turnoId, int $actorId, string $tipo, array $datos): void
    {
        DB::table('eventos_labores_turno')->insert(['turno_id' => $turnoId, 'user_id' => $actorId,
            'tipo' => $tipo, 'datos' => json_encode($datos, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        $temporadaId = TurnoPlanificador::query()->whereKey($turnoId)->value('temporada_id');
        DB::afterCommit(fn () => app(ServicioEstadoArbitrajePlanificador::class)->solicitar($temporadaId, 'labor_turno'));
    }
}

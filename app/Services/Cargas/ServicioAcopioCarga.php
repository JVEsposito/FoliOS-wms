<?php

namespace App\Services\Cargas;

use App\Enums\EstadoCarga;
use App\Enums\EstadoCargaFolio;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\ReservaCargaFolio;
use App\Models\ReservaTareaMovimiento;
use App\Models\TareaMovimiento;
use App\Models\UbicacionActual;
use Illuminate\Support\Collection;

/** Comparte el destino de carga sin crear otra tarea ni anticipar inventario físico. */
class ServicioAcopioCarga
{
    public function __construct(
        private readonly CalculadorConcentracionCarga $calculador,
        private readonly SectorAcopioCarga $sector,
    ) {}

    public function esRecepcion(PlanOperacional $plan, TipoMovimiento $tipo): bool
    {
        return ($plan->contexto['planner_horizon'] ?? config('planificador.horizon')) === 'rolling'
            && (in_array($plan->tipo, [
                TipoPlanOperacional::RecepcionTunel,
                TipoPlanOperacional::RecepcionRepaletizaje,
            ], true) || ($plan->tipo === TipoPlanOperacional::AlmacenamientoPallet
                && ($tipo === TipoMovimiento::UbicacionInicial
                    || $plan->referencia_tipo === 'recepcion_fruta_embalada')));
    }

    /**
     * Al materializar se bloquea la carga antes de leer sus puntos. Dos tablets
     * no pueden establecer simultáneamente dos primeros acopios de una carga.
     *
     * @return array<string, mixed>|null
     */
    public function paraFolio(string $folioId, ?string $excluirTareaId = null, bool $bloquear = false): ?array
    {
        $this->sector->olvidar();
        $asignacionLeida = CargaFolio::query()
            ->where('folio_id', $folioId)
            ->whereHas('reservaActiva')
            ->first(['id', 'carga_id']);
        if (! $asignacionLeida && ! $bloquear) {
            return null;
        }

        $carga = $asignacionLeida ? Carga::query()
            ->when($bloquear, fn ($consulta) => $consulta->lockForUpdate())
            ->find($asignacionLeida->carga_id) : null;
        if ($bloquear) {
            // La primera lectura puede pertenecer al snapshot REPEATABLE READ.
            // Se conserva el orden carga/reserva de Oficina y se comprueba con
            // una lectura actual incluso cuando antes no había asignación.
            $reservaActual = ReservaCargaFolio::query()->lockForUpdate()->find($folioId);
            if ($reservaActual?->carga_folio_id !== $asignacionLeida?->id) {
                throw new ConflictoOperacion('La asignación de carga cambió durante la operación; actualice la tarea antes de reservar destino.');
            }
        }
        if (! $carga || ! in_array($carga->estado, EstadoCarga::visiblesEnOperacion(), true)
            || ! $carga->temporada()->where('activa', true)->exists()) {
            return null;
        }
        $asignaciones = CargaFolio::query()
            ->where('carga_id', $carga->id)
            ->whereHas('reservaActiva')
            ->whereHas('folio', fn ($consulta) => $consulta->where('activo', true)->where('tipo_bulto', TipoBulto::Pallet))
            ->with([
                'folio.ubicacionActual' => fn ($consulta) => $consulta
                    ->when($bloquear, fn ($actual) => $actual->lockForUpdate()),
                'folio.ubicacionActual.posicion.camara',
            ])
            ->when($bloquear, fn ($consulta) => $consulta->lockForUpdate())
            ->orderBy('id')
            ->get();
        $asignacion = $asignaciones->firstWhere('folio_id', $folioId);
        if (! $asignacion || $asignacion->estado !== EstadoCargaFolio::Pendiente) {
            return null;
        }

        $tieneCamion = $carga->presenciaAndenActiva()
            ->when($bloquear, fn ($consulta) => $consulta->lockForUpdate())
            ->exists();
        $reservas = ReservaTareaMovimiento::query()
            ->conCompromisoVigente()
            ->whereNotNull('bloqueo_posicion_id')
            ->whereHas('tareaMovimiento', fn ($consulta) => $consulta
                ->whereIn('folio_id', $asignaciones->pluck('folio_id'))
                ->when($excluirTareaId, fn ($tareas) => $tareas->whereKeyNot($excluirTareaId)))
            ->with(['tareaMovimiento:id,folio_id', 'posicionDestino.camara'])
            ->orderBy('reservada_at')->orderBy('id')
            ->when($bloquear, fn ($consulta) => $consulta->lockForUpdate())
            ->get();
        $propias = $excluirTareaId === null ? [] : ReservaTareaMovimiento::query()
            ->conCompromisoVigente()
            ->where('tarea_movimiento_id', $excluirTareaId)
            ->whereNotNull('bloqueo_posicion_id')
            ->pluck('bloqueo_posicion_id')
            ->all();

        return $this->proyectar($carga, $asignaciones, $reservas, $folioId, $tieneCamion, $propias);
    }

    /** Una lectura compartida por carga; se usa solo para preparar propuestas, nunca para autorizar escrituras.
     * @param  Collection<int, TareaMovimiento>  $tareas
     * @return array<string, array<string, mixed>|null>
     */
    public function paraTareas(Collection $tareas): array
    {
        $this->sector->olvidar();
        $tareas = (new TareaMovimiento)->newCollection($tareas->all())->loadMissing('planOperacional');
        $recepciones = $tareas->filter(fn (TareaMovimiento $tarea): bool => $this
            ->esRecepcion($tarea->planOperacional, $tarea->tipo_movimiento));
        $salida = $recepciones->mapWithKeys(fn (TareaMovimiento $tarea): array => [$tarea->id => null])->all();
        if ($recepciones->isEmpty()) {
            return $salida;
        }
        $actuales = CargaFolio::query()->whereIn('folio_id', $recepciones->pluck('folio_id'))
            ->where('estado', EstadoCargaFolio::Pendiente)->whereHas('reservaActiva')
            ->with(['carga.temporada', 'carga.presenciaAndenActiva'])->get()->keyBy('folio_id');
        if ($actuales->isEmpty()) {
            return $salida;
        }
        $asignaciones = CargaFolio::query()->whereIn('carga_id', $actuales->pluck('carga_id')->unique())
            ->whereHas('reservaActiva')
            ->whereHas('folio', fn ($consulta) => $consulta->where('activo', true)->where('tipo_bulto', TipoBulto::Pallet))
            ->with('folio.ubicacionActual.posicion.camara')->orderBy('id')->get();
        $reservas = ReservaTareaMovimiento::query()->conCompromisoVigente()->whereNotNull('bloqueo_posicion_id')
            ->whereHas('tareaMovimiento', fn ($consulta) => $consulta->whereIn('folio_id', $asignaciones->pluck('folio_id')))
            ->with(['tareaMovimiento:id,folio_id', 'posicionDestino.camara'])
            ->orderBy('reservada_at')->orderBy('id')->get();
        $porCarga = $asignaciones->groupBy('carga_id');
        foreach ($recepciones as $tarea) {
            $carga = $actuales->get($tarea->folio_id)?->carga;
            if (! $carga || ! $carga->temporada?->activa
                || ! in_array($carga->estado, EstadoCarga::visiblesEnOperacion(), true)) {
                continue;
            }
            $grupo = $porCarga->get($carga->id, collect());
            if (! $grupo->contains('folio_id', $tarea->folio_id)) {
                continue;
            }
            $folios = $grupo->pluck('folio_id')->flip();
            $salida[$tarea->id] = $this->proyectar($carga, $grupo, $reservas->filter(
                fn (ReservaTareaMovimiento $reserva): bool => $reserva->tarea_movimiento_id !== $tarea->id
                    && $folios->has($reserva->tareaMovimiento->folio_id),
            ), $tarea->folio_id, $carga->presenciaAndenActiva !== null, $reservas
                ->where('tarea_movimiento_id', $tarea->id)
                ->pluck('bloqueo_posicion_id')
                ->filter()
                ->values()
                ->all());
        }

        return $salida;
    }

    /** @param Collection<int, CargaFolio> $asignaciones
     * @param  Collection<int, ReservaTareaMovimiento>  $reservas
     * @param  list<string>  $propias  destinos ya reservados por la tarea del pallet
     * @return array<string, mixed>
     */
    private function proyectar(Carga $carga, Collection $asignaciones, Collection $reservas, string $folioId, bool $tieneCamion, array $propias = []): array
    {
        // Se construye una geometría proyectada aparte. Los entrantes conservan
        // sus reservas y nunca se cuentan como pallets físicamente recibidos.
        $proyectadas = $asignaciones->map(function (CargaFolio $item) use ($reservas, $folioId): CargaFolio {
            $copia = clone $item;
            $folio = clone $item->folio;
            if ($item->folio_id === $folioId) {
                $folio->setRelation('ubicacionActual', null);
            } else {
                $destino = $reservas->first(fn (ReservaTareaMovimiento $reserva): bool => $reserva
                    ->tareaMovimiento->folio_id === $item->folio_id)?->posicionDestino;
                if ($destino) {
                    $folio->setRelation('ubicacionActual', (new UbicacionActual)
                        ->setRelation('posicion', $destino));
                }
            }

            return $copia->setRelation('folio', $folio);
        });
        $fisico = $this->calculador->analizar($asignaciones->where('folio_id', '!=', $folioId));
        $pendientes = $asignaciones->where('estado', EstadoCargaFolio::Pendiente)->count();
        $camaraId = $carga->camara_objetivo_id
            ?? ($fisico['grupo_principal']['camara']['id'] ?? null)
            ?? $reservas->first()?->posicionDestino?->camara_id
            // Antes del primer pallet ya se estima dónde cabe la carga completa.
            ?? $this->sector->camaraParaCarga(
                $carga->id,
                $asignaciones->pluck('folio.exportadora')->filter()->unique()->values()->all(),
                $pendientes,
            );
        // El acopio crece donde cabe lo que falta de la carga, no necesariamente junto al grupo
        // más grande: un grupo encajonado entre bandas llenas no recibe a nadie más.
        $sector = $camaraId === null ? null : $this->sector->elegir(
            $camaraId,
            $carga->id,
            $this->puntosEnCamara($proyectadas, $camaraId),
            $pendientes,
            $propias,
        );

        return [
            'carga_id' => $carga->id,
            'carga_codigo' => $carga->codigo,
            'carga_folio_id' => $asignaciones->firstWhere('folio_id', $folioId)->id,
            'camara_objetivo_id' => $camaraId,
            'puntos' => $sector['puntos'] ?? [],
            'sector' => $sector['sector'] ?? null,
            'despacho_directo' => $tieneCamion,
        ];
    }

    /**
     * @param  Collection<int, CargaFolio>  $proyectadas
     * @return list<array{banda: int, posicion: int, nivel: int}>
     */
    private function puntosEnCamara(Collection $proyectadas, string $camaraId): array
    {
        return $proyectadas
            ->map(fn (CargaFolio $asignacion): ?Posicion => $asignacion->folio?->ubicacionActual?->posicion)
            ->filter(fn (?Posicion $posicion): bool => $posicion !== null && $posicion->camara_id === $camaraId)
            ->map(fn (Posicion $posicion): array => [
                'banda' => (int) $posicion->banda,
                'posicion' => (int) $posicion->posicion,
                'nivel' => (int) $posicion->nivel,
            ])
            ->values()
            ->all();
    }

    /** Actualiza solamente trabajo aún sin destino comprometido. */
    public function actualizarRecepcion(TareaMovimiento $tarea): void
    {
        $this->actualizarRecepciones(collect([$tarea]));
    }

    /** @param Collection<int, TareaMovimiento> $tareas */
    public function actualizarRecepciones(Collection $tareas): void
    {
        $tareas = (new TareaMovimiento)->newCollection($tareas->all())->loadMissing('planOperacional');
        $candidatas = $tareas->filter(fn (TareaMovimiento $tarea): bool => $this->esRecepcion($tarea->planOperacional, $tarea->tipo_movimiento)
            && in_array($tarea->estado, [EstadoTareaMovimiento::Pendiente, EstadoTareaMovimiento::Asumida], true));
        if ($candidatas->isEmpty()) {
            return;
        }
        $comprometidas = ReservaTareaMovimiento::query()->conCompromisoVigente()
            ->whereIn('tarea_movimiento_id', $candidatas->pluck('id'))->whereNotNull('bloqueo_posicion_id')
            ->pluck('tarea_movimiento_id')->flip();
        $candidatas = $candidatas->reject(fn (TareaMovimiento $tarea): bool => $comprometidas->has($tarea->id));
        $acopios = $this->paraTareas($candidatas);
        foreach ($candidatas as $tarea) {
            $this->aplicarAcopio($tarea, $acopios[$tarea->id] ?? null);
        }
    }

    /** @param array<string, mixed>|null $acopio */
    private function aplicarAcopio(TareaMovimiento $tarea, ?array $acopio): void
    {
        $contexto = $tarea->contexto ?? [];
        $anterior = $contexto['acopio_carga'] ?? null;
        if ($acopio === $anterior) {
            return;
        }
        $contexto['acopio_carga'] = $acopio;
        $tarea->update([
            'contexto' => $contexto,
            'camara_destino_id' => $acopio['camara_objetivo_id'] ?? null,
            'posicion_destino_id' => null,
            'version' => $tarea->version + 1,
        ]);
    }

    /**
     * @param  bool  $reservado  el destino ya fue validado y reservado (al iniciar el retiro): si
     *                           el acopio se movió entre medio, el pallet igual va a su reserva.
     */
    public function validarDestino(TareaMovimiento $tarea, Posicion $posicion, bool $reservado = false): void
    {
        $complementario = ($tarea->contexto['tipo_decision'] ?? null) === 'blocker_destino_util';
        if ((! $this->esRecepcion($tarea->planOperacional, $tarea->tipo_movimiento) && ! $complementario)
            || $tarea->estado === EstadoTareaMovimiento::EnProceso) {
            return;
        }
        $acopio = $this->paraFolio($tarea->folio_id, $tarea->id, bloquear: true);
        if (! $complementario && ($acopio['carga_id'] ?? null) !== ($tarea->contexto['acopio_carga']['carga_id'] ?? null)) {
            throw new ConflictoOperacion('La carga cambió; libere y vuelva a tomar la tarea para actualizar su acopio.');
        }
        if ($complementario && ($acopio['carga_id'] ?? null) !== ($tarea->contexto['carga_beneficiada_id'] ?? null)) {
            throw new ConflictoOperacion('Cambió la carga beneficiada del acopio; se debe replanificar la maniobra complementaria.');
        }
        if (! $acopio) {
            return;
        }
        if ($acopio['despacho_directo']) {
            throw new ConflictoOperacion('La carga tiene camión en andén; actualice la labor para realizar despacho directo.');
        }
        if ($acopio['camara_objetivo_id'] && $posicion->camara_id !== $acopio['camara_objetivo_id']) {
            throw new ConflictoOperacion('El destino debe respetar la cámara de acopio vigente de la carga.');
        }
        if ($reservado) {
            return;
        }
        if ($acopio['puntos'] !== [] && ! collect($acopio['puntos'])->contains(
            fn (array $punto): bool => (int) $punto['nivel'] === (int) $posicion->nivel
                && ((abs($punto['banda'] - $posicion->banda) === 0
                        && abs($punto['posicion'] - $posicion->posicion) === 1)
                    || (abs($punto['banda'] - $posicion->banda) === 1
                        && abs($punto['posicion'] - $posicion->posicion) <= 1)),
        )) {
            throw new ConflictoOperacion('El destino no amplía el acopio vigente de la carga; actualice la propuesta.');
        }
    }

    /** @param Collection<int, CargaFolio> $asignaciones
     * @return array<int, array<string, mixed>>
     */
    public function dependencias(Collection $asignaciones): array
    {
        return $asignaciones->filter(fn (CargaFolio $item): bool => $item->estado === EstadoCargaFolio::Pendiente
            && $item->folio?->activo && $item->folio->tipo_bulto === TipoBulto::Pallet
            && $item->folio->ubicacionActual === null)
            ->map(function (CargaFolio $item): array {
                $folio = $item->folio;
                $tarea = $folio->relationLoaded('tareasMovimiento') ? $folio->tareasMovimiento->first() : null;
                $prefrio = $folio->relationLoaded('procesosPrefrio') ? $folio->procesosPrefrio
                    ->sortByDesc('cargado_at')->first(fn ($item): bool => in_array($item->estado->value, ['en_proceso', 'aprobado'], true)) : null;
                $origen = ($tarea?->contexto['origen_logico'] ?? null) === 'repaletizaje'
                    ? 'Repaletizaje'
                    : ($prefrio?->proceso?->tunel?->nombre
                        ?? ($folio->origen_sistema === 'recepcion_externa' ? 'Packing externo' : 'Recepción pendiente'));

                return [
                    'folio_id' => $folio->id,
                    'numero_folio' => $folio->numero_folio,
                    'origen' => $origen,
                    'plan_id' => $tarea?->plan_operacional_id,
                    'tarea_id' => $tarea?->id,
                    'responsable' => $tarea?->responsable?->name,
                    'estado' => $tarea?->estado->value ?? 'esperando_liberacion',
                ];
            })->values()->all();
    }
}

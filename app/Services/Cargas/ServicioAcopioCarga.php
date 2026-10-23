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
use App\Models\ReservaTareaMovimiento;
use App\Models\TareaMovimiento;
use App\Models\UbicacionActual;
use Illuminate\Support\Collection;

/** Comparte el destino de carga sin crear otra tarea ni anticipar inventario físico. */
class ServicioAcopioCarga
{
    public function __construct(private readonly CalculadorConcentracionCarga $calculador) {}

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
        $cargaId = CargaFolio::query()
            ->where('folio_id', $folioId)
            ->whereHas('reservaActiva')
            ->value('carga_id');
        if (! $cargaId) {
            return null;
        }

        $carga = Carga::query()
            ->when($bloquear, fn ($consulta) => $consulta->lockForUpdate())
            ->find($cargaId);
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
        $camaraId = $carga->camara_objetivo_id
            ?? ($fisico['grupo_principal']['camara']['id'] ?? null)
            ?? $reservas->first()?->posicionDestino?->camara_id;
        $analisis = $this->calculador->analizar($proyectadas, $camaraId);

        return [
            'carga_id' => $carga->id,
            'carga_codigo' => $carga->codigo,
            'carga_folio_id' => $asignacion->id,
            'camara_objetivo_id' => $camaraId,
            'puntos' => $analisis['grupo_principal_puntos'],
            'despacho_directo' => $tieneCamion,
        ];
    }

    /** Actualiza solamente trabajo aún sin destino comprometido. */
    public function actualizarRecepcion(TareaMovimiento $tarea): void
    {
        if (! $this->esRecepcion($tarea->planOperacional, $tarea->tipo_movimiento)
            || ! in_array($tarea->estado, [EstadoTareaMovimiento::Pendiente, EstadoTareaMovimiento::Asumida], true)
            || $tarea->reservaActiva()->conCompromisoVigente()->whereNotNull('bloqueo_posicion_id')->exists()) {
            return;
        }
        $acopio = $this->paraFolio($tarea->folio_id, $tarea->id);
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

    public function validarDestino(TareaMovimiento $tarea, Posicion $posicion): void
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

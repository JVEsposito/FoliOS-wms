<?php

namespace App\Http\Resources;

use App\Enums\EstadoCustodiaTemporal;
use App\Enums\EstadoTareaMovimiento;
use App\Models\TareaMovimiento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TareaMovimientoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'permite_sesion_por_tarea' => $this->maniobra_operacional_id !== null
                && (($this->planOperacional?->contexto ?? [])['planner_horizon'] ?? config('planificador.horizon')) === 'rolling',
            'plan' => $this->whenLoaded('planOperacional', fn (): array => [
                'id' => $this->planOperacional->id,
                'tipo' => $this->planOperacional->tipo->value,
                'estado' => $this->planOperacional->estado->value,
                'prioridad' => $this->planOperacional->prioridad->value,
                'titulo' => $this->planOperacional->titulo,
                'motivo' => $this->planOperacional->motivo,
                'version' => $this->planOperacional->version,
                'horizon' => ($this->planOperacional->contexto ?? [])['planner_horizon']
                    ?? config('planificador.horizon'),
                'compute' => config('planificador.compute'),
            ]),
            'secuencia' => $this->secuencia,
            'maniobra' => $this->whenLoaded('maniobraOperacional', function (): ?array {
                $maniobra = $this->maniobraOperacional;

                return $maniobra ? [
                    'id' => $maniobra->id,
                    'estado' => $maniobra->estado->value,
                    'titulo' => $maniobra->titulo,
                    'secuencia_actual' => $maniobra->secuencia_actual,
                    'pasos_totales' => $maniobra->costo_movimientos,
                    'costo_movimientos' => $maniobra->costo_movimientos,
                    'beneficio_estimado' => $maniobra->beneficio_estimado,
                    'riesgo_operacional' => $maniobra->riesgo_operacional,
                    'version' => $maniobra->version,
                    'arbitraje' => $maniobra->relationLoaded('ultimaDecisionArbitraje')
                        && $maniobra->ultimaDecisionArbitraje ? [
                            'decision' => $maniobra->ultimaDecisionArbitraje->decision->value,
                            'orden' => $maniobra->ultimaDecisionArbitraje->orden,
                            'puntaje' => $maniobra->ultimaDecisionArbitraje->puntaje,
                            'beneficio_neto' => $maniobra->ultimaDecisionArbitraje->beneficio_neto,
                            'motivo' => $maniobra->ultimaDecisionArbitraje->motivo,
                            'conflictos' => $maniobra->ultimaDecisionArbitraje->conflictos ?? [],
                            'snapshot_version' => $maniobra->ultimaDecisionArbitraje
                                ->relationLoaded('ciclo')
                                ? $maniobra->ultimaDecisionArbitraje->ciclo?->snapshot_version
                                : null,
                        ] : null,
                    'objetivos' => $maniobra->relationLoaded('objetivos')
                        ? $maniobra->objetivos->map(fn ($objetivo): array => [
                            'id' => $objetivo->id,
                            'tipo' => $objetivo->tipo->value,
                            'estado' => $objetivo->estado->value,
                            'prioridad' => $objetivo->prioridad->value,
                            'titulo' => $objetivo->titulo,
                            'motivo' => $objetivo->motivo,
                            'es_principal' => (bool) $objetivo->pivot->es_principal,
                            'beneficio_estimado' => (int) $objetivo->pivot->beneficio_estimado,
                        ])->values()->all()
                        : [],
                    'custodia_temporal_activa' => $this->custodiaTemporalActiva(),
                    'pasos' => $maniobra->relationLoaded('pasos')
                        ? $maniobra->pasos->map(fn (TareaMovimiento $paso): array => [
                            'id' => $paso->id,
                            'secuencia' => $paso->secuencia_maniobra,
                            'estado' => $paso->estado->value,
                            'tipo_movimiento' => $paso->tipo_movimiento->value,
                            'tipo_paso' => $paso->tipo_paso_maniobra?->value,
                            'folio' => $paso->relationLoaded('folio') && $paso->folio ? [
                                'id' => $paso->folio->id,
                                'numero_folio' => $paso->folio->numero_folio,
                            ] : null,
                            'origen' => $this->extremoDeTarea($paso, 'Origen'),
                            'destino' => $this->extremoDeTarea($paso, 'Destino'),
                            'destino_logico' => $this->destinoLogicoDeTarea($paso),
                            'instruccion' => $paso->instruccion,
                        ])->values()->all()
                        : [],
                    'custodias_temporales' => $maniobra->relationLoaded('custodiasTemporales')
                        ? $maniobra->custodiasTemporales
                            ->where('estado', EstadoCustodiaTemporal::Activa)
                            ->map(fn ($custodia): array => [
                                'id' => $custodia->id,
                                'estado' => $custodia->estado->value,
                                'folio' => $custodia->relationLoaded('folio') && $custodia->folio ? [
                                    'id' => $custodia->folio->id,
                                    'numero_folio' => $custodia->folio->numero_folio,
                                ] : null,
                                'origen' => [
                                    'camara' => $custodia->relationLoaded('camaraOrigen') && $custodia->camaraOrigen ? [
                                        'id' => $custodia->camaraOrigen->id,
                                        'nombre' => $custodia->camaraOrigen->nombre,
                                    ] : null,
                                    'posicion' => $custodia->relationLoaded('posicionOrigen') && $custodia->posicionOrigen ? [
                                        'id' => $custodia->posicionOrigen->id,
                                        'etiqueta' => $custodia->posicionOrigen->etiqueta,
                                        'banda' => $custodia->posicionOrigen->banda,
                                        'posicion' => $custodia->posicionOrigen->posicion,
                                        'nivel' => $custodia->posicionOrigen->nivel,
                                    ] : null,
                                ],
                                'extraido_at' => $custodia->extraido_at?->toAtomString(),
                            ])->values()->all()
                        : [],
                ] : null;
            }),
            'secuencia_maniobra' => $this->secuencia_maniobra,
            'tipo_paso_maniobra' => $this->tipo_paso_maniobra?->value,
            'tipo_movimiento' => $this->tipo_movimiento->value,
            'estado' => $this->estado->value,
            'prioridad' => $this->prioridad->value,
            'punto_no_retorno' => $this->estado === EstadoTareaMovimiento::EnProceso,
            'folio' => $this->whenLoaded('folio', fn (): array => [
                'id' => $this->folio->id,
                'numero_folio' => $this->folio->numero_folio,
                'tipo_bulto' => $this->folio->tipo_bulto->value,
                'variedad' => $this->folio->variedad,
                'calibre' => $this->folio->calibre,
                'marca' => $this->folio->marca,
                'exportadora' => $this->folio->exportadora,
                'envase' => $this->folio->datos_externos['envase'] ?? null,
                'fecha_ingreso' => $this->folio->fecha_ingreso?->toAtomString(),
            ]),
            'origen' => $this->extremo('Origen'),
            'destino' => $this->extremo('Destino'),
            'destino_logico' => $this->destinoLogico(),
            'responsable' => $this->whenLoaded('responsable', fn (): ?array => $this->responsable ? [
                'id' => $this->responsable->id,
                'nombre' => $this->responsable->name,
            ] : null),
            'dispositivo' => $this->whenLoaded('dispositivo', fn (): ?array => $this->dispositivo ? [
                'id' => $this->dispositivo->id,
                'codigo' => $this->dispositivo->codigo,
                'nombre' => $this->dispositivo->nombre,
            ] : null),
            'reserva' => $this->whenLoaded('reservaActiva', function (): ?array {
                $reserva = $this->reservaActiva;

                return $reserva ? [
                    'id' => $reserva->id,
                    'estado' => $reserva->estado->value,
                    'tipo_compromiso' => $reserva->bloqueo_posicion_id !== null ? 'fisica' : 'claim',
                    'destino_reservado' => $reserva->bloqueo_posicion_id !== null,
                    'reservada_at' => $reserva->reservada_at?->toAtomString(),
                    'renovada_at' => $reserva->renovada_at?->toAtomString(),
                    'vence_at' => $reserva->vence_at?->toAtomString(),
                    'segundos_restantes' => $this->estado === EstadoTareaMovimiento::EnProceso
                        || $this->custodiaTemporalActiva()
                        ? null
                        : max(0, (int) now()->diffInSeconds($reserva->vence_at, false)),
                    'version' => $reserva->version,
                ] : null;
            }),
            'instruccion' => $this->instruccion,
            // Corrige también tareas existentes sin reescribir decisiones ni movimientos.
            'contexto' => $this->relationLoaded('folio') && $this->folio
                ? [...($this->contexto ?? []), 'formato' => $this->folio->datos_externos['envase'] ?? null]
                : ($this->contexto ?? []),
            'asumida_at' => $this->asumida_at?->toAtomString(),
            'iniciada_at' => $this->iniciada_at?->toAtomString(),
            'completada_at' => $this->completada_at?->toAtomString(),
            'cancelada_at' => $this->cancelada_at?->toAtomString(),
            'cancelacion' => $this->cancelada_at ? [
                'motivo' => $this->motivo_cancelacion,
                'reemplazada_por_tarea_id' => $this->reemplazada_por_tarea_id,
            ] : null,
            'version' => $this->version,
            'created_at' => $this->created_at?->toAtomString(),
        ];
    }

    private function custodiaTemporalActiva(): bool
    {
        $maniobra = $this->relationLoaded('maniobraOperacional')
            ? $this->maniobraOperacional
            : null;

        return $maniobra
            && $maniobra->relationLoaded('custodiasTemporales')
            && $maniobra->custodiasTemporales->contains(
                fn ($custodia): bool => $custodia->estado === EstadoCustodiaTemporal::Activa,
            );
    }

    /** @return array<string, mixed>|null */
    private function extremo(string $nombre): ?array
    {
        return $this->extremoDeTarea($this->resource, $nombre);
    }

    /** @return array<string, mixed>|null */
    private function extremoDeTarea(TareaMovimiento $tarea, string $nombre): ?array
    {
        $camara = $tarea->{"camara{$nombre}"};
        $posicion = $tarea->{"posicion{$nombre}"};

        if (! $camara) {
            return null;
        }

        return [
            'camara' => [
                'id' => $camara->id,
                'nombre' => $camara->nombre,
            ],
            'posicion' => $posicion ? [
                'id' => $posicion->id,
                'etiqueta' => $posicion->etiqueta,
                'banda' => $posicion->banda,
                'posicion' => $posicion->posicion,
                'nivel' => $posicion->nivel,
            ] : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function destinoLogico(): ?array
    {
        return $this->destinoLogicoDeTarea($this->resource);
    }

    /** @return array<string, mixed>|null */
    private function destinoLogicoDeTarea(TareaMovimiento $tarea): ?array
    {
        $contexto = $tarea->contexto ?? [];
        if (($contexto['tipo_decision'] ?? null) !== 'retiro_directo_anden'
            || empty($contexto['anden_id'])) {
            return null;
        }

        return [
            'tipo' => 'anden',
            'id' => $contexto['anden_id'],
            'nombre' => $contexto['anden_nombre'] ?? 'Andén',
            'carga_folio_id' => $contexto['carga_folio_id'] ?? null,
            'presencia_carga_anden_id' => $contexto['presencia_carga_anden_id'] ?? null,
        ];
    }
}

<?php

namespace App\Services\Retenciones;

use App\Enums\EstadoCarga;
use App\Enums\EstadoCargaFolio;
use App\Enums\EstadoManiobraOperacional;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoRetencionOperacional;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\TipoEventoCarga;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\EventoCarga;
use App\Models\Folio;
use App\Models\ReservaCargaFolio;
use App\Models\RetencionOperacionalFolio;
use App\Models\TareaMovimiento;
use App\Models\User;
use App\Services\Cargas\ServicioInstructivosCarga;
use App\Services\Cargas\ServicioTareasCarga;
use App\Services\Estiba\ServicioManiobrasOperacionales;
use App\Services\Estiba\ServicioPlanesOperacionales;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioRetencionesOperacionales
{
    public function __construct(
        private readonly ServicioTareasCarga $tareasCarga,
        private readonly ServicioPlanesOperacionales $planes,
        private readonly ServicioManiobrasOperacionales $maniobras,
        private readonly ServicioPlanSegregacionRetenidos $planificador,
    ) {}

    /**
     * @param array{
     *   estado_operacional: mixed,
     *   condicion_termica: mixed,
     *   habilitacion_almacenamiento: mixed
     * } $estadoAnterior
     */
    public function activar(
        Folio $folio,
        array $estadoAnterior,
        string $motivo,
        ?User $usuario = null,
    ): RetencionOperacionalFolio {
        return DB::transaction(function () use (
            $folio,
            $estadoAnterior,
            $motivo,
            $usuario,
        ): RetencionOperacionalFolio {
            $folio = Folio::query()->lockForUpdate()->findOrFail($folio->id);
            $retencion = RetencionOperacionalFolio::query()
                ->where('bloqueo_folio_id', $folio->id)
                ->lockForUpdate()
                ->first();
            $reservaCarga = ReservaCargaFolio::query()
                ->where('folio_id', $folio->id)
                ->with('asignacion.carga')
                ->lockForUpdate()
                ->first();

            if (! $retencion) {
                $retencion = RetencionOperacionalFolio::create([
                    'folio_id' => $folio->id,
                    'bloqueo_folio_id' => $folio->id,
                    'estado' => EstadoRetencionOperacional::Activa,
                    'motivo' => Str::limit(trim($motivo), 255, ''),
                    'estado_operacional_anterior' => $this->valor(
                        $estadoAnterior['estado_operacional'] ?? null,
                    ) ?? EstadoOperacionalFolio::PendientePrefrio->value,
                    'condicion_termica_anterior' => $this->valor(
                        $estadoAnterior['condicion_termica'] ?? null,
                    ),
                    'habilitacion_almacenamiento_anterior' => $this->valor(
                        $estadoAnterior['habilitacion_almacenamiento'] ?? null,
                    ),
                    'carga_id_original' => $reservaCarga?->asignacion?->carga_id,
                    'carga_folio_id_original' => $reservaCarga?->carga_folio_id,
                    'retenido_por_user_id' => $usuario?->id,
                    'retenido_at' => now(),
                    'contexto' => [
                        'carga_suspendida' => $reservaCarga !== null,
                        'flujo_restaurado' => false,
                    ],
                ]);
            } else {
                $retencion->update([
                    'motivo' => Str::limit(trim($motivo), 255, ''),
                    'contexto' => [
                        ...($retencion->contexto ?? []),
                        'retencion_reiterada_at' => now()->toIso8601String(),
                    ],
                ]);
            }

            if ($usuario) {
                $this->cancelarTrabajoIncompatible($folio, $retencion, $usuario);
            }
            $this->suspenderCarga($folio, $retencion, $reservaCarga, $usuario);

            if ($usuario) {
                $this->planificador->sincronizar($retencion->refresh(), $usuario);
            }

            return $retencion->refresh();
        }, attempts: 3);
    }

    public function liberar(Folio $folio, User $usuario): ?RetencionOperacionalFolio
    {
        return DB::transaction(function () use ($folio, $usuario): ?RetencionOperacionalFolio {
            $folio = Folio::query()->lockForUpdate()->findOrFail($folio->id);
            $retencion = RetencionOperacionalFolio::query()
                ->where('bloqueo_folio_id', $folio->id)
                ->lockForUpdate()
                ->first();

            if (! $retencion) {
                return null;
            }

            $this->planificador->cancelar(
                $retencion,
                $usuario,
                'La retención fue liberada; se retira la segregación pendiente.',
            );
            $retencion->update([
                'bloqueo_folio_id' => null,
                'estado' => EstadoRetencionOperacional::Liberada,
                'liberado_por_user_id' => $usuario->id,
                'liberado_at' => now(),
            ]);
            $this->restaurarCarga($folio, $retencion, $usuario);

            return $retencion->refresh();
        }, attempts: 3);
    }

    private function cancelarTrabajoIncompatible(
        Folio $folio,
        RetencionOperacionalFolio $retencion,
        User $usuario,
    ): void {
        $tareas = TareaMovimiento::query()
            ->with(['planOperacional', 'maniobraOperacional'])
            ->where('folio_id', $folio->id)
            ->whereIn('estado', [
                EstadoTareaMovimiento::Bloqueada->value,
                EstadoTareaMovimiento::Pendiente->value,
                EstadoTareaMovimiento::Asumida->value,
                EstadoTareaMovimiento::EnProceso->value,
            ])
            ->lockForUpdate()
            ->get()
            ->reject(fn (TareaMovimiento $tarea): bool => $tarea->planOperacional?->referencia_tipo
                === ServicioPlanSegregacionRetenidos::REFERENCIA
                && $tarea->planOperacional?->referencia_id === $retencion->id);

        foreach ($tareas->pluck('maniobraOperacional')->filter()->unique('id') as $maniobra) {
            if (in_array($maniobra->estado, [
                EstadoManiobraOperacional::EnEjecucion,
                EstadoManiobraOperacional::PausadaDiscrepancia,
            ], true)) {
                continue;
            }
            $this->maniobras->cancelarReversible(
                $maniobra,
                $usuario,
                'Retención prioritaria del pallet.',
            );
        }

        foreach ($tareas->whereNull('maniobra_operacional_id') as $tarea) {
            $this->planes->cancelarPorReplanificacion(
                $tarea,
                $usuario,
                'Retención prioritaria del pallet.',
            );
        }
    }

    private function suspenderCarga(
        Folio $folio,
        RetencionOperacionalFolio $retencion,
        ?ReservaCargaFolio $reserva,
        ?User $usuario,
    ): void {
        if (! $reserva) {
            return;
        }

        $asignacion = CargaFolio::query()
            ->with('carga')
            ->lockForUpdate()
            ->findOrFail($reserva->carga_folio_id);
        $carga = Carga::query()->lockForUpdate()->findOrFail($asignacion->carga_id);
        $reserva->delete();
        $asignacion->update([
            'estado' => EstadoCargaFolio::Descartado,
            'finalizado_por_user_id' => $usuario?->id,
            'finalizado_at' => now(),
            'motivo_finalizacion' => 'Retención prioritaria: '.$retencion->motivo,
        ]);
        $carga->update([
            'version' => $carga->version + 1,
            'actualizada_por_user_id' => $usuario?->id ?? $carga->actualizada_por_user_id,
        ]);

        if ($usuario) {
            EventoCarga::create([
                'carga_id' => $carga->id,
                'folio_id' => $folio->id,
                'user_id' => $usuario->id,
                'tipo' => TipoEventoCarga::FolioDesasignado,
                'datos' => [
                    'causa' => 'retencion_operacional',
                    'retencion_id' => $retencion->id,
                    'motivo' => $retencion->motivo,
                ],
            ]);
        }
        $this->tareasCarga->sincronizar($carga);
    }

    private function restaurarCarga(
        Folio $folio,
        RetencionOperacionalFolio $retencion,
        User $usuario,
    ): void {
        $contexto = $retencion->contexto ?? [];
        $carga = $retencion->carga_id_original
            ? Carga::query()->lockForUpdate()->find($retencion->carga_id_original)
            : null;
        $motivoNoRestaurado = null;

        if (! $carga) {
            $motivoNoRestaurado = 'sin_carga_original';
        } elseif (! in_array($carga->estado, [
            EstadoCarga::Borrador,
            ...EstadoCarga::visiblesEnOperacion(),
        ], true)) {
            $motivoNoRestaurado = 'carga_finalizada';
        } elseif ($folio->estado_operacional !== EstadoOperacionalFolio::Disponible
            || $folio->habilitacion_almacenamiento !== HabilitacionAlmacenamientoFolio::Habilitado
            || ! $folio->ubicacionActual()->exists()) {
            $motivoNoRestaurado = 'folio_no_elegible';
        } elseif (ReservaCargaFolio::query()->where('folio_id', $folio->id)->exists()) {
            $motivoNoRestaurado = 'folio_ya_asignado';
        } elseif (CargaFolio::query()
            ->where('carga_id', $carga->id)
            ->whereHas('reservaActiva')
            ->lockForUpdate()
            ->count() >= 26) {
            $motivoNoRestaurado = 'carga_sin_cupo';
        }

        $instructivoId = CargaFolio::query()->find($retencion->carga_folio_id_original)?->instructivo_embarque_id;
        if ($motivoNoRestaurado === null && $instructivoId !== null) {
            try {
                app(ServicioInstructivosCarga::class)->paraAsignacion($carga, $instructivoId, 1);
            } catch (\DomainException) {
                $motivoNoRestaurado = 'instructivo_sin_cupo';
            }
        }

        if ($motivoNoRestaurado) {
            $retencion->update([
                'contexto' => [
                    ...$contexto,
                    'flujo_restaurado' => false,
                    'motivo_no_restaurado' => $motivoNoRestaurado,
                ],
            ]);

            return;
        }

        $asignacion = CargaFolio::create([
            'carga_id' => $carga->id,
            'instructivo_embarque_id' => $instructivoId,
            'folio_id' => $folio->id,
            'estado' => EstadoCargaFolio::Pendiente,
            'reemplaza_a_carga_folio_id' => $retencion->carga_folio_id_original,
            'asignado_por_user_id' => $usuario->id,
            'asignado_at' => now(),
        ]);
        ReservaCargaFolio::create([
            'folio_id' => $folio->id,
            'carga_folio_id' => $asignacion->id,
        ]);
        $carga->update([
            'version' => $carga->version + 1,
            'actualizada_por_user_id' => $usuario->id,
        ]);
        EventoCarga::create([
            'carga_id' => $carga->id,
            'folio_id' => $folio->id,
            'user_id' => $usuario->id,
            'tipo' => TipoEventoCarga::FolioAsignado,
            'datos' => [
                'causa' => 'liberacion_retencion',
                'retencion_id' => $retencion->id,
                'carga_folio_id_anterior' => $retencion->carga_folio_id_original,
            ],
        ]);
        $retencion->update([
            'contexto' => [
                ...$contexto,
                'flujo_restaurado' => true,
                'carga_folio_id_restaurado' => $asignacion->id,
            ],
        ]);
        if ($carga->estado !== EstadoCarga::Borrador) {
            $this->tareasCarga->sincronizar($carga);
        }
    }

    private function valor(mixed $valor): ?string
    {
        if ($valor instanceof \BackedEnum) {
            return (string) $valor->value;
        }

        return filled($valor) ? (string) $valor : null;
    }
}

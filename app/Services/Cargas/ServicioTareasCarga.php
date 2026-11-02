<?php

namespace App\Services\Cargas;

use App\Enums\EstadoCarga;
use App\Enums\EstadoCargaFolio;
use App\Enums\EstadoTareaCarga;
use App\Enums\TipoEventoCarga;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\EventoCarga;
use App\Models\Folio;
use App\Models\Movimiento;
use App\Models\TareaCarga;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServicioTareasCarga
{
    /**
     * Mantiene una tarea compartida por cada cámara que todavía contiene
     * folios pendientes de una carga publicada.
     *
     * @return Collection<int, TareaCarga>
     */
    public function sincronizar(Carga $carga): Collection
    {
        $asignaciones = CargaFolio::query()
            ->where('carga_id', $carga->id)
            // FOR UPDATE debe recorrer solo esta carga, no el índice global de estados.
            ->when(DB::getDriverName() === 'mysql', fn ($q) => $q->forceIndex('carga_folios_carga_id_estado_index'))
            ->whereIn('estado', [
                EstadoCargaFolio::Pendiente->value,
                EstadoCargaFolio::ConIncidencia->value,
            ])
            ->whereHas('reservaActiva')
            ->with('folio.ubicacionActual.posicion')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $camarasPendientes = $asignaciones
            ->map(fn (CargaFolio $asignacion): ?string => $asignacion
                ->folio
                ->ubicacionActual
                ?->posicion
                ?->camara_id)
            ->filter()
            ->unique()
            ->values();

        $tareas = TareaCarga::query()
            ->where('carga_id', $carga->id)
            ->orderBy('camara_origen_id')
            ->lockForUpdate()
            ->get();

        foreach ($camarasPendientes as $camaraId) {
            $tarea = $tareas->firstWhere('camara_origen_id', $camaraId);

            if (! $tarea) {
                $tarea = TareaCarga::create([
                    'carga_id' => $carga->id,
                    'camara_origen_id' => $camaraId,
                    'estado' => EstadoTareaCarga::Pendiente,
                ]);
                $tareas->push($tarea);
            } elseif (in_array($tarea->estado, [
                EstadoTareaCarga::Completada,
                EstadoTareaCarga::Cancelada,
            ], true)) {
                $tarea->update([
                    'estado' => EstadoTareaCarga::Pendiente,
                    'responsable_user_id' => null,
                    'asumida_at' => null,
                    'completada_at' => null,
                ]);
            }
        }

        foreach ($tareas as $tarea) {
            if (! $camarasPendientes->contains($tarea->camara_origen_id)
                && $tarea->estado !== EstadoTareaCarga::Completada) {
                $tarea->update([
                    'estado' => EstadoTareaCarga::Completada,
                    'completada_at' => now(),
                ]);
            }
        }

        return TareaCarga::query()
            ->where('carga_id', $carga->id)
            ->orderBy('camara_origen_id')
            ->get();
    }

    public function registrarMovimiento(Folio $folio, Movimiento $movimiento, User $usuario): void
    {
        $asignacionLeida = CargaFolio::query()
            ->where('folio_id', $folio->id)
            ->whereHas('reservaActiva')
            ->first();

        if (! $asignacionLeida) {
            return;
        }

        $carga = Carga::query()->lockForUpdate()->findOrFail($asignacionLeida->carga_id);
        $asignacion = CargaFolio::query()
            ->whereKey($asignacionLeida->id)
            ->whereHas('reservaActiva')
            ->lockForUpdate()
            ->first();

        if (! $asignacion || ! in_array($asignacion->estado, [
            EstadoCargaFolio::Pendiente,
            EstadoCargaFolio::ConIncidencia,
        ], true)) {
            return;
        }

        if (! in_array($carga->estado, EstadoCarga::visiblesEnOperacion(), true)) {
            return;
        }

        $nuevoEstado = $carga->estado === EstadoCarga::Pendiente
            ? EstadoCarga::EnPreparacion
            : $carga->estado;

        $carga->update([
            'estado' => $nuevoEstado,
            'version' => $carga->version + 1,
            'actualizada_por_user_id' => $usuario->id,
        ]);

        EventoCarga::create([
            'carga_id' => $carga->id,
            'folio_id' => $folio->id,
            'user_id' => $usuario->id,
            'tipo' => TipoEventoCarga::FolioMovido,
            'datos' => [
                'version' => $carga->version,
                'movimiento_id' => $movimiento->id,
                'camara_origen_id' => $movimiento->camara_origen_id,
                'posicion_origen_id' => $movimiento->posicion_origen_id,
                'camara_destino_id' => $movimiento->camara_destino_id,
                'posicion_destino_id' => $movimiento->posicion_destino_id,
            ],
        ]);

        $this->sincronizar($carga);
    }
}

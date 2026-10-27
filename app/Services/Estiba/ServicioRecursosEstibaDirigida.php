<?php

namespace App\Services\Estiba;

use App\Exceptions\ConflictoOperacion;
use App\Models\Posicion;
use App\Models\ReservaTareaMovimiento;
use App\Models\TareaMovimiento;
use App\Models\UbicacionActual;

/** Validación física bajo los bloqueos transaccionales de la cámara y de la tarea. */
class ServicioRecursosEstibaDirigida
{
    public function validarAcceso(Posicion $posicion, TareaMovimiento $tarea, bool $destino = true): void
    {
        $frente = Posicion::query()->where('camara_id', $posicion->camara_id)
            ->where('banda', $posicion->banda)->where('nivel', $posicion->nivel)
            ->where('posicion', '>', $posicion->posicion)->pluck('id');
        if (UbicacionActual::query()->whereIn('posicion_id', $frente)
            ->where('folio_id', '!=', $tarea->folio_id)->lockForUpdate()->exists()) {
            throw new ConflictoOperacion('El acceso a la posición está bloqueado por un pallet delante. Actualiza el destino.');
        }
        if (ReservaTareaMovimiento::query()->whereIn('bloqueo_posicion_id', $frente)
            ->where('tarea_movimiento_id', '!=', $tarea->id)->conCompromisoVigente()->lockForUpdate()->exists()) {
            throw new ConflictoOperacion('El acceso a la posición está comprometido por otra labor.');
        }
        if ($destino && UbicacionActual::query()->where('posicion_id', $posicion->id)->lockForUpdate()->exists()) {
            throw new ConflictoOperacion('La posición de destino ya se encuentra ocupada.');
        }
    }

    public function validarTarea(TareaMovimiento $tarea): void
    {
        if ($tarea->posicion_origen_id) {
            if (! UbicacionActual::query()->where('folio_id', $tarea->folio_id)
                ->where('posicion_id', $tarea->posicion_origen_id)->lockForUpdate()->exists()) {
                throw new ConflictoOperacion('El pallet ya no está en el origen de la tarea.');
            }
            $this->validarAcceso(Posicion::findOrFail($tarea->posicion_origen_id), $tarea, false);
        }
        if ($tarea->posicion_destino_id) {
            $this->validarAcceso(Posicion::findOrFail($tarea->posicion_destino_id), $tarea);
        }
    }
}

<?php

namespace App\Services\Cargas;

use App\Enums\EstadoCargaFolio;
use App\Enums\EstadoReingresoCamara;
use App\Enums\OrigenReingresoCamara;
use App\Exceptions\ConflictoOperacion;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Folio;
use App\Models\Movimiento;
use App\Models\ReingresoFolioCamara;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * Pallets que salieron de la cámara por una carga y deben volver: el folio
 * conserva su número y queda bloqueado para otras cargas hasta ubicarse.
 */
class ServicioReingresoCamara
{
    /** Se llama dentro de la transacción que ya bloqueó la carga y la asignación. */
    public function registrar(
        Folio $folio,
        ?Carga $carga,
        ?CargaFolio $asignacion,
        OrigenReingresoCamara $origen,
        string $motivo,
        User $usuario,
        ?EstadoCargaFolio $estadoPrevio = null,
    ): ReingresoFolioCamara {
        try {
            return ReingresoFolioCamara::create([
                'temporada_id' => $carga?->temporada_id ?? $folio->temporada_id,
                'folio_id' => $folio->id,
                'folio_pendiente_id' => $folio->id,
                'carga_id' => $carga?->id,
                'carga_folio_id' => $asignacion?->id,
                'origen' => $origen,
                'estado' => EstadoReingresoCamara::Pendiente,
                'estado_carga_previo' => $estadoPrevio?->value,
                'anden_id' => $asignacion?->anden_id,
                'motivo' => $motivo,
                'registrado_por_user_id' => $usuario->id,
                'registrado_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23000') {
                throw new ConflictoOperacion(
                    "El folio {$folio->numero_folio} ya tiene un reingreso a cámara pendiente.",
                    previous: $exception,
                );
            }

            throw $exception;
        }
    }

    public function pendiente(string $folioId, bool $bloquear = false): ?ReingresoFolioCamara
    {
        return ReingresoFolioCamara::query()
            ->where('folio_pendiente_id', $folioId)
            ->when($bloquear, fn ($consulta) => $consulta->lockForUpdate())
            ->first();
    }

    /** El pallet volvió a una posición de cámara: el reingreso se cierra con ese movimiento. */
    public function cerrarPorUbicacion(Folio $folio, Movimiento $movimiento, User $usuario): ?ReingresoFolioCamara
    {
        $reingreso = $this->pendiente($folio->id, bloquear: true);
        if (! $reingreso) {
            return null;
        }

        $reingreso->update([
            'estado' => EstadoReingresoCamara::Ubicado,
            'folio_pendiente_id' => null,
            'movimiento_ubicacion_id' => $movimiento->id,
            'camara_ubicacion_id' => $movimiento->camara_destino_id,
            'ubicado_por_user_id' => $usuario->id,
            'ubicado_at' => now(),
        ]);

        return $reingreso;
    }
}

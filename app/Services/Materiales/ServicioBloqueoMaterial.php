<?php

namespace App\Services\Materiales;

use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoReservaMaterial;
use App\Enums\TipoEventoBloqueoMaterial;
use App\Exceptions\ConflictoOperacion;
use App\Models\EventoBloqueoMaterial;
use App\Models\FolioMaterial;
use App\Models\User;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ServicioBloqueoMaterial
{
    public function bloquear(
        FolioMaterial $folioMaterial,
        string $operacionId,
        string $motivo,
        User $usuario,
    ): EventoBloqueoMaterial {
        return $this->cambiar(
            $folioMaterial,
            $operacionId,
            TipoEventoBloqueoMaterial::Bloqueado,
            trim($motivo),
            $usuario,
        );
    }

    public function liberar(
        FolioMaterial $folioMaterial,
        string $operacionId,
        string $motivo,
        User $usuario,
    ): EventoBloqueoMaterial {
        return $this->cambiar(
            $folioMaterial,
            $operacionId,
            TipoEventoBloqueoMaterial::Liberado,
            trim($motivo),
            $usuario,
        );
    }

    public function bloquearPorVencimiento(FolioMaterial $material, string $operacionId): EventoBloqueoMaterial
    {
        return $this->cambiar($material, $operacionId, TipoEventoBloqueoMaterial::Bloqueado, 'Vencido', null);
    }

    private function cambiar(
        FolioMaterial $folioMaterial,
        string $operacionId,
        TipoEventoBloqueoMaterial $tipo,
        string $motivo,
        ?User $usuario,
    ): EventoBloqueoMaterial {
        try {
            return DB::transaction(function () use (
                $folioMaterial,
                $operacionId,
                $tipo,
                $motivo,
                $usuario,
            ): EventoBloqueoMaterial {
                $existente = EventoBloqueoMaterial::query()
                    ->where('operacion_id', $operacionId)
                    ->lockForUpdate()
                    ->first();

                if ($existente) {
                    return $this->validarReintento(
                        $existente,
                        $folioMaterial,
                        $tipo,
                        $motivo,
                        $usuario,
                    );
                }

                $material = FolioMaterial::query()
                    ->with(['folio.ubicacionActual', 'item.cliente.cliente'])
                    ->lockForUpdate()
                    ->findOrFail($folioMaterial->folio_id);
                $folio = $material->folio;

                if (! $folio?->activo || (float) $material->cantidad_actual <= 0) {
                    throw new DomainException('El folio no posee existencia activa para cambiar su bloqueo.');
                }

                $estadoAnterior = $folio->estado_operacional;
                $sistema = $usuario === null;
                if ($sistema && $material->bloqueado_por_vencimiento) {
                    return $this->cargar($material->eventosBloqueo()->where('tipo', TipoEventoBloqueoMaterial::Bloqueado->value)->where('motivo', 'Vencido')->latest('ocurrido_at')->firstOrFail());
                }
                if ($sistema && ! $material->estaVencido()) {
                    throw new DomainException('El bloqueo automático requiere un folio vencido.');
                }
                if ($tipo === TipoEventoBloqueoMaterial::Liberado) {
                    $material->asegurarVigente();
                    if ($material->bloqueado_por_vencimiento) {
                        throw new DomainException('Corrige la fecha de vencimiento mediante la acción supervisada.');
                    }
                }

                if ($tipo === TipoEventoBloqueoMaterial::Bloqueado) {
                    if (! ($sistema && $estadoAnterior === EstadoOperacionalFolio::Bloqueado) && ! in_array($estadoAnterior, [
                        EstadoOperacionalFolio::Disponible,
                        EstadoOperacionalFolio::PendienteUbicacion,
                    ], true)) {
                        throw new DomainException('El folio no se encuentra disponible para ser bloqueado.');
                    }

                    $this->asegurarSinReservas($material);
                    $estadoResultante = EstadoOperacionalFolio::Bloqueado;
                    $material->update([
                        'motivo_bloqueo' => $motivo,
                        ...($sistema ? [
                            'bloqueado_por_vencimiento' => true,
                            'motivo_bloqueo_previo_vencimiento' => $material->motivo_bloqueo,
                        ] : []),
                    ]);
                } else {
                    if ($estadoAnterior !== EstadoOperacionalFolio::Bloqueado) {
                        throw new DomainException('El folio no se encuentra bloqueado.');
                    }

                    $estadoResultante = $folio->ubicacionActual
                        ? EstadoOperacionalFolio::Disponible
                        : EstadoOperacionalFolio::PendienteUbicacion;
                    $material->update(['motivo_bloqueo' => null]);
                }

                $folio->update(['estado_operacional' => $estadoResultante]);
                $evento = EventoBloqueoMaterial::create([
                    'operacion_id' => $operacionId,
                    'folio_id' => $material->folio_id,
                    'tipo' => $tipo,
                    'estado_anterior' => $estadoAnterior,
                    'estado_resultante' => $estadoResultante,
                    'motivo' => $motivo,
                    'user_id' => $usuario?->id,
                    'ocurrido_at' => now(),
                ]);

                return $this->cargar($evento);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existente = EventoBloqueoMaterial::query()
                ->where('operacion_id', $operacionId)
                ->first();

            if ($existente) {
                return $this->validarReintento(
                    $existente,
                    $folioMaterial,
                    $tipo,
                    $motivo,
                    $usuario,
                );
            }

            throw new ConflictoOperacion(
                'El cambio de bloqueo entró en conflicto con otra operación concurrente.',
                previous: $exception,
            );
        }
    }

    private function validarReintento(
        EventoBloqueoMaterial $evento,
        FolioMaterial $folioMaterial,
        TipoEventoBloqueoMaterial $tipo,
        string $motivo,
        ?User $usuario,
    ): EventoBloqueoMaterial {
        if ($evento->folio_id !== $folioMaterial->folio_id
            || $evento->tipo !== $tipo
            || $evento->user_id !== $usuario?->id
            || $evento->motivo !== $motivo) {
            throw new ConflictoOperacion(
                'El UUID del cambio de bloqueo ya fue utilizado con otros datos.',
            );
        }

        return $this->cargar($evento);
    }

    private function asegurarSinReservas(FolioMaterial $material): void
    {
        if ((float) $material->cantidad_reservada > 0
            || $material->reservas()
                ->where('estado', EstadoReservaMaterial::Activa->value)
                ->lockForUpdate()
                ->exists()
            || $material->reservasTransformacion()
                ->where('estado', EstadoReservaMaterial::Activa->value)
                ->lockForUpdate()
                ->exists()) {
            throw new DomainException(
                'El folio posee reservas activas. Cancela o libera esas reservas antes de bloquearlo.',
            );
        }
    }

    public function cargar(EventoBloqueoMaterial $evento): EventoBloqueoMaterial
    {
        return $evento->load([
            'folioMaterial.folio.ubicacionActual.posicion.camara',
            'folioMaterial.item.cliente.cliente',
            'usuario:id,name',
        ]);
    }
}

<?php

namespace App\Services\Estiba;

use App\Enums\EstadoCustodiaTemporal;
use App\Enums\EstadoManiobraOperacional;
use App\Enums\EstadoPlanOperacional;
use App\Enums\EstadoPosicion;
use App\Enums\EstadoReservaTareaMovimiento;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\ModoBandaOperacional;
use App\Enums\TipoEspacioPreparacionSag;
use App\Enums\TipoMovimiento;
use App\Enums\UsoBandaOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Models\BandaOperacional;
use App\Models\Camara;
use App\Models\CustodiaTemporalManiobra;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\ManiobraOperacional;
use App\Models\Movimiento;
use App\Models\Posicion;
use App\Models\ReservaBandaManiobra;
use App\Models\ReservaPosicionInspeccionSag;
use App\Models\ReservaTareaMovimiento;
use App\Models\TareaMovimiento;
use App\Models\UbicacionActual;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ServicioReservasTareasMovimiento
{
    public function validarDestinoManual(
        ?string $posicionDestinoId,
        ?string $numeroFolio = null,
    ): void {
        if (! $posicionDestinoId) {
            return;
        }

        $reserva = ReservaTareaMovimiento::query()
            ->where('bloqueo_posicion_id', $posicionDestinoId)
            ->lockForUpdate()
            ->first();

        if ($reserva) {
            if ($this->estaVencida($reserva)) {
                $this->expirar($reserva);
            } else {
                throw new ConflictoOperacion(
                    'La posición de destino se encuentra reservada por una tarea operacional.',
                );
            }
        }

        $folioId = $numeroFolio
            ? Folio::query()->where('numero_folio', $numeroFolio)->value('id')
            : null;
        $this->validarReservaPreparacionSag($posicionDestinoId, $folioId);
    }

    public function asumir(
        TareaMovimiento $tarea,
        User $usuario,
        Dispositivo $dispositivo,
    ): ReservaTareaMovimiento {
        $reserva = $this->reservaActivaTarea($tarea->id, true);

        if ($reserva && $this->estaVencida($reserva)) {
            $this->expirar($reserva);
            $tarea->refresh();
            $reserva = null;
        }

        if ($reserva) {
            if ($reserva->user_id !== $usuario->id
                || $reserva->dispositivo_id !== $dispositivo->id) {
                throw new ConflictoOperacion(
                    'La tarea ya fue reservada por otro camarero o dispositivo.',
                );
            }

            return $this->renovarReserva($reserva);
        }

        if ($tarea->responsable_user_id !== null
            || $tarea->estado !== EstadoTareaMovimiento::Pendiente) {
            throw new ConflictoOperacion('La tarea cambió de estado antes de ser reservada.');
        }

        // En horizonte rolling, asumir significa exclusivamente reclamar la tarea.
        // Batch conserva el comportamiento histórico para planes estáticos.
        $posicion = $this->esHorizonteBatch($tarea)
            ? $this->bloquearDestinoDisponible($tarea)
            : null;

        if ($posicion) {
            $this->validarDestinoSinConflicto(
                $posicion->id,
                folioId: $tarea->folio_id,
            );
        }

        $ahora = now();

        try {
            $reserva = ReservaTareaMovimiento::create([
                'tarea_movimiento_id' => $tarea->id,
                'posicion_destino_id' => $posicion?->id,
                'bloqueo_tarea_id' => $tarea->id,
                'bloqueo_posicion_id' => $posicion?->id,
                'estado' => EstadoReservaTareaMovimiento::Activa,
                'user_id' => $usuario->id,
                'dispositivo_id' => $dispositivo->id,
                'reservada_at' => $ahora,
                'renovada_at' => $ahora,
                'vence_at' => $this->nuevoVencimiento(),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConflictoOperacion(
                'La tarea o su posición de destino acaba de ser reservada por otro camarero.',
                previous: $exception,
            );
        }
        $this->incrementarRevisionCamara($reserva->posicion_destino_id);

        $tarea->update([
            'estado' => EstadoTareaMovimiento::Asumida,
            'responsable_user_id' => $usuario->id,
            'dispositivo_id' => $dispositivo->id,
            'asumida_at' => $ahora,
            'version' => $tarea->version + 1,
        ]);

        return $reserva;
    }

    public function materializarDestino(
        TareaMovimiento $tarea,
        Posicion $posicion,
        User $usuario,
        Dispositivo $dispositivo,
        ?int $versionTarea = null,
        ?int $versionPlan = null,
        ?int $versionCamara = null,
    ): ReservaTareaMovimiento {
        $tareaBloqueada = TareaMovimiento::query()
            ->with('planOperacional')
            ->lockForUpdate()
            ->findOrFail($tarea->id);
        $reserva = $this->reservaActivaTarea($tareaBloqueada->id, true);

        if (! $reserva || $this->estaVencida($reserva)) {
            if ($reserva) {
                $this->expirar($reserva);
            }
            throw new ConflictoOperacion(
                'La tarea perdió su claim; vuelva a asumirla antes de reservar destino.',
            );
        }

        $this->validarPropietario($reserva, $usuario, $dispositivo);
        if ($tareaBloqueada->estado !== EstadoTareaMovimiento::Asumida) {
            throw new ConflictoOperacion('Solo una tarea asumida y no iniciada puede recibir un nuevo destino.');
        }
        if ($versionTarea !== null && $tareaBloqueada->version !== $versionTarea) {
            throw new ConflictoOperacion('La tarea cambió desde el snapshot utilizado por la tablet.');
        }
        if ($versionPlan !== null && $tareaBloqueada->planOperacional->version !== $versionPlan) {
            throw new ConflictoOperacion('El plan cambió desde el snapshot utilizado por la tablet.');
        }

        $posicionBloqueada = Posicion::query()->lockForUpdate()->findOrFail($posicion->id);
        $camara = Camara::query()->lockForUpdate()->findOrFail($posicionBloqueada->camara_id);
        if ($versionCamara !== null && $camara->version_plano !== $versionCamara) {
            throw new ConflictoOperacion('La cámara cambió desde el snapshot utilizado por la tablet.');
        }
        if ($posicionBloqueada->estado !== EstadoPosicion::Activa) {
            throw new ConflictoOperacion('La posición propuesta ya no está habilitada.');
        }
        if (UbicacionActual::query()
            ->where('posicion_id', $posicionBloqueada->id)
            ->lockForUpdate()
            ->exists()) {
            throw new ConflictoOperacion('La posición propuesta ya se encuentra ocupada.');
        }

        $this->validarBandaOperacional($posicionBloqueada, $tareaBloqueada);
        $this->validarDestinoParaTipo($tareaBloqueada, $posicionBloqueada);
        $this->validarDestinoSinConflicto(
            $posicionBloqueada->id,
            $reserva->id,
            $tareaBloqueada->folio_id,
        );

        $destinoAnterior = $reserva->posicion_destino_id;
        if ($destinoAnterior && $destinoAnterior !== $posicionBloqueada->id) {
            $this->incrementarRevisionCamara($destinoAnterior);
        }

        try {
            $reserva->update([
                'posicion_destino_id' => $posicionBloqueada->id,
                'bloqueo_posicion_id' => $posicionBloqueada->id,
                'renovada_at' => now(),
                'vence_at' => $this->nuevoVencimiento(),
                'version' => $reserva->version + 1,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConflictoOperacion(
                'La posición propuesta acaba de ser reservada por otra tarea.',
                previous: $exception,
            );
        }

        $tareaBloqueada->update([
            'camara_destino_id' => $posicionBloqueada->camara_id,
            'posicion_destino_id' => $posicionBloqueada->id,
            'version' => $tareaBloqueada->version + 1,
        ]);
        $this->incrementarRevisionCamara($posicionBloqueada->id);

        return $reserva->refresh();
    }

    public function iniciar(
        TareaMovimiento $tarea,
        User $usuario,
        Dispositivo $dispositivo,
    ): ReservaTareaMovimiento {
        $tareaBloqueada = TareaMovimiento::query()
            ->with('planOperacional')
            ->lockForUpdate()
            ->findOrFail($tarea->id);
        $reserva = $this->reservaActivaTarea($tareaBloqueada->id, true);

        if (! $reserva || $this->estaVencida($reserva)) {
            if ($reserva) {
                $this->expirar($reserva);
            }
            throw new ConflictoOperacion('La tarea perdió su reserva antes de iniciar el movimiento.');
        }
        $this->validarPropietario($reserva, $usuario, $dispositivo);

        if ($tareaBloqueada->estado === EstadoTareaMovimiento::EnProceso) {
            return $reserva;
        }
        if ($tareaBloqueada->estado !== EstadoTareaMovimiento::Asumida) {
            throw new ConflictoOperacion('Solo una tarea asumida puede iniciar movimiento físico.');
        }
        if ($tareaBloqueada->tipo_movimiento !== TipoMovimiento::Retiro
            && (! $tareaBloqueada->posicion_destino_id || ! $reserva->bloqueo_posicion_id)) {
            throw new ConflictoOperacion(
                'La tarea todavía no posee un destino físico validado y reservado.',
            );
        }
        if ($tareaBloqueada->posicion_destino_id) {
            $this->validarBandaOperacional(
                Posicion::query()->lockForUpdate()->findOrFail($tareaBloqueada->posicion_destino_id),
                $tareaBloqueada,
            );
        }

        $otraEnProceso = TareaMovimiento::query()
            ->where('id', '!=', $tareaBloqueada->id)
            ->where('estado', EstadoTareaMovimiento::EnProceso->value)
            ->where('responsable_user_id', $usuario->id)
            ->where('dispositivo_id', $dispositivo->id)
            ->lockForUpdate()
            ->exists();
        if ($otraEnProceso) {
            throw new ConflictoOperacion(
                'El camarero ya posee otro pallet físicamente en movimiento en esta tablet.',
            );
        }

        $tareaBloqueada->update([
            'estado' => EstadoTareaMovimiento::EnProceso,
            'iniciada_at' => now(),
            'version' => $tareaBloqueada->version + 1,
        ]);

        // Desde aquí el lease deja de ser descartable: esta reserva no expirará
        // automáticamente hasta completar el movimiento o registrar incidencia.
        return $this->renovarReserva($reserva);
    }

    public function renovar(
        TareaMovimiento $tarea,
        User $usuario,
        Dispositivo $dispositivo,
    ): ?ReservaTareaMovimiento {
        $reserva = $this->reservaActivaTarea($tarea->id, true);

        if (! $reserva) {
            throw new ConflictoOperacion('La tarea no posee una reserva activa.');
        }
        if ($this->estaVencida($reserva)) {
            $this->expirar($reserva);

            return null;
        }

        $this->validarPropietario($reserva, $usuario, $dispositivo);

        return $this->renovarReserva($reserva);
    }

    public function liberar(
        TareaMovimiento $tarea,
        User $usuario,
        Dispositivo $dispositivo,
        string $motivo = 'Liberada por el camarero.',
    ): void {
        $tareaBloqueada = TareaMovimiento::query()->lockForUpdate()->findOrFail($tarea->id);
        if ($tareaBloqueada->estado === EstadoTareaMovimiento::EnProceso) {
            throw new ConflictoOperacion(
                'Una tarea físicamente en movimiento no puede liberarse; debe completarse o entrar en incidencia.',
            );
        }

        $reserva = $this->reservaActivaTarea($tareaBloqueada->id, true);

        if (! $reserva) {
            if ($tareaBloqueada->estado === EstadoTareaMovimiento::Pendiente
                && $tareaBloqueada->responsable_user_id === null) {
                return;
            }

            throw new ConflictoOperacion('La tarea no posee una reserva activa.');
        }

        if ($this->estaVencida($reserva)) {
            $this->expirar($reserva);

            return;
        }

        $this->validarPropietario($reserva, $usuario, $dispositivo);
        if ($this->estaProtegidaPorMovimiento($reserva)) {
            throw new ConflictoOperacion('Una tarea con custodia activa no puede liberarse.');
        }
        $ahora = now();
        $this->finalizarReserva($reserva, EstadoReservaTareaMovimiento::Liberada, [
            'liberada_at' => $ahora,
            'motivo_liberacion' => trim($motivo),
        ]);
        $this->devolverTareaALaBandeja($reserva->tarea_movimiento_id);
    }

    /**
     * Libera el claim o destino de una tarea que será reemplazada por una
     * decisión rolling más prioritaria. Nunca atraviesa el punto de no retorno.
     */
    public function liberarParaReplanificacion(
        TareaMovimiento $tarea,
        string $motivo,
    ): bool {
        $tareaBloqueada = TareaMovimiento::query()->lockForUpdate()->findOrFail($tarea->id);

        if ($tareaBloqueada->estado === EstadoTareaMovimiento::EnProceso) {
            return false;
        }

        $reserva = $this->reservaActivaTarea($tareaBloqueada->id, true);
        if ($reserva) {
            $this->finalizarReserva($reserva, EstadoReservaTareaMovimiento::Liberada, [
                'liberada_at' => now(),
                'motivo_liberacion' => trim($motivo),
            ]);
        }

        return true;
    }

    public function validarParaMovimiento(
        ?TareaMovimiento $tarea,
        Folio $folio,
        TipoMovimiento $tipo,
        ?string $posicionOrigenId,
        ?string $posicionDestinoId,
        User $usuario,
        Dispositivo $dispositivo,
    ): ?TareaMovimiento {
        $this->expirarRelacionadas($folio->id, $posicionDestinoId);
        $this->validarPalletManiobraActiva(
            $folio->id,
            $tarea?->maniobra_operacional_id,
        );

        if (! $tarea) {
            $this->validarBandasSinManiobra($posicionOrigenId, $posicionDestinoId);
            if (CustodiaTemporalManiobra::query()
                ->where('bloqueo_folio_id', $folio->id)
                ->lockForUpdate()
                ->exists()) {
                throw new ConflictoOperacion(
                    'El pallet está bajo custodia temporal de una maniobra activa.',
                );
            }
            if ($this->reservaActivaFolio($folio->id, true)) {
                throw new ConflictoOperacion(
                    'El pallet se encuentra reservado por una tarea operacional.',
                );
            }
            if ($posicionDestinoId && $this->reservaActivaDestino($posicionDestinoId, true)) {
                throw new ConflictoOperacion(
                    'La posición de destino se encuentra reservada por otra tarea.',
                );
            }
            if ($posicionDestinoId) {
                $this->validarReservaPreparacionSag($posicionDestinoId, $folio->id);
            }

            return null;
        }

        $tareaBloqueada = TareaMovimiento::query()
            ->with('planOperacional')
            ->lockForUpdate()
            ->findOrFail($tarea->id);
        $custodia = CustodiaTemporalManiobra::query()
            ->where('bloqueo_folio_id', $folio->id)
            ->lockForUpdate()
            ->first();
        if ($custodia
            && $custodia->maniobra_operacional_id !== $tareaBloqueada->maniobra_operacional_id) {
            throw new ConflictoOperacion(
                'El pallet está bajo custodia temporal de otra maniobra.',
            );
        }
        $this->validarBandasDeTarea(
            $posicionOrigenId,
            $posicionDestinoId,
            $tareaBloqueada->maniobra_operacional_id,
        );
        $reserva = $this->reservaActivaTarea($tareaBloqueada->id, true);

        if (! $reserva || $this->estaVencida($reserva)) {
            if ($reserva) {
                $this->expirar($reserva);
            }

            throw new ConflictoOperacion(
                'La reserva de la tarea expiró; vuelva a asumirla antes de mover el pallet.',
            );
        }

        $this->validarPropietario($reserva, $usuario, $dispositivo);
        $this->validarCorrespondencia(
            $tareaBloqueada,
            $folio,
            $tipo,
            $posicionOrigenId,
            $posicionDestinoId,
        );

        $reservaDestino = $posicionDestinoId
            ? $this->reservaActivaDestino($posicionDestinoId, true)
            : null;
        if ($reservaDestino && $reservaDestino->id !== $reserva->id) {
            throw new ConflictoOperacion(
                'La posición de destino se encuentra reservada por otra tarea.',
            );
        }

        if ($posicionDestinoId && $reserva->bloqueo_posicion_id !== $posicionDestinoId) {
            throw new ConflictoOperacion('El destino físico no corresponde a la reserva materializada.');
        }
        if ($posicionDestinoId) {
            $this->validarReservaPreparacionSag($posicionDestinoId, $folio->id);
        }

        return $tareaBloqueada;
    }

    public function validarIntegridadMovimiento(Movimiento $movimiento): void
    {
        $tareaId = $movimiento->tarea_movimiento_id;

        if ($tareaId === null) {
            if ($this->reservaActivaFolio($movimiento->folio_id)) {
                throw new ConflictoOperacion(
                    'El pallet se encuentra reservado por una tarea operacional.',
                );
            }
            if ($movimiento->posicion_destino_id
                && $this->reservaActivaDestino($movimiento->posicion_destino_id)) {
                throw new ConflictoOperacion(
                    'La posición de destino se encuentra reservada por una tarea operacional.',
                );
            }
            if ($movimiento->posicion_destino_id) {
                $this->validarReservaPreparacionSag(
                    $movimiento->posicion_destino_id,
                    $movimiento->folio_id,
                );
            }

            return;
        }

        $tarea = TareaMovimiento::query()->with('planOperacional')->find($tareaId);
        $reserva = $this->reservaActivaTarea($tareaId);

        if (! $tarea || ! $reserva || $this->estaVencida($reserva)) {
            throw new ConflictoOperacion('El movimiento no posee una reserva operacional vigente.');
        }
        if ($movimiento->plan_operacional_id !== $tarea->plan_operacional_id
            || $movimiento->user_id !== $reserva->user_id
            || $movimiento->dispositivo_id !== $reserva->dispositivo_id) {
            throw new DomainException('El movimiento no corresponde al actor de la tarea reservada.');
        }

        $folio = Folio::query()->find($movimiento->folio_id);
        if (! $folio) {
            throw new DomainException('El folio de la tarea reservada no existe.');
        }

        $this->validarCorrespondencia(
            $tarea,
            $folio,
            $movimiento->tipo_movimiento,
            $movimiento->posicion_origen_id,
            $movimiento->posicion_destino_id,
        );
        if ($movimiento->posicion_destino_id) {
            $this->validarReservaPreparacionSag(
                $movimiento->posicion_destino_id,
                $movimiento->folio_id,
            );
        }
    }

    public function completar(TareaMovimiento $tarea, Movimiento $movimiento): void
    {
        $tareaBloqueada = TareaMovimiento::query()
            ->with('planOperacional')
            ->lockForUpdate()
            ->findOrFail($tarea->id);
        $reserva = $this->reservaActivaTarea($tareaBloqueada->id, true);

        if (! $reserva) {
            throw new ConflictoOperacion('La tarea perdió su reserva antes de completar el movimiento.');
        }

        $ahora = now();
        $this->finalizarReserva($reserva, EstadoReservaTareaMovimiento::Completada, [
            'completada_at' => $ahora,
        ]);
        $tareaBloqueada->update([
            'estado' => EstadoTareaMovimiento::Completada,
            'iniciada_at' => $tareaBloqueada->iniciada_at ?? $movimiento->recibido_servidor_at,
            'completada_at' => $ahora,
            'version' => $tareaBloqueada->version + 1,
        ]);

        app(ServicioManiobrasOperacionales::class)->avanzarTrasMovimiento(
            $tareaBloqueada->refresh(),
            $movimiento,
        );

        // Batch conserva el cierre histórico. En rolling, la inexistencia temporal
        // de tareas no significa que el objetivo del plan se haya cumplido.
        if (! $this->esHorizonteBatch($tareaBloqueada)) {
            return;
        }

        $plan = $tareaBloqueada->planOperacional;
        $poseePendientes = TareaMovimiento::query()
            ->where('plan_operacional_id', $plan->id)
            ->whereNotIn('estado', [
                EstadoTareaMovimiento::Completada->value,
                EstadoTareaMovimiento::Cancelada->value,
            ])
            ->exists();

        if (! $poseePendientes) {
            $plan->update([
                'estado' => EstadoPlanOperacional::Completado,
                'completado_por_user_id' => $movimiento->user_id,
                'completado_at' => $ahora,
                'version' => $plan->version + 1,
            ]);
        }
    }

    public function expirarVencidas(int $limite = 100, ?string $temporadaId = null): int
    {
        $ids = ReservaTareaMovimiento::query()
            ->when(
                $temporadaId,
                fn ($consulta) => $consulta->whereHas(
                    'tareaMovimiento.planOperacional',
                    fn ($planes) => $planes->where('temporada_id', $temporadaId),
                ),
            )
            ->where('estado', EstadoReservaTareaMovimiento::Activa->value)
            ->whereNotNull('bloqueo_tarea_id')
            ->where('vence_at', '<=', now())
            ->whereDoesntHave(
                'tareaMovimiento',
                fn ($tareas) => $tareas->where('estado', EstadoTareaMovimiento::EnProceso->value),
            )
            ->whereDoesntHave(
                'tareaMovimiento.maniobraOperacional.custodiasTemporales',
                fn ($custodias) => $custodias->where(
                    'estado',
                    EstadoCustodiaTemporal::Activa->value,
                ),
            )
            ->orderBy('vence_at')
            ->limit($limite)
            ->pluck('id');
        $expiradas = 0;

        foreach ($ids as $id) {
            $expirada = DB::transaction(function () use ($id): bool {
                $reserva = ReservaTareaMovimiento::query()->lockForUpdate()->find($id);

                if (! $reserva
                    || $reserva->estado !== EstadoReservaTareaMovimiento::Activa
                    || ! $this->estaVencida($reserva)) {
                    return false;
                }

                $this->expirar($reserva);

                return true;
            }, attempts: 3);

            $expiradas += $expirada ? 1 : 0;
        }

        return $expiradas;
    }

    private function bloquearDestinoDisponible(TareaMovimiento $tarea): ?Posicion
    {
        if (! $tarea->posicion_destino_id) {
            return null;
        }

        $posicion = Posicion::query()
            ->lockForUpdate()
            ->findOrFail($tarea->posicion_destino_id);

        if ($posicion->camara_id !== $tarea->camara_destino_id
            || $posicion->estado !== EstadoPosicion::Activa) {
            throw new ConflictoOperacion('La posición propuesta ya no está habilitada.');
        }
        if (UbicacionActual::query()
            ->where('posicion_id', $posicion->id)
            ->lockForUpdate()
            ->exists()) {
            throw new ConflictoOperacion('La posición propuesta ya se encuentra ocupada.');
        }

        return $posicion;
    }

    private function validarDestinoParaTipo(TareaMovimiento $tarea, Posicion $posicion): void
    {
        $origenCamaraId = $tarea->camara_origen_id;
        $valida = match ($tarea->tipo_movimiento) {
            TipoMovimiento::UbicacionInicial => $origenCamaraId === null,
            TipoMovimiento::Reubicacion => $origenCamaraId !== null
                && $posicion->camara_id === $origenCamaraId
                && $posicion->id !== $tarea->posicion_origen_id,
            TipoMovimiento::TrasladoEntreCamaras => $origenCamaraId !== null
                && $posicion->camara_id !== $origenCamaraId,
            TipoMovimiento::Retiro, TipoMovimiento::Reversion => false,
        };

        if (! $valida) {
            throw new ConflictoOperacion('El destino propuesto no corresponde al tipo de movimiento de la tarea.');
        }
    }

    private function validarBandaOperacional(
        Posicion $posicion,
        ?TareaMovimiento $tarea = null,
    ): void {
        $usoRequerido = $tarea?->contexto['uso_banda_destino']
            ?? UsoBandaOperacional::TransitoProductoTerminado->value;
        $banda = BandaOperacional::query()
            ->where('camara_id', $posicion->camara_id)
            ->where('numero', $posicion->banda)
            ->lockForUpdate()
            ->first();
        // Cámaras creadas antes de que existiera el catálogo de bandas (o
        // fixtures de compatibilidad) conservan el flujo histórico. Una
        // emergencia no entra en esta excepción: su declaración exige bandas
        // sincronizadas y las cambia a en_vaciado/bloqueada.
        if (! $banda) {
            return;
        }
        if ($banda->modo !== ModoBandaOperacional::Operativa
            || ! in_array(
                $usoRequerido,
                $banda->usos_permitidos ?? [],
                true,
            )) {
            throw new ConflictoOperacion(
                $usoRequerido === UsoBandaOperacional::TransitoProductoTerminado->value
                    ? 'La banda propuesta no admite nuevos ingresos de producto terminado.'
                    : 'La banda propuesta no admite el uso requerido por la tarea.',
            );
        }

        $reservaBanda = ReservaBandaManiobra::query()
            ->where('clave_bloqueo', implode(':', [
                $posicion->camara_id,
                $posicion->banda,
                $posicion->nivel,
            ]))
            ->lockForUpdate()
            ->first();
        if ($reservaBanda
            && $reservaBanda->maniobra_operacional_id !== $tarea?->maniobra_operacional_id) {
            throw new ConflictoOperacion(
                'La banda está protegida por una maniobra física en ejecución.',
            );
        }
    }

    private function validarBandasSinManiobra(
        ?string $posicionOrigenId,
        ?string $posicionDestinoId,
    ): void {
        $this->validarBandasDeTarea($posicionOrigenId, $posicionDestinoId, null);
        if ($posicionDestinoId) {
            $this->validarBandaOperacional(
                Posicion::query()->lockForUpdate()->findOrFail($posicionDestinoId),
            );
        }
    }

    private function validarPalletManiobraActiva(
        string $folioId,
        ?string $maniobraPermitidaId,
    ): void {
        $maniobra = ManiobraOperacional::query()
            ->whereIn('estado', [
                EstadoManiobraOperacional::EnEjecucion->value,
                EstadoManiobraOperacional::PausadaDiscrepancia->value,
            ])
            ->when(
                $maniobraPermitidaId,
                fn ($consulta) => $consulta->whereKeyNot($maniobraPermitidaId),
            )
            ->whereHas('pasos', fn ($consulta) => $consulta
                ->where('folio_id', $folioId)
                ->whereIn('estado', [
                    EstadoTareaMovimiento::Bloqueada->value,
                    EstadoTareaMovimiento::Pendiente->value,
                    EstadoTareaMovimiento::Asumida->value,
                    EstadoTareaMovimiento::EnProceso->value,
                ]))
            ->lockForUpdate()
            ->first();

        if ($maniobra) {
            throw new ConflictoOperacion(
                'El pallet está protegido por una maniobra física asumida.',
            );
        }
    }

    private function validarBandasDeTarea(
        ?string $posicionOrigenId,
        ?string $posicionDestinoId,
        ?string $maniobraId,
    ): void {
        $posiciones = Posicion::query()
            ->whereIn('id', array_values(array_filter([
                $posicionOrigenId,
                $posicionDestinoId,
            ])))
            ->lockForUpdate()
            ->get();

        foreach ($posiciones as $posicion) {
            $reserva = ReservaBandaManiobra::query()
                ->where('clave_bloqueo', implode(':', [
                    $posicion->camara_id,
                    $posicion->banda,
                    $posicion->nivel,
                ]))
                ->lockForUpdate()
                ->first();
            if ($reserva) {
                if ($reserva?->maniobra_operacional_id === $maniobraId) {
                    continue;
                }
                throw new ConflictoOperacion(
                    'La banda está protegida por una maniobra física en ejecución.',
                );
            }
        }
    }

    private function validarDestinoSinConflicto(
        string $posicionId,
        ?string $reservaPropiaId = null,
        ?string $folioId = null,
    ): void {
        $reservaDestino = ReservaTareaMovimiento::query()
            ->where('bloqueo_posicion_id', $posicionId)
            ->lockForUpdate()
            ->first();

        if ($reservaDestino && $this->estaVencida($reservaDestino)) {
            $this->expirar($reservaDestino);
            $reservaDestino = null;
        }
        if ($reservaDestino && $reservaDestino->id !== $reservaPropiaId) {
            throw new ConflictoOperacion('La posición de destino ya fue reservada por otra tarea.');
        }

        $this->validarReservaPreparacionSag($posicionId, $folioId);
    }

    private function validarReservaPreparacionSag(
        string $posicionId,
        ?string $folioId,
    ): void {
        $reserva = ReservaPosicionInspeccionSag::query()
            ->where('clave_bloqueo', $posicionId)
            ->lockForUpdate()
            ->first();
        if (! $reserva) {
            return;
        }

        $perteneceAlLote = $folioId !== null
            && $reserva->tipo_espacio === TipoEspacioPreparacionSag::Pallet
            && $reserva->lote()
                ->whereHas('folios', fn ($consulta) => $consulta->where('folio_id', $folioId))
                ->exists();
        if ($perteneceAlLote) {
            return;
        }

        throw new ConflictoOperacion(
            'La posición está reservada para la preparación de una inspección SAG.',
        );
    }

    private function validarPropietario(
        ReservaTareaMovimiento $reserva,
        User $usuario,
        Dispositivo $dispositivo,
    ): void {
        if ($reserva->user_id !== $usuario->id
            || $reserva->dispositivo_id !== $dispositivo->id) {
            throw new ConflictoOperacion(
                'La reserva pertenece a otro camarero o dispositivo.',
            );
        }
    }

    private function validarCorrespondencia(
        TareaMovimiento $tarea,
        Folio $folio,
        TipoMovimiento $tipo,
        ?string $posicionOrigenId,
        ?string $posicionDestinoId,
    ): void {
        $estadoEjecutable = $this->esHorizonteBatch($tarea)
            ? in_array($tarea->estado, [
                EstadoTareaMovimiento::Asumida,
                EstadoTareaMovimiento::EnProceso,
            ], true)
            : $tarea->estado === EstadoTareaMovimiento::EnProceso;

        if (! $estadoEjecutable
            || ! $tarea->planOperacional
            || $tarea->planOperacional->estado !== EstadoPlanOperacional::EnEjecucion) {
            throw new ConflictoOperacion(
                $this->esHorizonteBatch($tarea)
                    ? 'La tarea no se encuentra habilitada para ejecución.'
                    : 'La tarea debe marcarse en proceso antes de ejecutar el movimiento físico.',
            );
        }
        if ($tarea->folio_id !== $folio->id
            || $tarea->tipo_movimiento !== $tipo
            || $tarea->posicion_origen_id !== $posicionOrigenId
            || $tarea->posicion_destino_id !== $posicionDestinoId) {
            throw new ConflictoOperacion(
                'El folio, origen o destino no corresponde a la tarea reservada.',
            );
        }
    }

    private function renovarReserva(ReservaTareaMovimiento $reserva): ReservaTareaMovimiento
    {
        $reserva->update([
            'renovada_at' => now(),
            'vence_at' => $this->nuevoVencimiento(),
            'version' => $reserva->version + 1,
        ]);
        $this->incrementarRevisionCamara($reserva->posicion_destino_id);

        return $reserva->refresh();
    }

    private function esHorizonteBatch(TareaMovimiento $tarea): bool
    {
        $contextoPlan = $tarea->planOperacional?->contexto ?? [];

        return ($contextoPlan['planner_horizon'] ?? config('planificador.horizon')) === 'batch';
    }

    private function expirarRelacionadas(string $folioId, ?string $posicionDestinoId): void
    {
        $reservas = ReservaTareaMovimiento::query()
            ->where('estado', EstadoReservaTareaMovimiento::Activa->value)
            ->where(function ($consulta) use ($folioId, $posicionDestinoId): void {
                $consulta->whereHas(
                    'tareaMovimiento',
                    fn ($tareas) => $tareas->where('folio_id', $folioId),
                );
                if ($posicionDestinoId) {
                    $consulta->orWhere('bloqueo_posicion_id', $posicionDestinoId);
                }
            })
            ->lockForUpdate()
            ->get();

        foreach ($reservas as $reserva) {
            if ($this->estaVencida($reserva)) {
                $this->expirar($reserva);
            }
        }
    }

    private function expirar(ReservaTareaMovimiento $reserva): void
    {
        if ($this->estaProtegidaPorMovimiento($reserva)) {
            return;
        }

        $this->finalizarReserva($reserva, EstadoReservaTareaMovimiento::Expirada, [
            'expirada_at' => now(),
            'motivo_liberacion' => 'Lease vencido por falta de renovación.',
        ]);
        $this->devolverTareaALaBandeja($reserva->tarea_movimiento_id);
    }

    /** @param array<string, mixed> $atributos */
    private function finalizarReserva(
        ReservaTareaMovimiento $reserva,
        EstadoReservaTareaMovimiento $estado,
        array $atributos,
    ): void {
        $posicionDestinoId = $reserva->posicion_destino_id;
        $reserva->update([
            ...$atributos,
            'estado' => $estado,
            'bloqueo_tarea_id' => null,
            'bloqueo_posicion_id' => null,
            'version' => $reserva->version + 1,
        ]);
        $this->incrementarRevisionCamara($posicionDestinoId);
    }

    private function incrementarRevisionCamara(?string $posicionDestinoId): void
    {
        if (! $posicionDestinoId) {
            return;
        }

        $camaraId = Posicion::query()->whereKey($posicionDestinoId)->value('camara_id');
        if ($camaraId) {
            Camara::query()->whereKey($camaraId)->increment('revision_reservas');
        }
    }

    private function devolverTareaALaBandeja(string $tareaId): void
    {
        $tarea = TareaMovimiento::query()->lockForUpdate()->find($tareaId);

        if (! $tarea || $tarea->estado->esFinal() || $tarea->estado === EstadoTareaMovimiento::EnProceso) {
            return;
        }

        if ($tarea->maniobra_operacional_id && $tarea->maniobraOperacional()
            ->whereHas('custodiasTemporales', fn ($consulta) => $consulta
                ->where('estado', EstadoCustodiaTemporal::Activa->value))->exists()) {
            return;
        }

        // En rolling el destino pertenece al claim vencido o liberado. En batch
        // es parte del plan y debe permanecer visible en la bandeja.
        $rolling = ($tarea->planOperacional?->contexto['planner_horizon']
            ?? config('planificador.horizon')) === 'rolling';
        $tarea->update([
            'estado' => EstadoTareaMovimiento::Pendiente,
            ...($rolling ? ['camara_destino_id' => null, 'posicion_destino_id' => null] : []),
            'responsable_user_id' => null,
            'dispositivo_id' => null,
            'asumida_at' => null,
            'iniciada_at' => null,
            'version' => $tarea->version + 1,
        ]);
        $this->restablecerManiobraTrasExpirar($tarea);
    }

    private function restablecerManiobraTrasExpirar(TareaMovimiento $tarea): void
    {
        if (! $tarea->maniobra_operacional_id) {
            return;
        }

        $maniobra = $tarea->maniobraOperacional()->lockForUpdate()->first();
        if (! $maniobra
            || $maniobra->estado->esFinal()
            || $maniobra->custodiasTemporales()
                ->where('estado', EstadoCustodiaTemporal::Activa->value)
                ->lockForUpdate()
                ->exists()) {
            return;
        }

        $maniobra->reservasBandas()
            ->whereNull('liberada_at')
            ->update([
                'clave_bloqueo' => null,
                'liberada_at' => now(),
                'motivo_liberacion' => 'Claim vencido antes del siguiente movimiento físico.',
            ]);
        $poseePrefijoFisico = $maniobra->pasos()
            ->where('estado', EstadoTareaMovimiento::Completada->value)
            ->lockForUpdate()
            ->exists();
        $maniobra->update([
            'estado' => EstadoManiobraOperacional::Pendiente,
            'responsable_user_id' => null,
            'dispositivo_id' => null,
            'asumida_at' => $poseePrefijoFisico ? $maniobra->asumida_at : null,
            'iniciada_at' => $poseePrefijoFisico ? $maniobra->iniciada_at : null,
            'version' => $maniobra->version + 1,
        ]);
    }

    private function reservaActivaTarea(
        string $tareaId,
        bool $bloquear = false,
    ): ?ReservaTareaMovimiento {
        $consulta = ReservaTareaMovimiento::query()
            ->where('bloqueo_tarea_id', $tareaId);

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->first();
    }

    private function reservaActivaDestino(
        string $posicionId,
        bool $bloquear = false,
    ): ?ReservaTareaMovimiento {
        $consulta = ReservaTareaMovimiento::query()
            ->where('bloqueo_posicion_id', $posicionId)
            ->where(function ($query): void {
                $query->where('vence_at', '>', now())
                    ->orWhereHas(
                        'tareaMovimiento',
                        fn ($tareas) => $tareas->where('estado', EstadoTareaMovimiento::EnProceso->value),
                    );
            });

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->first();
    }

    private function reservaActivaFolio(
        string $folioId,
        bool $bloquear = false,
    ): ?ReservaTareaMovimiento {
        $consulta = ReservaTareaMovimiento::query()
            ->whereNotNull('bloqueo_tarea_id')
            ->where(function ($query): void {
                $query->where('vence_at', '>', now())
                    ->orWhereHas(
                        'tareaMovimiento',
                        fn ($tareas) => $tareas->where('estado', EstadoTareaMovimiento::EnProceso->value),
                    );
            })
            ->whereHas(
                'tareaMovimiento',
                fn ($tareas) => $tareas->where('folio_id', $folioId),
            );

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->first();
    }

    private function estaVencida(ReservaTareaMovimiento $reserva): bool
    {
        return ! $this->estaProtegidaPorMovimiento($reserva)
            && $reserva->vence_at->lte(now());
    }

    private function estaProtegidaPorMovimiento(ReservaTareaMovimiento $reserva): bool
    {
        $tarea = $reserva->tareaMovimiento()
            ->first(['id', 'estado', 'maniobra_operacional_id']);
        if (! $tarea) {
            return false;
        }
        if ($tarea->estado === EstadoTareaMovimiento::EnProceso) {
            return true;
        }

        return $tarea->maniobra_operacional_id !== null
            && CustodiaTemporalManiobra::query()
                ->where('maniobra_operacional_id', $tarea->maniobra_operacional_id)
                ->where('estado', EstadoCustodiaTemporal::Activa->value)
                ->exists();
    }

    private function nuevoVencimiento(): CarbonInterface
    {
        return now()->addMinutes((int) config('planificador.reserva_tarea_minutos', 10));
    }
}

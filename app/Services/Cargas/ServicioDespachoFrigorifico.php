<?php

namespace App\Services\Cargas;

use App\Enums\DominioTransicionOperacional;
use App\Enums\EstadoCarga;
use App\Enums\EstadoCargaFolio;
use App\Enums\EstadoIncidenciaCarga;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoSesionEstiba;
use App\Enums\TipoEventoCarga;
use App\Enums\TipoIncidenciaCarga;
use App\Enums\TipoResolucionIncidenciaCarga;
use App\Exceptions\ConflictoOperacion;
use App\Exceptions\OperacionNoAutorizada;
use App\Models\Anden;
use App\Models\BloqueoCamara;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Dispositivo;
use App\Models\EventoCarga;
use App\Models\Folio;
use App\Models\IncidenciaCargaFolio;
use App\Models\PresenciaCargaAnden;
use App\Models\ReservaCargaFolio;
use App\Models\SesionEstiba;
use App\Models\TareaMovimiento;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Estiba\ServicioMovimientoEstiba;
use App\Services\Transiciones\ComandoTransicionOperacional;
use App\Services\Transiciones\MotorTransicionesOperacionales;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use JsonException;

class ServicioDespachoFrigorifico
{
    public function __construct(
        private readonly AlcanceOperacionalUsuario $alcance,
        private readonly ServicioTareasCarga $servicioTareas,
        private readonly ServicioMovimientoEstiba $servicioMovimientos,
        private readonly MotorTransicionesOperacionales $motorTransiciones,
        private readonly ServicioPlanDespachoDirecto $planificadorDespachoDirecto,
        private readonly ServicioPresenciaCargaAnden $presenciasAnden,
    ) {}

    public function reportarIncidencia(
        string $operacionId,
        CargaFolio $asignacion,
        TipoIncidenciaCarga $tipo,
        ?string $descripcion,
        SesionEstiba $sesion,
        User $usuario,
        Dispositivo $dispositivo,
    ): IncidenciaCargaFolio {
        $this->asegurarUuid($operacionId);
        $this->asegurarPuedeReportar($usuario);
        $payload = [
            'carga_folio_id' => $asignacion->id,
            'tipo' => $tipo,
            'descripcion' => $this->textoOpcional($descripcion),
            'sesion_id' => $sesion->id,
            'usuario_id' => $usuario->id,
            'dispositivo_id' => $dispositivo->id,
        ];
        $payloadHash = $this->hash($payload);

        $accion = function () use (
            $operacionId,
            $asignacion,
            $tipo,
            $descripcion,
            $sesion,
            $usuario,
            $dispositivo,
            $payloadHash,
        ): IncidenciaCargaFolio {
            $existente = IncidenciaCargaFolio::query()
                ->where('operacion_reporte_id', $operacionId)
                ->lockForUpdate()
                ->first();

            if ($existente) {
                if (! hash_equals($existente->reporte_payload_hash, $payloadHash)) {
                    throw new ConflictoOperacion(
                        'El UUID de reporte ya fue utilizado con datos diferentes.',
                    );
                }

                return $existente;
            }

            $asignacionLeida = CargaFolio::query()->findOrFail($asignacion->id);
            $carga = Carga::query()->lockForUpdate()->findOrFail($asignacionLeida->carga_id);
            $asignacionBloqueada = CargaFolio::query()
                ->with('folio.ubicacionActual.posicion.camara')
                ->lockForUpdate()
                ->findOrFail($asignacion->id);

            if ($asignacionBloqueada->estado !== EstadoCargaFolio::Pendiente
                || ! $asignacionBloqueada->reservaActiva()->lockForUpdate()->exists()) {
                throw new DomainException(
                    'Solo un folio pendiente y reservado puede recibir una incidencia.',
                );
            }

            if (! in_array($carga->estado, EstadoCarga::visiblesEnOperacion(), true)) {
                throw new DomainException('La carga no se encuentra disponible para operación.');
            }

            $ubicacion = $asignacionBloqueada->folio->ubicacionActual;

            if (! $ubicacion?->posicion) {
                throw new DomainException('El folio no posee una ubicación actual.');
            }

            $this->asegurarSesionAutoriza(
                $sesion,
                $ubicacion->posicion->camara_id,
                $usuario,
                $dispositivo,
            );

            try {
                $incidencia = IncidenciaCargaFolio::create([
                    'operacion_reporte_id' => $operacionId,
                    'reporte_payload_hash' => $payloadHash,
                    'carga_folio_id' => $asignacionBloqueada->id,
                    'tipo' => $tipo,
                    'descripcion' => $this->textoOpcional($descripcion),
                    'estado' => EstadoIncidenciaCarga::Abierta,
                    'camara_id' => $ubicacion->posicion->camara_id,
                    'posicion_id' => $ubicacion->posicion_id,
                    'reportado_por_user_id' => $usuario->id,
                    'dispositivo_id' => $dispositivo->id,
                    'sesion_estiba_id' => $sesion->id,
                    'reportada_at' => now(),
                ]);
            } catch (QueryException $exception) {
                if ($exception->getCode() === '23000') {
                    throw new ConflictoOperacion(
                        'La incidencia entró en conflicto con otro reporte concurrente.',
                        previous: $exception,
                    );
                }

                throw $exception;
            }

            $asignacionBloqueada->update(['estado' => EstadoCargaFolio::ConIncidencia]);
            $carga->update([
                'estado' => $carga->estado === EstadoCarga::Pendiente
                    ? EstadoCarga::EnPreparacion
                    : $carga->estado,
                'version' => $carga->version + 1,
                'actualizada_por_user_id' => $usuario->id,
            ]);
            $this->registrarEvento(
                $carga,
                TipoEventoCarga::IncidenciaReportada,
                $usuario,
                $asignacionBloqueada->folio,
                [
                    'incidencia_id' => $incidencia->id,
                    'tipo' => $tipo->value,
                ],
            );
            $this->servicioTareas->sincronizar($carga);

            return $incidencia->refresh();
        };

        return $this->transicionar(
            'incidencia.reportar',
            $operacionId,
            $usuario,
            $payload,
            $accion,
            CargaFolio::class,
            (string) $asignacion->id,
            dispositivo: $dispositivo,
        );
    }

    public function resolverIncidencia(
        string $operacionId,
        IncidenciaCargaFolio $incidencia,
        TipoResolucionIncidenciaCarga $resolucion,
        User $usuario,
        ?Folio $folioReemplazo = null,
        ?string $observacion = null,
    ): IncidenciaCargaFolio {
        $this->asegurarUuid($operacionId);
        $this->asegurarPuedeResolver($usuario, $resolucion);
        $payload = [
            'incidencia_id' => $incidencia->id,
            'resolucion' => $resolucion,
            'folio_reemplazo_id' => $folioReemplazo?->id,
            'observacion' => $this->textoOpcional($observacion),
            'usuario_id' => $usuario->id,
        ];
        $payloadHash = $this->hash($payload);

        $accion = function () use (
            $operacionId,
            $incidencia,
            $resolucion,
            $usuario,
            $folioReemplazo,
            $observacion,
            $payloadHash,
        ): IncidenciaCargaFolio {
            $operacionExistente = IncidenciaCargaFolio::query()
                ->where('operacion_resolucion_id', $operacionId)
                ->lockForUpdate()
                ->first();

            if ($operacionExistente) {
                if ($operacionExistente->id !== $incidencia->id
                    || ! hash_equals((string) $operacionExistente->resolucion_payload_hash, $payloadHash)) {
                    throw new ConflictoOperacion(
                        'El UUID de resolución ya fue utilizado con datos diferentes.',
                    );
                }

                return $operacionExistente;
            }

            $incidenciaLeida = IncidenciaCargaFolio::query()->findOrFail($incidencia->id);
            $asignacionLeida = CargaFolio::query()->findOrFail($incidenciaLeida->carga_folio_id);
            $carga = Carga::query()->lockForUpdate()->findOrFail($asignacionLeida->carga_id);
            $incidenciaBloqueada = IncidenciaCargaFolio::query()
                ->lockForUpdate()
                ->findOrFail($incidencia->id);

            if ($incidenciaBloqueada->estado !== EstadoIncidenciaCarga::Abierta) {
                throw new DomainException('La incidencia ya fue resuelta.');
            }

            $asignacion = CargaFolio::query()
                ->with('folio.ubicacionActual.posicion.camara')
                ->lockForUpdate()
                ->findOrFail($incidenciaBloqueada->carga_folio_id);

            if ($asignacion->estado !== EstadoCargaFolio::ConIncidencia
                || ! $asignacion->reservaActiva()->lockForUpdate()->exists()) {
                throw new DomainException('El folio ya no posee una incidencia activa en la carga.');
            }

            $asignacionReemplazo = null;

            if ($resolucion === TipoResolucionIncidenciaCarga::DespachoParcial) {
                $this->asegurarCargaConservaFolios($carga, $asignacion);
                $this->finalizarAsignacion(
                    $asignacion,
                    EstadoCargaFolio::Descartado,
                    $usuario,
                    $observacion,
                );
            } elseif ($resolucion === TipoResolucionIncidenciaCarga::Reemplazo) {
                if (! $folioReemplazo) {
                    throw new DomainException('Debe indicar el folio de reemplazo.');
                }

                $folioReemplazoBloqueado = Folio::query()
                    ->with('ubicacionActual.posicion.camara')
                    ->lockForUpdate()
                    ->findOrFail($folioReemplazo->id);
                $this->asegurarReemplazoValido($asignacion->folio, $folioReemplazoBloqueado);
                $this->finalizarAsignacion(
                    $asignacion,
                    EstadoCargaFolio::Reemplazado,
                    $usuario,
                    $observacion,
                );

                try {
                    $asignacionReemplazo = CargaFolio::create([
                        'carga_id' => $carga->id,
                        'folio_id' => $folioReemplazoBloqueado->id,
                        'estado' => EstadoCargaFolio::Pendiente,
                        'reemplaza_a_carga_folio_id' => $asignacion->id,
                        'asignado_por_user_id' => $usuario->id,
                        'asignado_at' => now(),
                    ]);
                    ReservaCargaFolio::create([
                        'folio_id' => $folioReemplazoBloqueado->id,
                        'carga_folio_id' => $asignacionReemplazo->id,
                    ]);
                } catch (QueryException $exception) {
                    if ($exception->getCode() === '23000') {
                        throw new ConflictoOperacion(
                            'El folio de reemplazo fue reservado por otra carga.',
                            previous: $exception,
                        );
                    }

                    throw $exception;
                }
            } else {
                $asignacion->update(['estado' => EstadoCargaFolio::Pendiente]);
            }

            $incidenciaBloqueada->update([
                'estado' => EstadoIncidenciaCarga::Resuelta,
                'operacion_resolucion_id' => $operacionId,
                'resolucion_payload_hash' => $payloadHash,
                'tipo_resolucion' => $resolucion,
                'observacion_resolucion' => $this->textoOpcional($observacion),
                'resuelta_por_user_id' => $usuario->id,
                'resuelta_at' => now(),
                'carga_folio_reemplazo_id' => $asignacionReemplazo?->id,
            ]);
            $carga->update([
                'version' => $carga->version + 1,
                'actualizada_por_user_id' => $usuario->id,
            ]);
            $this->registrarEvento(
                $carga,
                $resolucion === TipoResolucionIncidenciaCarga::Reemplazo
                    ? TipoEventoCarga::FolioReemplazado
                    : TipoEventoCarga::IncidenciaResuelta,
                $usuario,
                $asignacion->folio,
                [
                    'incidencia_id' => $incidenciaBloqueada->id,
                    'resolucion' => $resolucion->value,
                    'folio_reemplazo_id' => $asignacionReemplazo?->folio_id,
                ],
            );
            $this->servicioTareas->sincronizar($carga);

            return $incidenciaBloqueada->refresh();
        };

        return $this->transicionar(
            'incidencia.resolver',
            $operacionId,
            $usuario,
            $payload,
            $accion,
            IncidenciaCargaFolio::class,
            (string) $incidencia->id,
        );
    }

    /**
     * @param  array<int, string>  $advertenciasConfirmadas
     */
    public function enviarFolioAnden(
        string $operacionId,
        CargaFolio $asignacion,
        Anden $anden,
        SesionEstiba $sesion,
        User $usuario,
        Dispositivo $dispositivo,
        int $versionCamaraConocida,
        DateTimeInterface $generadoDispositivoAt,
        array $advertenciasConfirmadas = [],
        ?TareaMovimiento $tareaMovimiento = null,
    ): CargaFolio {
        if (! $this->alcance->puedeEnviarFoliosAnden($usuario)) {
            throw new OperacionNoAutorizada(
                'El usuario no está autorizado para enviar folios al andén.',
            );
        }

        $payload = [
            'carga_folio_id' => $asignacion->id,
            'anden_id' => $anden->id,
            'sesion_id' => $sesion->id,
            'version_camara_conocida' => $versionCamaraConocida,
            'generado_dispositivo_at' => $generadoDispositivoAt,
            'advertencias_confirmadas' => $advertenciasConfirmadas,
            'tarea_movimiento_id' => $tareaMovimiento?->id,
        ];

        $accion = function () use (
            $operacionId,
            $asignacion,
            $anden,
            $sesion,
            $usuario,
            $dispositivo,
            $versionCamaraConocida,
            $generadoDispositivoAt,
            $advertenciasConfirmadas,
            $tareaMovimiento,
        ): CargaFolio {
            $asignacionLeida = CargaFolio::query()->findOrFail($asignacion->id);
            $carga = Carga::query()->lockForUpdate()->findOrFail($asignacionLeida->carga_id);
            $andenBloqueado = Anden::query()->lockForUpdate()->findOrFail($anden->id);

            if (! $andenBloqueado->activo) {
                throw new DomainException('El andén seleccionado no se encuentra activo.');
            }

            $presencia = $carga->presenciaAndenActiva()->lockForUpdate()->first();
            $ocupacionAnden = PresenciaCargaAnden::query()
                ->where('bloqueo_anden_id', $andenBloqueado->id)
                ->lockForUpdate()
                ->first();
            if ($ocupacionAnden && $ocupacionAnden->carga_id !== $carga->id) {
                throw new ConflictoOperacion(
                    'El andén está ocupado por el camión de otra carga.',
                );
            }
            if ($tareaMovimiento) {
                if (! $presencia
                    || $presencia->anden_id !== $andenBloqueado->id
                    || ($tareaMovimiento->contexto['carga_folio_id'] ?? null) !== $asignacion->id
                    || ($tareaMovimiento->contexto['presencia_carga_anden_id'] ?? null) !== $presencia->id) {
                    throw new ConflictoOperacion(
                        'La tarea ya no coincide con el camión, la carga o el andén activos.',
                    );
                }
            } elseif ($presencia && $presencia->anden_id !== $andenBloqueado->id) {
                throw new DomainException(
                    'La carga posee un camión activo en otro andén; use el destino confirmado por oficina.',
                );
            }

            $asignacionBloqueada = CargaFolio::query()
                ->with('folio')
                ->lockForUpdate()
                ->findOrFail($asignacion->id);

            if (! in_array($asignacionBloqueada->estado, [
                EstadoCargaFolio::Pendiente,
                EstadoCargaFolio::EnAnden,
            ], true)) {
                throw new DomainException(
                    'El folio debe estar pendiente y sin incidencias para enviarse al andén.',
                );
            }

            if (! in_array($carga->estado, EstadoCarga::visiblesEnOperacion(), true)
                && $carga->estado !== EstadoCarga::Despachada) {
                throw new DomainException('La carga no admite envíos al andén.');
            }

            $movimiento = $this->servicioMovimientos->retirar(
                $operacionId,
                $asignacionBloqueada->folio,
                $sesion,
                $usuario,
                $dispositivo,
                $versionCamaraConocida,
                $generadoDispositivoAt,
                "Envío a andén {$andenBloqueado->codigo} de la carga {$carga->codigo}",
                $advertenciasConfirmadas,
                $tareaMovimiento,
            );
            $carga->refresh();

            if ($asignacionBloqueada->estado === EstadoCargaFolio::EnAnden) {
                if ($asignacionBloqueada->anden_id !== $andenBloqueado->id
                    || $movimiento->folio_id !== $asignacionBloqueada->folio_id) {
                    throw new ConflictoOperacion(
                        'La operación repetida no coincide con el envío al andén registrado.',
                    );
                }

                return $asignacionBloqueada;
            }

            $asignacionBloqueada->update([
                'estado' => EstadoCargaFolio::EnAnden,
                'anden_id' => $andenBloqueado->id,
                'enviado_anden_por_user_id' => $usuario->id,
                'enviado_anden_desde_dispositivo_id' => $dispositivo->id,
                'enviado_anden_at' => now(),
            ]);

            $estados = CargaFolio::query()
                ->where('carga_id', $carga->id)
                ->whereHas('reservaActiva')
                ->lockForUpdate()
                ->pluck('estado');
            $todosEnAnden = $estados->isNotEmpty()
                && $estados->every(
                    fn (EstadoCargaFolio $estado): bool => $estado === EstadoCargaFolio::EnAnden,
                );
            $carga->update([
                'estado' => $todosEnAnden
                    ? EstadoCarga::Despachada
                    : EstadoCarga::DespachoParcial,
                'version' => $carga->version + 1,
                'actualizada_por_user_id' => $usuario->id,
            ]);
            $this->registrarEvento(
                $carga,
                TipoEventoCarga::FolioEnviadoAnden,
                $usuario,
                $asignacionBloqueada->folio,
                [
                    'movimiento_id' => $movimiento->id,
                    'anden_id' => $andenBloqueado->id,
                ],
            );
            $this->servicioTareas->sincronizar($carga);
            if ($presencia) {
                $this->planificadorDespachoDirecto->sincronizar($presencia, $usuario);
            }

            return $asignacionBloqueada->refresh();
        };

        return $this->transicionar(
            'folio.enviar_anden',
            $operacionId,
            $usuario,
            $payload,
            $accion,
            CargaFolio::class,
            (string) $asignacion->id,
            dispositivo: $dispositivo,
            ocurridoAt: $generadoDispositivoAt,
        );
    }

    public function cerrarDespacho(
        string $operacionId,
        Carga $carga,
        User $usuario,
        string $patente,
        string $conductor,
        ?string $observacion = null,
        ?DateTimeInterface $ocurridoAt = null,
    ): Carga {
        $this->asegurarUuid($operacionId);

        if (! $this->alcance->puedeCerrarDespachoFrigorifico($usuario)) {
            throw new OperacionNoAutorizada(
                'El usuario no está autorizado para cerrar el despacho frigorífico.',
            );
        }

        $patente = Str::upper(trim($patente));
        $conductor = trim($conductor);
        $salidaAt = $ocurridoAt
            ? CarbonImmutable::instance($ocurridoAt)
            : CarbonImmutable::now();

        if ($salidaAt->isFuture()) {
            throw new DomainException('La fecha y hora de salida no puede estar en el futuro.');
        }

        if ($patente === '' || $conductor === '') {
            throw new DomainException('La patente y el conductor son obligatorios para cerrar el despacho.');
        }

        $payload = [
            'carga_id' => $carga->id,
            'patente' => $patente,
            'conductor' => $conductor,
            'observacion' => $this->textoOpcional($observacion),
            'usuario_id' => $usuario->id,
        ];

        if ($ocurridoAt) {
            $payload['ocurrido_at'] = $salidaAt->toAtomString();
        }

        $payloadHash = $this->hash($payload);

        $accion = function () use (
            $operacionId,
            $carga,
            $usuario,
            $patente,
            $conductor,
            $observacion,
            $salidaAt,
            $payloadHash,
        ): Carga {
            $otraCarga = Carga::query()
                ->where('operacion_cierre_id', $operacionId)
                ->lockForUpdate()
                ->first();

            if ($otraCarga) {
                if ($otraCarga->id !== $carga->id
                    || ! hash_equals((string) $otraCarga->cierre_payload_hash, $payloadHash)) {
                    throw new ConflictoOperacion(
                        'El UUID de cierre ya fue utilizado con datos diferentes.',
                    );
                }

                return $otraCarga;
            }

            $cargaBloqueada = Carga::query()->lockForUpdate()->findOrFail($carga->id);

            if ($cargaBloqueada->estado !== EstadoCarga::Despachada) {
                throw new DomainException(
                    'La salida del camión solo puede confirmarse cuando todos los folios están en andén.',
                );
            }

            $asignaciones = CargaFolio::query()
                ->where('carga_id', $cargaBloqueada->id)
                ->whereHas('reservaActiva')
                ->with('folio')
                ->orderBy('folio_id')
                ->lockForUpdate()
                ->get();

            if ($asignaciones->isEmpty()
                || $asignaciones->contains(
                    fn (CargaFolio $asignacion): bool => $asignacion->estado !== EstadoCargaFolio::EnAnden,
                )) {
                throw new DomainException('La carga aún posee folios pendientes fuera del andén.');
            }

            $ultimoEnvioAnden = $asignaciones->max(
                fn (CargaFolio $asignacion): ?int => $asignacion->enviado_anden_at?->getTimestamp(),
            );

            if ($ultimoEnvioAnden !== null && $salidaAt->getTimestamp() < $ultimoEnvioAnden) {
                throw new DomainException(
                    'La fecha y hora de salida no puede ser anterior al último envío de un folio al andén.',
                );
            }

            $incidenciasAbiertas = IncidenciaCargaFolio::query()
                ->whereIn('carga_folio_id', $asignaciones->pluck('id'))
                ->where('estado', EstadoIncidenciaCarga::Abierta->value)
                ->lockForUpdate()
                ->exists();

            if ($incidenciasAbiertas) {
                throw new DomainException('La carga posee incidencias abiertas.');
            }

            foreach ($asignaciones as $asignacion) {
                $asignacion->folio->update([
                    'estado_operacional' => EstadoOperacionalFolio::Despachado,
                    'activo' => false,
                ]);
                $asignacion->reservaActiva()->lockForUpdate()->first()?->delete();
                $asignacion->update([
                    'estado' => EstadoCargaFolio::Despachado,
                    'finalizado_por_user_id' => $usuario->id,
                    'finalizado_at' => $salidaAt,
                    'motivo_finalizacion' => 'Salida de camión confirmada',
                ]);
                $this->registrarEvento(
                    $cargaBloqueada,
                    TipoEventoCarga::FolioDespachado,
                    $usuario,
                    $asignacion->folio,
                    ['salida_fisica_at' => $salidaAt->toAtomString()],
                );
            }

            $cargaBloqueada->update([
                'estado' => EstadoCarga::Cerrada,
                'operacion_cierre_id' => $operacionId,
                'cierre_payload_hash' => $payloadHash,
                'patente' => $patente,
                'conductor' => $conductor,
                'observacion_cierre' => $this->textoOpcional($observacion),
                'cerrada_por_user_id' => $usuario->id,
                'cerrada_at' => $salidaAt,
                'cierre_registrado_at' => CarbonImmutable::now(),
                'version' => $cargaBloqueada->version + 1,
                'actualizada_por_user_id' => $usuario->id,
            ]);
            $this->registrarEvento(
                $cargaBloqueada,
                TipoEventoCarga::CierreDespacho,
                $usuario,
                datos: [
                    'patente' => $patente,
                    'conductor' => $conductor,
                    'cantidad_folios' => $asignaciones->count(),
                    'salida_fisica_at' => $salidaAt->toAtomString(),
                    'registrada_at' => CarbonImmutable::now()->toAtomString(),
                ],
            );
            $this->servicioTareas->sincronizar($cargaBloqueada);
            $this->presenciasAnden->finalizarPorCierre(
                $cargaBloqueada,
                $usuario,
                $operacionId,
            );

            return $cargaBloqueada->refresh();
        };

        return $this->transicionar(
            'carga.cerrar',
            $operacionId,
            $usuario,
            $payload,
            $accion,
            Carga::class,
            (string) $carga->id,
            $carga->codigo,
            ocurridoAt: $salidaAt,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function transicionar(
        string $tipo,
        string $operacionId,
        User $usuario,
        array $payload,
        Closure $accion,
        string $sujetoTipo,
        string $sujetoId,
        ?string $referencia = null,
        ?Dispositivo $dispositivo = null,
        ?DateTimeInterface $ocurridoAt = null,
    ): mixed {
        return $this->motorTransiciones->ejecutar(
            new ComandoTransicionOperacional(
                dominio: DominioTransicionOperacional::Despacho,
                tipo: $tipo,
                usuario: $usuario,
                payload: $payload,
                operacionId: $operacionId,
                dispositivo: $dispositivo,
                sujetoTipo: $sujetoTipo,
                sujetoId: $sujetoId,
                referencia: $referencia,
                ocurridoAt: $ocurridoAt,
            ),
            $accion,
        );
    }

    private function asegurarPuedeReportar(User $usuario): void
    {
        if (! $this->alcance->puedeReportarIncidenciasCarga($usuario)) {
            throw new OperacionNoAutorizada(
                'El usuario no está autorizado para reportar incidencias de cargas.',
            );
        }
    }

    private function asegurarPuedeResolver(
        User $usuario,
        TipoResolucionIncidenciaCarga $resolucion,
    ): void {
        $autorizado = $resolucion === TipoResolucionIncidenciaCarga::Reparado
            ? $this->alcance->puedeResolverReparacionCarga($usuario)
            : $this->alcance->puedeResolverComercialmenteCarga($usuario);

        if (! $autorizado) {
            throw new OperacionNoAutorizada(
                'El usuario no está autorizado para resolver esta incidencia.',
            );
        }
    }

    private function asegurarSesionAutoriza(
        SesionEstiba $sesion,
        string $camaraId,
        User $usuario,
        Dispositivo $dispositivo,
    ): void {
        $sesionValida = SesionEstiba::query()
            ->whereKey($sesion->id)
            ->where('camara_id', $camaraId)
            ->where('user_id', $usuario->id)
            ->where('dispositivo_id', $dispositivo->id)
            ->where('estado', EstadoSesionEstiba::Abierta->value)
            ->lockForUpdate()
            ->exists();
        $bloqueoValido = BloqueoCamara::query()
            ->where('camara_id', $camaraId)
            ->where('sesion_estiba_id', $sesion->id)
            ->lockForUpdate()
            ->exists();

        if (! $sesionValida || ! $bloqueoValido) {
            throw new OperacionNoAutorizada(
                'La sesión no posee el bloqueo de la cámara donde se encuentra el folio.',
            );
        }
    }

    private function asegurarCargaConservaFolios(Carga $carga, CargaFolio $excluida): void
    {
        $cantidadRestante = CargaFolio::query()
            ->where('carga_id', $carga->id)
            ->where('id', '!=', $excluida->id)
            ->whereHas('reservaActiva')
            ->lockForUpdate()
            ->count();

        if ($cantidadRestante < 1) {
            throw new DomainException(
                'Un despacho parcial debe conservar al menos un folio en la carga.',
            );
        }
    }

    private function finalizarAsignacion(
        CargaFolio $asignacion,
        EstadoCargaFolio $estado,
        User $usuario,
        ?string $motivo,
    ): void {
        $asignacion->reservaActiva()->lockForUpdate()->first()?->delete();
        $asignacion->update([
            'estado' => $estado,
            'finalizado_por_user_id' => $usuario->id,
            'finalizado_at' => now(),
            'motivo_finalizacion' => $this->textoOpcional($motivo),
        ]);
    }

    private function asegurarReemplazoValido(Folio $original, Folio $reemplazo): void
    {
        if ($original->id === $reemplazo->id) {
            throw new DomainException('El folio de reemplazo debe ser diferente al original.');
        }

        if (! $reemplazo->activo
            || $reemplazo->estado_operacional !== EstadoOperacionalFolio::Disponible
            || ! $reemplazo->ubicacionActual?->posicion?->camara
            || $reemplazo->ubicacionActual->posicion->camara->contenido->value !== 'productos') {
            throw new DomainException(
                'El folio de reemplazo debe estar activo, disponible y ubicado en una cámara de productos.',
            );
        }

        if (ReservaCargaFolio::query()
            ->where('folio_id', $reemplazo->id)
            ->lockForUpdate()
            ->exists()) {
            throw new DomainException('El folio de reemplazo ya está reservado por otra carga.');
        }

        foreach ([
            'tipo_bulto',
            'condicion_sag_id',
            'variedad',
            'calibre',
            'marca',
            'exportadora',
        ] as $campo) {
            $valorOriginal = $original->{$campo};
            $valorReemplazo = $reemplazo->{$campo};

            if ($valorOriginal instanceof BackedEnum) {
                $valorOriginal = $valorOriginal->value;
            }

            if ($valorReemplazo instanceof BackedEnum) {
                $valorReemplazo = $valorReemplazo->value;
            }

            if ($valorOriginal !== $valorReemplazo) {
                throw new DomainException(
                    "El folio de reemplazo no coincide con el original en {$campo}.",
                );
            }
        }
    }

    private function registrarEvento(
        Carga $carga,
        TipoEventoCarga $tipo,
        User $usuario,
        ?Folio $folio = null,
        ?array $datos = null,
    ): void {
        EventoCarga::create([
            'carga_id' => $carga->id,
            'folio_id' => $folio?->id,
            'user_id' => $usuario->id,
            'tipo' => $tipo,
            'datos' => [
                'version' => $carga->version,
                ...($datos ?? []),
            ],
        ]);
    }

    private function asegurarUuid(string $operacionId): void
    {
        if (! Str::isUuid($operacionId)) {
            throw new DomainException('El identificador de la operación debe ser un UUID válido.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hash(array $payload): string
    {
        try {
            return hash('sha256', json_encode(
                $this->normalizar($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } catch (JsonException $exception) {
            throw new DomainException('No fue posible normalizar la operación.', previous: $exception);
        }
    }

    private function normalizar(mixed $valor): mixed
    {
        if ($valor instanceof BackedEnum) {
            return $valor->value;
        }

        if (is_array($valor)) {
            ksort($valor);

            return array_map(fn (mixed $item): mixed => $this->normalizar($item), $valor);
        }

        return $valor;
    }

    private function textoOpcional(?string $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}

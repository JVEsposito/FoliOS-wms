<?php

namespace App\Services\Prefrio;

use App\Enums\CondicionTermicaFolio;
use App\Enums\DominioTransicionOperacional;
use App\Enums\EstadoAdministrativoTunelPrefrio;
use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoProcesoPrefrio;
use App\Enums\EstadoTecnicoTunelPrefrio;
use App\Enums\FuenteHabilitacionAlmacenamiento;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\TipoBulto;
use App\Enums\TipoEventoPrefrio;
use App\Exceptions\ConflictoOperacion;
use App\Exceptions\OperacionNoAutorizada;
use App\Models\Dispositivo;
use App\Models\EventoPrefrio;
use App\Models\Folio;
use App\Models\PosicionTunelPrefrio;
use App\Models\ProcesoPrefrio;
use App\Models\ProcesoPrefrioFolio;
use App\Models\TunelPrefrio;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Folios\ServicioHabilitacionAlmacenamiento;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Transiciones\ComandoTransicionOperacional;
use App\Services\Transiciones\MotorTransicionesOperacionales;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

class ServicioProcesoPrefrio
{
    public function __construct(
        private readonly AlcanceOperacionalUsuario $alcance,
        private readonly ServicioHabilitacionAlmacenamiento $habilitacion,
        private readonly ServicioTemporadaActiva $temporadaActiva,
        private readonly MotorTransicionesOperacionales $motorTransiciones,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(
        array $datos,
        User $usuario,
        ?Dispositivo $dispositivo = null,
    ): ProcesoPrefrio {
        $this->asegurarOperacion($usuario);
        $operacionId = $datos['operacion_id'];
        $payload = $this->normalizar([
            'tunel_prefrio_id' => $datos['tunel_prefrio_id'],
            'setpoint' => (float) $datos['setpoint'],
            'duracion_objetivo_minutos' => $datos['duracion_objetivo_minutos'] ?? null,
            'formato_referencia' => $datos['formato_referencia'] ?? null,
            'observacion' => $datos['observacion'] ?? null,
            'ocurrido_at' => CarbonImmutable::parse($datos['ocurrido_at'])->toAtomString(),
        ]);
        $payloadHash = $this->calcularHash($payload);
        $payloadCompatible = $payload;
        unset($payloadCompatible['ocurrido_at']);
        $payloadHashCompatible = $this->calcularHash($payloadCompatible);

        $comando = new ComandoTransicionOperacional(
            dominio: DominioTransicionOperacional::Prefrio,
            tipo: 'proceso.crear',
            usuario: $usuario,
            payload: $payload,
            operacionId: $operacionId,
            dispositivo: $dispositivo,
            sujetoTipo: ProcesoPrefrio::class,
            referencia: (string) $datos['tunel_prefrio_id'],
            ocurridoAt: CarbonImmutable::parse($datos['ocurrido_at']),
        );
        $accion = function () use (
            $datos,
            $usuario,
            $dispositivo,
            $operacionId,
            $payload,
            $payloadHash,
            $payloadHashCompatible,
        ): ProcesoPrefrio {
            $momento = $this->validarMomentoOperacion($datos['ocurrido_at']);
            $existente = ProcesoPrefrio::query()
                ->where('operacion_id', $operacionId)
                ->lockForUpdate()
                ->first();

            if ($existente) {
                $this->validarIdempotenciaProceso(
                    $existente,
                    $usuario,
                    $dispositivo,
                    $payloadHash,
                    $payloadHashCompatible,
                );

                return $this->cargar($existente);
            }

            $temporada = $this->temporadaActiva->obtener(bloquear: true);

            $tunel = TunelPrefrio::query()
                ->lockForUpdate()
                ->findOrFail($datos['tunel_prefrio_id']);
            $this->validarTunelOperable($tunel);
            $this->asegurarTunelDisponible($tunel);
            $codigo = $this->siguienteCodigo();

            $proceso = ProcesoPrefrio::create([
                'temporada_id' => $temporada->id,
                'codigo' => $codigo,
                'operacion_id' => $operacionId,
                'payload_hash' => $payloadHash,
                'tunel_prefrio_id' => $tunel->id,
                'estado' => EstadoProcesoPrefrio::Borrador,
                'setpoint' => $datos['setpoint'],
                'duracion_objetivo_minutos' => $datos['duracion_objetivo_minutos'] ?? null,
                'formato_referencia' => $this->textoOpcional($datos['formato_referencia'] ?? null),
                'creado_por_user_id' => $usuario->id,
                'dispositivo_id' => $dispositivo?->id,
                'observacion' => $this->textoOpcional($datos['observacion'] ?? null),
            ]);

            EventoPrefrio::create([
                'operacion_id' => $operacionId,
                'payload_hash' => $payloadHash,
                'proceso_prefrio_id' => $proceso->id,
                'tipo' => TipoEventoPrefrio::CargaIniciada,
                'user_id' => $usuario->id,
                'dispositivo_id' => $dispositivo?->id,
                'ocurrido_at' => $momento,
                'datos' => $payload,
                'observacion' => $this->textoOpcional($datos['observacion'] ?? null),
            ]);

            return $this->cargar($proceso->refresh());
        };

        return $this->motorTransiciones->ejecutar($comando, $accion);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function agregarFolio(
        ProcesoPrefrio $proceso,
        array $datos,
        User $usuario,
        ?Dispositivo $dispositivo = null,
    ): ProcesoPrefrio {
        $this->asegurarOperacion($usuario);

        return $this->ejecutarEvento(
            $proceso,
            TipoEventoPrefrio::PalletAgregado,
            $datos,
            $usuario,
            $dispositivo,
            function (ProcesoPrefrio $procesoBloqueado) use ($datos, $usuario): ?ProcesoPrefrioFolio {
                $this->validarEstado($procesoBloqueado, [
                    EstadoProcesoPrefrio::Borrador,
                    EstadoProcesoPrefrio::Cargando,
                    EstadoProcesoPrefrio::ListoParaIniciar,
                ]);

                $folio = Folio::query()->lockForUpdate()->findOrFail($datos['folio_id']);
                $posicion = PosicionTunelPrefrio::query()
                    ->lockForUpdate()
                    ->findOrFail($datos['posicion_tunel_prefrio_id']);

                if ($folio->temporada_id !== $procesoBloqueado->temporada_id) {
                    throw new DomainException('El folio pertenece a otra temporada operacional.');
                }

                $this->validarFolioParaCarga($folio);

                if ($posicion->tunel_prefrio_id !== $procesoBloqueado->tunel_prefrio_id
                    || ! $posicion->activa) {
                    throw new DomainException('La posición no pertenece al túnel o se encuentra inactiva.');
                }

                $ocupantes = $procesoBloqueado->folios()
                    ->where('posicion_tunel_prefrio_id', $posicion->id)
                    ->whereNotIn('estado', [
                        EstadoFolioProcesoPrefrio::Retirado->value,
                        EstadoFolioProcesoPrefrio::Cancelado->value,
                    ])
                    ->with('folio:id,tipo_bulto')
                    ->lockForUpdate()
                    ->get();

                if ($ocupantes->isNotEmpty()
                    && ($folio->tipo_bulto !== TipoBulto::Saldo
                        || $ocupantes->contains(
                            fn (ProcesoPrefrioFolio $ocupante): bool => $ocupante->folio?->tipo_bulto
                                !== TipoBulto::Saldo,
                        ))) {
                    throw new ConflictoOperacion(
                        'La posición solo puede compartirse entre folios de tipo saldo.',
                    );
                }

                if ($procesoBloqueado->folios()
                    ->where('folio_id', $folio->id)
                    ->exists()) {
                    throw new ConflictoOperacion('El folio ya pertenece a este proceso de prefrío.');
                }

                $estadosActivos = collect(EstadoProcesoPrefrio::cases())
                    ->filter->esActivo()
                    ->map->value
                    ->all();
                $enOtroProceso = ProcesoPrefrioFolio::query()
                    ->where('folio_id', $folio->id)
                    ->whereHas('proceso', fn ($consulta) => $consulta
                        ->whereIn('estado', $estadosActivos))
                    ->exists();

                if ($enOtroProceso) {
                    throw new ConflictoOperacion('El folio ya participa en otro proceso activo de prefrío.');
                }

                $asignacion = ProcesoPrefrioFolio::create([
                    'proceso_prefrio_id' => $procesoBloqueado->id,
                    'folio_id' => $folio->id,
                    'posicion_tunel_prefrio_id' => $posicion->id,
                    'estado' => EstadoFolioProcesoPrefrio::Cargado,
                    'temperatura_inicial' => $datos['temperatura_inicial'] ?? null,
                    'cargado_at' => CarbonImmutable::parse($datos['ocurrido_at']),
                    'observacion' => $this->textoOpcional($datos['observacion'] ?? null),
                    'cargado_por_user_id' => $usuario->id,
                ]);

                $procesoBloqueado->update(['estado' => EstadoProcesoPrefrio::Cargando]);

                return $asignacion;
            },
        );
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function retirarFolio(
        ProcesoPrefrio $proceso,
        ProcesoPrefrioFolio $asignacion,
        array $datos,
        User $usuario,
        ?Dispositivo $dispositivo = null,
    ): ProcesoPrefrio {
        $this->asegurarOperacion($usuario);

        return $this->ejecutarEvento(
            $proceso,
            TipoEventoPrefrio::PalletRetirado,
            [...$datos, 'proceso_prefrio_folio_id' => $asignacion->id],
            $usuario,
            $dispositivo,
            function (ProcesoPrefrio $procesoBloqueado) use ($asignacion, $datos, $usuario): ?ProcesoPrefrioFolio {
                $this->validarEstado($procesoBloqueado, [
                    EstadoProcesoPrefrio::Borrador,
                    EstadoProcesoPrefrio::Cargando,
                    EstadoProcesoPrefrio::ListoParaIniciar,
                ]);

                $asignacion = ProcesoPrefrioFolio::query()
                    ->lockForUpdate()
                    ->findOrFail($asignacion->id);

                if ($asignacion->proceso_prefrio_id !== $procesoBloqueado->id) {
                    throw new DomainException('El folio no pertenece al proceso indicado.');
                }

                if ($asignacion->estado !== EstadoFolioProcesoPrefrio::Cargado) {
                    throw new DomainException('El folio ya no puede retirarse de la carga del túnel.');
                }

                $asignacion->update([
                    'estado' => EstadoFolioProcesoPrefrio::Retirado,
                    'retirado_at' => CarbonImmutable::parse($datos['ocurrido_at']),
                    'retirado_por_user_id' => $usuario->id,
                    'observacion' => $this->textoOpcional($datos['observacion'] ?? null),
                ]);

                $quedan = $procesoBloqueado->folios()
                    ->where('estado', EstadoFolioProcesoPrefrio::Cargado->value)
                    ->exists();
                $procesoBloqueado->update([
                    'estado' => $quedan
                        ? EstadoProcesoPrefrio::Cargando
                        : EstadoProcesoPrefrio::Borrador,
                ]);

                return $asignacion;
            },
        );
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function confirmarArmado(
        ProcesoPrefrio $proceso,
        array $datos,
        User $usuario,
        ?Dispositivo $dispositivo = null,
    ): ProcesoPrefrio {
        $this->asegurarOperacion($usuario);

        return $this->ejecutarEvento(
            $proceso,
            TipoEventoPrefrio::ArmadoConfirmado,
            $datos,
            $usuario,
            $dispositivo,
            function (ProcesoPrefrio $procesoBloqueado): ?ProcesoPrefrioFolio {
                $this->validarEstado($procesoBloqueado, [
                    EstadoProcesoPrefrio::Borrador,
                    EstadoProcesoPrefrio::Cargando,
                ]);

                if (! $procesoBloqueado->folios()
                    ->where('estado', EstadoFolioProcesoPrefrio::Cargado->value)
                    ->exists()) {
                    throw new DomainException('El proceso no posee folios cargados.');
                }

                $procesoBloqueado->update(['estado' => EstadoProcesoPrefrio::ListoParaIniciar]);

                return null;
            },
        );
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function iniciar(
        ProcesoPrefrio $proceso,
        array $datos,
        User $usuario,
        ?Dispositivo $dispositivo = null,
    ): ProcesoPrefrio {
        $this->asegurarOperacion($usuario);

        return $this->ejecutarEvento(
            $proceso,
            TipoEventoPrefrio::ProcesoIniciado,
            $datos,
            $usuario,
            $dispositivo,
            function (ProcesoPrefrio $procesoBloqueado) use ($datos, $usuario, $dispositivo): ?ProcesoPrefrioFolio {
                $this->validarEstado($procesoBloqueado, [EstadoProcesoPrefrio::ListoParaIniciar]);
                $tunel = TunelPrefrio::query()->lockForUpdate()->findOrFail($procesoBloqueado->tunel_prefrio_id);
                $this->validarTunelOperable($tunel);
                $this->asegurarTunelDisponible($tunel, $procesoBloqueado->id);

                $asignaciones = $procesoBloqueado->folios()
                    ->where('estado', EstadoFolioProcesoPrefrio::Cargado->value)
                    ->with('folio')
                    ->lockForUpdate()
                    ->get();

                if ($asignaciones->isEmpty()) {
                    throw new DomainException('El proceso no posee folios cargados para iniciar.');
                }

                foreach ($asignaciones as $asignacion) {
                    $asignacion->update(['estado' => EstadoFolioProcesoPrefrio::EnProceso]);
                    $this->habilitacion->marcarEnProceso(
                        $asignacion->folio,
                        $usuario,
                        $dispositivo,
                        'prefrio',
                        $procesoBloqueado->id,
                    );
                }

                $procesoBloqueado->update([
                    'estado' => EstadoProcesoPrefrio::EnProceso,
                    'iniciado_por_user_id' => $usuario->id,
                    'iniciado_at' => CarbonImmutable::parse($datos['ocurrido_at']),
                ]);

                return null;
            },
        );
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function registrarEventoOperacional(
        ProcesoPrefrio $proceso,
        TipoEventoPrefrio $tipo,
        array $datos,
        User $usuario,
        ?Dispositivo $dispositivo = null,
    ): ProcesoPrefrio {
        $this->asegurarOperacion($usuario);

        if (! in_array($tipo, [
            TipoEventoPrefrio::InversionRegistrada,
            TipoEventoPrefrio::Pausa,
            TipoEventoPrefrio::Reanudacion,
            TipoEventoPrefrio::Deshielo,
            TipoEventoPrefrio::Lectura,
        ], true)) {
            throw new DomainException('El tipo de evento no corresponde a un registro operacional.');
        }
        if ($tipo === TipoEventoPrefrio::Lectura) {
            // POEMP R-41: tres sectores, exterior e interior a cada lado. Una lectura horaria
            // puede quedar parcial. Las tablets anteriores envían una sola temperatura.
            if (array_key_exists('sectores', $datos)) {
                $datos['sectores'] = RegistroPrefrioR41::normalizar($datos['sectores']);
            } elseif (! is_numeric($datos['temperatura'] ?? null)) {
                throw ValidationException::withMessages(['sectores' => 'Ingresa al menos una temperatura de la lectura.']);
            }
        }

        return $this->ejecutarEvento(
            $proceso,
            $tipo,
            $datos,
            $usuario,
            $dispositivo,
            function (ProcesoPrefrio $procesoBloqueado): ?ProcesoPrefrioFolio {
                $this->validarEstado($procesoBloqueado, [EstadoProcesoPrefrio::EnProceso]);

                return null;
            },
        );
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function enviarAVerificacion(
        ProcesoPrefrio $proceso,
        array $datos,
        User $usuario,
        ?Dispositivo $dispositivo = null,
    ): ProcesoPrefrio {
        $this->asegurarOperacion($usuario);
        // Lectura final del R-41 enviada junto con el término del enfriado: debe estar completa.
        $lecturaFinal = $datos['datos']['lectura_final'] ?? null;
        if ($lecturaFinal !== null) {
            $datos['datos']['lectura_final'] = RegistroPrefrioR41::normalizar($lecturaFinal, true, 'lectura_final');
        }

        return $this->ejecutarEvento(
            $proceso,
            TipoEventoPrefrio::VerificacionFinal,
            $datos,
            $usuario,
            $dispositivo,
            function (ProcesoPrefrio $procesoBloqueado) use ($datos, $lecturaFinal): ?ProcesoPrefrioFolio {
                $this->validarEstado($procesoBloqueado, [EstadoProcesoPrefrio::EnProceso]);
                if ($lecturaFinal === null && config('prefrio.r41.exige_lectura_final', true)) {
                    // Sin lectura junto al término, la última lectura horaria debe estar completa.
                    $ultima = RegistroPrefrioR41::lecturas($procesoBloqueado->eventos()->get())->last();
                    if ($ultima === null || ! RegistroPrefrioR41::completa($ultima['sectores'])) {
                        throw ValidationException::withMessages(['lectura_final' => 'Antes de terminar el enfriado registra la lectura final: '
                            .'los 3 sectores (ventilador, medio y puerta), interior y exterior de ambos lados.']);
                    }
                }
                $procesoBloqueado->update([
                    'estado' => EstadoProcesoPrefrio::PendienteVerificacion,
                    'pendiente_verificacion_at' => CarbonImmutable::parse($datos['ocurrido_at']),
                ]);

                return null;
            },
        );
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function aprobar(
        ProcesoPrefrio $proceso,
        array $datos,
        User $usuario,
        ?Dispositivo $dispositivo = null,
    ): ProcesoPrefrio {
        $this->asegurarSupervision($usuario);

        return $this->ejecutarEvento(
            $proceso,
            TipoEventoPrefrio::Aprobacion,
            $datos,
            $usuario,
            $dispositivo,
            function (ProcesoPrefrio $procesoBloqueado) use ($datos, $usuario, $dispositivo): ?ProcesoPrefrioFolio {
                $this->validarEstado($procesoBloqueado, [EstadoProcesoPrefrio::PendienteVerificacion]);
                $resultados = collect($datos['resultados'] ?? [])->keyBy('folio_id');
                $asignaciones = $procesoBloqueado->folios()
                    ->where('estado', EstadoFolioProcesoPrefrio::EnProceso->value)
                    ->with('folio')
                    ->lockForUpdate()
                    ->get();

                foreach ($asignaciones as $asignacion) {
                    $resultado = $resultados->get($asignacion->folio_id, []);
                    $asignacion->update([
                        'estado' => EstadoFolioProcesoPrefrio::Aprobado,
                        'temperatura_final' => $resultado['temperatura_final'] ?? null,
                        'motivo_resultado' => 'prefrio_aprobado',
                        'observacion' => $this->textoOpcional($resultado['observacion'] ?? null),
                    ]);
                    $this->habilitacion->habilitar(
                        $asignacion->folio,
                        CondicionTermicaFolio::PrefrioAprobado,
                        FuenteHabilitacionAlmacenamiento::PrefrioAprobado,
                        $usuario,
                        $dispositivo,
                        'prefrio',
                        $procesoBloqueado->id,
                        $this->textoOpcional($resultado['observacion'] ?? null),
                    );
                }

                $procesoBloqueado->update([
                    'estado' => EstadoProcesoPrefrio::Aprobado,
                    'finalizado_por_user_id' => $usuario->id,
                    'finalizado_at' => CarbonImmutable::parse($datos['ocurrido_at']),
                ]);

                return null;
            },
        );
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function requerirReproceso(
        ProcesoPrefrio $proceso,
        array $datos,
        User $usuario,
        ?Dispositivo $dispositivo = null,
    ): ProcesoPrefrio {
        $this->asegurarSupervision($usuario);
        $motivo = trim((string) $datos['motivo']);

        return $this->ejecutarEvento(
            $proceso,
            TipoEventoPrefrio::Reproceso,
            $datos,
            $usuario,
            $dispositivo,
            function (ProcesoPrefrio $procesoBloqueado) use ($datos, $motivo, $usuario, $dispositivo): ?ProcesoPrefrioFolio {
                $this->validarEstado($procesoBloqueado, [EstadoProcesoPrefrio::PendienteVerificacion]);
                $resultados = collect($datos['resultados'] ?? [])->keyBy('folio_id');
                $asignaciones = $procesoBloqueado->folios()
                    ->where('estado', EstadoFolioProcesoPrefrio::EnProceso->value)
                    ->with('folio')
                    ->lockForUpdate()
                    ->get();

                foreach ($asignaciones as $asignacion) {
                    $resultado = $resultados->get($asignacion->folio_id, []);
                    $asignacion->update([
                        'estado' => EstadoFolioProcesoPrefrio::RequiereReproceso,
                        'temperatura_final' => $resultado['temperatura_final'] ?? null,
                        'motivo_resultado' => $motivo,
                        'observacion' => $this->textoOpcional($resultado['observacion'] ?? null),
                    ]);
                    $this->habilitacion->retener(
                        $asignacion->folio,
                        CondicionTermicaFolio::RequiereReproceso,
                        $motivo,
                        $usuario,
                        $dispositivo,
                        'prefrio',
                        $procesoBloqueado->id,
                    );
                }

                $procesoBloqueado->update([
                    'estado' => EstadoProcesoPrefrio::RequiereReproceso,
                    'finalizado_por_user_id' => $usuario->id,
                    'finalizado_at' => CarbonImmutable::parse($datos['ocurrido_at']),
                ]);

                return null;
            },
        );
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function cancelar(
        ProcesoPrefrio $proceso,
        array $datos,
        User $usuario,
        ?Dispositivo $dispositivo = null,
        bool $desdeCierreTemporada = false,
    ): ProcesoPrefrio {
        if ($desdeCierreTemporada) {
            if (! $usuario->can('administrar-accesos')) {
                throw new OperacionNoAutorizada('El cierre administrativo de prefrío requiere permiso para administrar accesos.');
            }
        } else {
            $this->asegurarSupervision($usuario);
        }
        $motivo = trim((string) $datos['motivo']);

        return $this->ejecutarEvento(
            $proceso,
            TipoEventoPrefrio::Cancelacion,
            $datos,
            $usuario,
            $dispositivo,
            function (ProcesoPrefrio $procesoBloqueado) use ($datos, $motivo, $usuario, $dispositivo): ?ProcesoPrefrioFolio {
                if ($procesoBloqueado->estado->esTerminal()) {
                    throw new DomainException('El proceso ya posee un resultado terminal.');
                }

                $procesoHabiaIniciado = in_array($procesoBloqueado->estado, [
                    EstadoProcesoPrefrio::EnProceso,
                    EstadoProcesoPrefrio::PendienteVerificacion,
                ], true);
                $asignaciones = $procesoBloqueado->folios()
                    ->whereNotIn('estado', [
                        EstadoFolioProcesoPrefrio::Retirado->value,
                        EstadoFolioProcesoPrefrio::Cancelado->value,
                    ])
                    ->with('folio')
                    ->lockForUpdate()
                    ->get();

                foreach ($asignaciones as $asignacion) {
                    $asignacion->update([
                        'estado' => EstadoFolioProcesoPrefrio::Cancelado,
                        'motivo_resultado' => $motivo,
                    ]);

                    if ($procesoHabiaIniciado) {
                        $this->habilitacion->retener(
                            $asignacion->folio,
                            CondicionTermicaFolio::Retenido,
                            $motivo,
                            $usuario,
                            $dispositivo,
                            'prefrio',
                            $procesoBloqueado->id,
                        );
                    }
                }

                $procesoBloqueado->update([
                    'estado' => EstadoProcesoPrefrio::Cancelado,
                    'finalizado_por_user_id' => $usuario->id,
                    'finalizado_at' => CarbonImmutable::parse($datos['ocurrido_at']),
                ]);

                return null;
            },
        );
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  callable(ProcesoPrefrio): ?ProcesoPrefrioFolio  $accion
     */
    private function ejecutarEvento(
        ProcesoPrefrio $proceso,
        TipoEventoPrefrio $tipo,
        array $datos,
        User $usuario,
        ?Dispositivo $dispositivo,
        callable $accion,
    ): ProcesoPrefrio {
        $operacionId = $datos['operacion_id'];
        $payload = $this->normalizar([
            ...$datos,
            'proceso_prefrio_id' => $proceso->id,
            'tipo' => $tipo->value,
        ]);
        $payloadHash = $this->calcularHash($payload);

        $comando = new ComandoTransicionOperacional(
            dominio: DominioTransicionOperacional::Prefrio,
            tipo: 'proceso.'.$tipo->value,
            usuario: $usuario,
            payload: $payload,
            operacionId: $operacionId,
            dispositivo: $dispositivo,
            sujetoTipo: ProcesoPrefrio::class,
            sujetoId: (string) $proceso->id,
            referencia: $proceso->codigo,
            ocurridoAt: CarbonImmutable::parse($datos['ocurrido_at']),
        );
        $accionTransicion = function () use (
            $proceso,
            $tipo,
            $datos,
            $usuario,
            $dispositivo,
            $accion,
            $operacionId,
            $payload,
            $payloadHash,
        ): ProcesoPrefrio {
            $eventoExistente = EventoPrefrio::query()
                ->where('operacion_id', $operacionId)
                ->lockForUpdate()
                ->first();

            if ($eventoExistente) {
                $mismaSolicitud = $eventoExistente->proceso_prefrio_id === $proceso->id
                    && $eventoExistente->tipo === $tipo
                    && $eventoExistente->user_id === $usuario->id
                    && $eventoExistente->dispositivo_id === $dispositivo?->id
                    && hash_equals($eventoExistente->payload_hash, $payloadHash);

                if (! $mismaSolicitud) {
                    throw new ConflictoOperacion('El UUID del evento ya fue utilizado con datos diferentes.');
                }

                return $this->cargar(ProcesoPrefrio::query()->findOrFail($proceso->id));
            }

            $procesoBloqueado = ProcesoPrefrio::query()->lockForUpdate()->findOrFail($proceso->id);
            $versionConocida = (int) $datos['version_conocida'];

            if ($procesoBloqueado->version !== $versionConocida) {
                throw new ConflictoOperacion('La versión conocida del proceso de prefrío está desactualizada.');
            }

            $momento = $this->validarMomentoOperacion($datos['ocurrido_at'], $procesoBloqueado);

            $asignacion = $accion($procesoBloqueado);
            $procesoBloqueado->increment('version');

            EventoPrefrio::create([
                'operacion_id' => $operacionId,
                'payload_hash' => $payloadHash,
                'proceso_prefrio_id' => $procesoBloqueado->id,
                'proceso_prefrio_folio_id' => $asignacion?->id,
                'tipo' => $tipo,
                'user_id' => $usuario->id,
                'dispositivo_id' => $dispositivo?->id,
                'ocurrido_at' => $momento,
                'datos' => $payload,
                'observacion' => $this->textoOpcional($datos['observacion'] ?? null),
            ]);

            return $this->cargar($procesoBloqueado->refresh());
        };

        return $this->motorTransiciones->ejecutar($comando, $accionTransicion);
    }

    private function siguienteCodigo(): string
    {
        $anio = (int) now()->format('Y');
        DB::table('secuencias_procesos_prefrio')->insertOrIgnore([
            'anio' => $anio,
            'ultimo_numero' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secuencia = DB::table('secuencias_procesos_prefrio')
            ->where('anio', $anio)
            ->lockForUpdate()
            ->first();
        $numero = ((int) $secuencia->ultimo_numero) + 1;
        DB::table('secuencias_procesos_prefrio')
            ->where('anio', $anio)
            ->update(['ultimo_numero' => $numero, 'updated_at' => now()]);

        return sprintf('PF-%d-%06d', $anio, $numero);
    }

    private function validarMomentoOperacion(
        string $valor,
        ?ProcesoPrefrio $proceso = null,
    ): CarbonImmutable {
        $momento = CarbonImmutable::parse($valor);
        if ($momento->greaterThan(now()->addMinutes(5))) {
            throw new ConflictoOperacion(
                'La fecha y hora de la acción no puede quedar en el futuro.',
            );
        }

        if ($proceso === null) {
            return $momento;
        }

        $ultimoEvento = EventoPrefrio::query()
            ->where('proceso_prefrio_id', $proceso->id)
            ->latest('ocurrido_at')
            ->latest('created_at')
            ->lockForUpdate()
            ->first();
        if ($ultimoEvento?->ocurrido_at?->greaterThan($momento)) {
            throw new ConflictoOperacion(sprintf(
                'La fecha y hora debe ser igual o posterior al último evento (%s).',
                $ultimoEvento->ocurrido_at->setTimezone((string) config('app.operational_timezone'))->format('d-m-Y H:i'),
            ));
        }

        return $momento;
    }

    private function validarFolioParaCarga(Folio $folio): void
    {
        if (! $folio->activo || $folio->tipo_bulto === TipoBulto::Material) {
            throw new DomainException('El folio no corresponde a producto activo.');
        }

        if ($folio->ubicacionActual()->exists()) {
            throw new DomainException('El folio se encuentra ubicado en una cámara y debe retirarse antes del prefrío.');
        }

        if ($folio->habilitacion_almacenamiento === HabilitacionAlmacenamientoFolio::Habilitado) {
            throw new DomainException('El folio ya se encuentra habilitado para almacenamiento.');
        }

        if (! in_array($folio->condicion_termica, [
            CondicionTermicaFolio::PendientePrefrio,
            CondicionTermicaFolio::RequiereReproceso,
            CondicionTermicaFolio::Retenido,
        ], true)) {
            throw new DomainException('La condición térmica del folio no permite cargarlo al túnel.');
        }
    }

    private function validarTunelOperable(TunelPrefrio $tunel): void
    {
        if ($tunel->estado_administrativo !== EstadoAdministrativoTunelPrefrio::Activo) {
            throw new DomainException('El túnel se encuentra administrativamente inactivo.');
        }

        if ($tunel->estado_tecnico !== EstadoTecnicoTunelPrefrio::Operativo) {
            throw new DomainException('El túnel no se encuentra técnicamente operativo.');
        }
    }

    private function asegurarTunelDisponible(TunelPrefrio $tunel, ?string $ignorarProcesoId = null): void
    {
        $estados = collect(EstadoProcesoPrefrio::cases())
            ->filter->esActivo()
            ->map->value
            ->all();
        $ocupado = $tunel->procesos()
            ->whereIn('estado', $estados)
            ->when($ignorarProcesoId, fn ($consulta) => $consulta->whereKeyNot($ignorarProcesoId))
            ->exists();

        if ($ocupado) {
            throw new ConflictoOperacion('El túnel ya posee un proceso activo.');
        }
    }

    /**
     * @param  array<int, EstadoProcesoPrefrio>  $permitidos
     */
    private function validarEstado(ProcesoPrefrio $proceso, array $permitidos): void
    {
        if (! in_array($proceso->estado, $permitidos, true)) {
            throw new DomainException(sprintf(
                'El proceso se encuentra en estado %s y no admite esta acción.',
                $proceso->estado->value,
            ));
        }
    }

    private function asegurarOperacion(User $usuario): void
    {
        if (! $this->alcance->puedeOperarPrefrio($usuario)) {
            throw new OperacionNoAutorizada('El usuario no puede operar procesos de prefrío.');
        }
    }

    private function asegurarSupervision(User $usuario): void
    {
        if (! $this->alcance->puedeSupervisarPrefrio($usuario)) {
            throw new OperacionNoAutorizada('La decisión final de prefrío requiere supervisor de frío o administrador.');
        }
    }

    private function validarIdempotenciaProceso(
        ProcesoPrefrio $proceso,
        User $usuario,
        ?Dispositivo $dispositivo,
        string $payloadHash,
        ?string $payloadHashCompatible = null,
    ): void {
        $coincidePayload = hash_equals($proceso->payload_hash, $payloadHash)
            || ($payloadHashCompatible !== null
                && hash_equals($proceso->payload_hash, $payloadHashCompatible));
        if ($proceso->creado_por_user_id !== $usuario->id
            || $proceso->dispositivo_id !== $dispositivo?->id
            || ! $coincidePayload) {
            throw new ConflictoOperacion('El UUID del proceso ya fue utilizado con datos diferentes.');
        }
    }

    private function cargar(ProcesoPrefrio $proceso): ProcesoPrefrio
    {
        return $proceso->load([
            'temporada:id,codigo,nombre,activa',
            'tunel:id,codigo,nombre,capacidad_posiciones,setpoint_habitual,estado_administrativo,estado_tecnico,version_configuracion',
            'folios' => fn ($consulta) => $consulta
                ->with([
                    'folio:id,numero_folio,tipo_bulto,estado_operacional,condicion_termica,habilitacion_almacenamiento,variedad,calibre,marca,exportadora,datos_externos',
                    'posicion:id,tunel_prefrio_id,numero,etiqueta,activa',
                    'cargadoPor:id,name',
                ])
                ->orderBy('created_at'),
            'eventos' => fn ($consulta) => $consulta
                ->with(['usuario:id,name', 'dispositivo:id,codigo,nombre'])
                ->latest('ocurrido_at')
                ->latest('created_at'),
            'creadoPor:id,name',
            'iniciadoPor:id,name',
            'finalizadoPor:id,name',
        ]);
    }

    private function asegurarUuid(string $valor): void
    {
        if (! Str::isUuid($valor)) {
            throw new DomainException('El identificador de operación debe ser un UUID válido.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function calcularHash(array $payload): string
    {
        try {
            return hash('sha256', json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } catch (JsonException $exception) {
            throw new DomainException('El contenido de la operación no es serializable.', previous: $exception);
        }
    }

    private function normalizar(mixed $valor): mixed
    {
        if ($valor instanceof DateTimeInterface) {
            return $valor->format(DATE_ATOM);
        }

        if (! is_array($valor)) {
            return $valor;
        }

        if (array_is_list($valor)) {
            return array_map(fn (mixed $item): mixed => $this->normalizar($item), $valor);
        }

        ksort($valor, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->normalizar($item), $valor);
    }

    private function textoOpcional(mixed $valor): ?string
    {
        $texto = Str::of((string) $valor)->squish()->toString();

        return $texto !== '' ? $texto : null;
    }
}

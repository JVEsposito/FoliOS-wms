<?php

namespace App\Services\Prefrio;

use App\Enums\CondicionTermicaFolio;
use App\Enums\DominioTransicionOperacional;
use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoProcesoPrefrio;
use App\Enums\FuenteHabilitacionAlmacenamiento;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Enums\TipoEventoPrefrio;
use App\Exceptions\ConflictoOperacion;
use App\Exceptions\OperacionNoAutorizada;
use App\Models\EventoPrefrio;
use App\Models\Folio;
use App\Models\PosicionTunelPrefrio;
use App\Models\ProcesoPrefrio;
use App\Models\ProcesoPrefrioFolio;
use App\Models\User;
use App\Services\Folios\ServicioHabilitacionAlmacenamiento;
use App\Services\Transiciones\ComandoTransicionOperacional;
use App\Services\Transiciones\MotorTransicionesOperacionales;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use JsonException;

class ServicioCorreccionProcesoPrefrio
{
    public function __construct(
        private readonly ServicioHabilitacionAlmacenamiento $habilitacion,
        private readonly MotorTransicionesOperacionales $motorTransiciones,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function corregir(
        ProcesoPrefrio $proceso,
        array $datos,
        User $usuario,
    ): ProcesoPrefrio {
        $this->asegurarAdministrador($usuario);
        $payload = $this->normalizar($datos);
        $payloadHash = $this->calcularHash($payload);

        $comando = new ComandoTransicionOperacional(
            dominio: DominioTransicionOperacional::Administracion,
            tipo: 'prefrio.corregir',
            usuario: $usuario,
            payload: $payload,
            operacionId: $datos['operacion_id'],
            sujetoTipo: ProcesoPrefrio::class,
            sujetoId: (string) $proceso->id,
            referencia: $proceso->codigo,
        );
        $accion = function () use (
            $proceso,
            $datos,
            $usuario,
            $payloadHash,
        ): ProcesoPrefrio {
            $proceso = ProcesoPrefrio::query()->lockForUpdate()->findOrFail($proceso->id);
            $existente = EventoPrefrio::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->lockForUpdate()
                ->first();

            if ($existente) {
                if ($existente->proceso_prefrio_id !== $proceso->id
                    || $existente->tipo !== TipoEventoPrefrio::CorreccionAdministrativa
                    || ! hash_equals((string) $existente->payload_hash, $payloadHash)) {
                    throw new ConflictoOperacion(
                        'El identificador de corrección ya fue utilizado con datos diferentes.',
                    );
                }

                return $this->cargar($proceso);
            }

            if ($proceso->version !== (int) $datos['version_conocida']) {
                throw new ConflictoOperacion(
                    'El proceso cambió mientras se editaba. Actualiza el detalle y vuelve a intentarlo.',
                );
            }

            $cambios = [];
            $antesProceso = $this->snapshot($proceso, $this->camposProceso());

            $this->actualizarEventos($proceso, $datos['eventos'], $cambios);
            $this->actualizarFolios($proceso, $datos['folios'], $usuario, $cambios);

            if (! empty($datos['nuevo_folio'])) {
                $this->agregarFolioHistorico(
                    $proceso,
                    $datos['nuevo_folio'],
                    $usuario,
                    $cambios,
                );
            }

            $proceso->fill([
                'setpoint' => $datos['proceso']['setpoint'],
                'duracion_objetivo_minutos' => $datos['proceso']['duracion_objetivo_minutos'] ?? null,
                'formato_referencia' => $this->textoOpcional(
                    $datos['proceso']['formato_referencia'] ?? null,
                ),
                'observacion' => $this->textoOpcional($datos['proceso']['observacion'] ?? null),
                ...$this->hitosDesdeEventos($proceso),
            ]);

            $this->validarCronologia($proceso);
            $this->validarPosiciones($proceso);

            $proceso->version = ((int) $proceso->version) + 1;
            $proceso->save();

            $despuesProceso = $this->snapshot($proceso, $this->camposProceso());
            if ($antesProceso !== $despuesProceso) {
                array_unshift($cambios, [
                    'entidad' => 'proceso',
                    'id' => $proceso->id,
                    'antes' => $antesProceso,
                    'despues' => $despuesProceso,
                ]);
            }

            EventoPrefrio::create([
                'operacion_id' => $datos['operacion_id'],
                'payload_hash' => $payloadHash,
                'proceso_prefrio_id' => $proceso->id,
                'tipo' => TipoEventoPrefrio::CorreccionAdministrativa,
                'user_id' => $usuario->id,
                'ocurrido_at' => now(),
                'datos' => [
                    'motivo' => $this->textoObligatorio($datos['motivo']),
                    'cambios' => $cambios,
                    'estado_operacional_folios_sincronizado' => true,
                    'resultado_termico_folios_sincronizado' => true,
                ],
                'observacion' => $this->textoObligatorio($datos['motivo']),
            ]);

            return $this->cargar($proceso->refresh());
        };

        return $this->motorTransiciones->ejecutar($comando, $accion);
    }

    /**
     * @param  array<int, array<string, mixed>>  $eventos
     * @param  array<int, array<string, mixed>>  $cambios
     */
    private function actualizarEventos(
        ProcesoPrefrio $proceso,
        array $eventos,
        array &$cambios,
    ): void {
        foreach ($eventos as $datosEvento) {
            $evento = EventoPrefrio::query()->lockForUpdate()->findOrFail($datosEvento['id']);

            if ($evento->proceso_prefrio_id !== $proceso->id) {
                throw new DomainException('Uno de los eventos no pertenece al proceso indicado.');
            }

            if ($evento->tipo === TipoEventoPrefrio::CorreccionAdministrativa) {
                throw new DomainException('Las correcciones administrativas previas no pueden reescribirse.');
            }
            if (in_array($evento->tipo, [TipoEventoPrefrio::FinalizacionParcial, TipoEventoPrefrio::RetiroParcialConfirmado], true)) {
                throw new DomainException('La finalización y el retiro parcial se conservan como eventos de terreno.');
            }

            $momento = $this->momento($datosEvento['ocurrido_at']);
            $campos = ['ocurrido_at', 'observacion'];
            $antes = $this->snapshot($evento, $campos);
            $evento->fill([
                'ocurrido_at' => $momento,
                'observacion' => $this->textoOpcional($datosEvento['observacion'] ?? null),
            ]);

            if (! $evento->isDirty()) {
                continue;
            }

            $evento->save();
            $cambios[] = [
                'entidad' => 'evento',
                'id' => $evento->id,
                'tipo' => $evento->tipo->value,
                'antes' => $antes,
                'despues' => $this->snapshot($evento, $campos),
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $folios
     * @param  array<int, array<string, mixed>>  $cambios
     */
    private function actualizarFolios(
        ProcesoPrefrio $proceso,
        array $folios,
        User $usuario,
        array &$cambios,
    ): void {
        foreach ($folios as $datosFolio) {
            $asignacion = ProcesoPrefrioFolio::query()
                ->with('folio')
                ->lockForUpdate()
                ->findOrFail($datosFolio['id']);

            if ($asignacion->proceso_prefrio_id !== $proceso->id) {
                throw new DomainException('Uno de los folios no pertenece al proceso indicado.');
            }

            if ($asignacion->finalizado_parcial_at !== null) {
                throw new DomainException('El folio ya tiene una finalización parcial registrada.');
            }
            $campos = $this->camposAsignacion();
            $antes = $this->snapshot($asignacion, $campos);

            if (! $datosFolio['incluido']) {
                if (! in_array($asignacion->estado, [
                    EstadoFolioProcesoPrefrio::Retirado,
                    EstadoFolioProcesoPrefrio::Cancelado,
                ], true)) {
                    $asignacion->fill([
                        'estado' => EstadoFolioProcesoPrefrio::Cancelado,
                        'retirado_at' => now(),
                        'retirado_por_user_id' => $usuario->id,
                        'motivo_resultado' => 'correccion_administrativa',
                        'observacion' => $this->textoOpcional($datosFolio['observacion'] ?? null),
                    ]);
                }
            } else {
                if (empty($datosFolio['posicion_tunel_prefrio_id'])
                    || empty($datosFolio['cargado_at'])) {
                    throw new DomainException(
                        'Todo folio incluido requiere posición y fecha de carga.',
                    );
                }

                $this->validarPosicion(
                    $proceso,
                    (string) $datosFolio['posicion_tunel_prefrio_id'],
                );
                $asignacion->fill([
                    'posicion_tunel_prefrio_id' => $datosFolio['posicion_tunel_prefrio_id'],
                    'estado' => $this->estadoAsignacion($proceso),
                    'temperatura_inicial' => $datosFolio['temperatura_inicial'] ?? null,
                    'temperatura_final' => $datosFolio['temperatura_final'] ?? null,
                    'cargado_at' => $this->momento($datosFolio['cargado_at']),
                    'retirado_at' => null,
                    'retirado_por_user_id' => null,
                    'motivo_resultado' => $this->motivoResultado($proceso),
                    'observacion' => $this->textoOpcional($datosFolio['observacion'] ?? null),
                ]);

                $this->sincronizarAprobacionTermicaHistorica(
                    $proceso,
                    $asignacion->folio,
                    $usuario,
                    $this->textoOpcional($datosFolio['observacion'] ?? null),
                    $cambios,
                );
            }

            if (! $asignacion->isDirty()) {
                continue;
            }

            $asignacion->save();
            $cambios[] = [
                'entidad' => 'folio',
                'id' => $asignacion->id,
                'numero_folio' => $asignacion->folio?->numero_folio,
                'antes' => $antes,
                'despues' => $this->snapshot($asignacion, $campos),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $datosFolio
     * @param  array<int, array<string, mixed>>  $cambios
     */
    private function agregarFolioHistorico(
        ProcesoPrefrio $proceso,
        array $datosFolio,
        User $usuario,
        array &$cambios,
    ): void {
        $numero = mb_strtoupper(trim((string) $datosFolio['numero_folio']));
        $folio = Folio::query()
            ->whereRaw('UPPER(numero_folio) = ?', [$numero])
            ->lockForUpdate()
            ->first();

        if (! $folio) {
            throw new DomainException('No existe un folio con el número indicado.');
        }

        if ($folio->temporada_id !== $proceso->temporada_id) {
            throw new DomainException('El folio pertenece a otra temporada operacional.');
        }

        if ($proceso->folios()->where('folio_id', $folio->id)->exists()) {
            throw new ConflictoOperacion(
                'El folio ya posee historial en este proceso; reactiva su fila existente.',
            );
        }

        $asignado = ProcesoPrefrioFolio::query()
            ->where('folio_id', $folio->id)
            ->whereNotIn('estado', [
                EstadoFolioProcesoPrefrio::Retirado->value,
                EstadoFolioProcesoPrefrio::Cancelado->value,
            ])
            ->lockForUpdate()
            ->exists();

        if ($asignado) {
            throw new ConflictoOperacion(
                'El folio ya pertenece a otro proceso de prefrío no retirado.',
            );
        }

        $this->validarPosicion(
            $proceso,
            (string) $datosFolio['posicion_tunel_prefrio_id'],
        );

        $asignacion = ProcesoPrefrioFolio::create([
            'proceso_prefrio_id' => $proceso->id,
            'folio_id' => $folio->id,
            'posicion_tunel_prefrio_id' => $datosFolio['posicion_tunel_prefrio_id'],
            'estado' => $this->estadoAsignacion($proceso),
            'temperatura_inicial' => $datosFolio['temperatura_inicial'] ?? null,
            'temperatura_final' => $datosFolio['temperatura_final'] ?? null,
            'cargado_at' => $this->momento($datosFolio['cargado_at']),
            'motivo_resultado' => $this->motivoResultado($proceso),
            'observacion' => $this->textoOpcional($datosFolio['observacion'] ?? null),
            'cargado_por_user_id' => $usuario->id,
        ]);

        $this->sincronizarAprobacionTermicaHistorica(
            $proceso,
            $folio,
            $usuario,
            $this->textoOpcional($datosFolio['observacion'] ?? null),
            $cambios,
        );

        $cambios[] = [
            'entidad' => 'folio_agregado',
            'id' => $asignacion->id,
            'numero_folio' => $folio->numero_folio,
            'despues' => $this->snapshot($asignacion, $this->camposAsignacion()),
        ];
    }

    /**
     * Una corrección histórica puede incorporar a un proceso ya aprobado un folio
     * que quedó omitido en el registro original. En ese caso debe recibir la misma
     * transición térmica que habría obtenido durante la aprobación normal.
     *
     * La reparación se limita al estado pendiente original para no reescribir
     * folios que ya tuvieron movimientos operacionales posteriores.
     *
     * @param  array<int, array<string, mixed>>  $cambios
     */
    private function sincronizarAprobacionTermicaHistorica(
        ProcesoPrefrio $proceso,
        Folio $folio,
        User $usuario,
        ?string $observacion,
        array &$cambios,
    ): void {
        if ($proceso->estado !== EstadoProcesoPrefrio::Aprobado
            || ! $folio->activo
            || $folio->estado_operacional !== EstadoOperacionalFolio::PendientePrefrio
            || $folio->condicion_termica !== CondicionTermicaFolio::PendientePrefrio) {
            return;
        }

        $campos = [
            'estado_operacional',
            'condicion_termica',
            'habilitacion_almacenamiento',
            'fuente_habilitacion_almacenamiento',
            'habilitado_almacenamiento_at',
            'habilitado_almacenamiento_por_user_id',
            'retencion_termica_motivo',
        ];
        $antes = $this->snapshot($folio, $campos);

        $this->habilitacion->habilitar(
            $folio,
            CondicionTermicaFolio::PrefrioAprobado,
            FuenteHabilitacionAlmacenamiento::PrefrioAprobado,
            $usuario,
            null,
            'prefrio',
            $proceso->id,
            $observacion,
        );

        $cambios[] = [
            'entidad' => 'folio_resultado_termico',
            'id' => $folio->id,
            'numero_folio' => $folio->numero_folio,
            'antes' => $antes,
            'despues' => $this->snapshot($folio->refresh(), $campos),
        ];
    }

    private function validarCronologia(ProcesoPrefrio $proceso): void
    {
        $eventos = $proceso->eventos()
            ->where('tipo', '!=', TipoEventoPrefrio::CorreccionAdministrativa->value)
            ->lockForUpdate()
            ->get();
        $inicio = $eventos->firstWhere('tipo', TipoEventoPrefrio::ProcesoIniciado)?->ocurrido_at;
        $verificacion = $eventos
            ->where('tipo', TipoEventoPrefrio::VerificacionFinal)
            ->sortByDesc('ocurrido_at')
            ->first()?->ocurrido_at;
        $final = $eventos
            ->whereIn('tipo', [
                TipoEventoPrefrio::Aprobacion,
                TipoEventoPrefrio::Reproceso,
                TipoEventoPrefrio::Cancelacion,
            ])
            ->sortByDesc('ocurrido_at')
            ->first()?->ocurrido_at;

        if ($inicio && $verificacion && $verificacion->lessThan($inicio)) {
            throw new ConflictoOperacion(
                'La verificación final no puede quedar antes del inicio del proceso.',
            );
        }

        if ($inicio && $final && $final->lessThan($inicio)) {
            throw new ConflictoOperacion(
                'El cierre no puede quedar antes del inicio del proceso.',
            );
        }

        if ($verificacion && $final && $final->lessThan($verificacion)) {
            throw new ConflictoOperacion(
                'El cierre no puede quedar antes de la verificación final.',
            );
        }

        $operacionales = [
            TipoEventoPrefrio::InversionRegistrada,
            TipoEventoPrefrio::Pausa,
            TipoEventoPrefrio::Reanudacion,
            TipoEventoPrefrio::Deshielo,
            TipoEventoPrefrio::Lectura,
            TipoEventoPrefrio::FinalizacionParcial,
        ];
        foreach ($eventos->whereIn('tipo', $operacionales) as $evento) {
            if ($inicio && $evento->ocurrido_at->lessThan($inicio)) {
                throw new ConflictoOperacion(
                    'Los eventos operacionales no pueden quedar antes del inicio.',
                );
            }
            if ($verificacion && $evento->ocurrido_at->greaterThan($verificacion)) {
                throw new ConflictoOperacion(
                    'Los eventos operacionales no pueden quedar después de la verificación final.',
                );
            }
        }

    }

    private function validarPosiciones(ProcesoPrefrio $proceso): void
    {
        $grupos = $proceso->folios()
            ->enTunel()
            ->with('folio:id,numero_folio,tipo_bulto')
            ->lockForUpdate()
            ->get()
            ->groupBy('posicion_tunel_prefrio_id');

        foreach ($grupos as $asignaciones) {
            if ($asignaciones->count() <= 1) {
                continue;
            }

            if ($asignaciones->contains(
                fn (ProcesoPrefrioFolio $asignacion): bool => $asignacion->folio?->tipo_bulto
                    !== TipoBulto::Saldo,
            )) {
                throw new ConflictoOperacion(
                    'Una posición compartida solo puede contener folios de tipo saldo.',
                );
            }
        }
    }

    private function validarPosicion(ProcesoPrefrio $proceso, string $posicionId): void
    {
        $posicion = PosicionTunelPrefrio::query()->lockForUpdate()->findOrFail($posicionId);
        if ($posicion->tunel_prefrio_id !== $proceso->tunel_prefrio_id) {
            throw new DomainException('La posición no pertenece al túnel del proceso.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function hitosDesdeEventos(ProcesoPrefrio $proceso): array
    {
        $consulta = fn (TipoEventoPrefrio $tipo) => $proceso->eventos()
            ->where('tipo', $tipo->value)
            ->orderByDesc('ocurrido_at')
            ->value('ocurrido_at');
        $finalizado = $proceso->eventos()
            ->whereIn('tipo', [
                TipoEventoPrefrio::Aprobacion->value,
                TipoEventoPrefrio::Reproceso->value,
                TipoEventoPrefrio::Cancelacion->value,
            ])
            ->orderByDesc('ocurrido_at')
            ->value('ocurrido_at');

        return [
            'iniciado_at' => $consulta(TipoEventoPrefrio::ProcesoIniciado),
            'pendiente_verificacion_at' => $consulta(TipoEventoPrefrio::VerificacionFinal),
            'finalizado_at' => $finalizado,
        ];
    }

    private function estadoAsignacion(ProcesoPrefrio $proceso): EstadoFolioProcesoPrefrio
    {
        return match ($proceso->estado) {
            EstadoProcesoPrefrio::Aprobado => EstadoFolioProcesoPrefrio::Aprobado,
            EstadoProcesoPrefrio::RequiereReproceso => EstadoFolioProcesoPrefrio::RequiereReproceso,
            EstadoProcesoPrefrio::Cancelado => EstadoFolioProcesoPrefrio::Cancelado,
            EstadoProcesoPrefrio::EnProceso,
            EstadoProcesoPrefrio::PendienteVerificacion => EstadoFolioProcesoPrefrio::EnProceso,
            default => EstadoFolioProcesoPrefrio::Cargado,
        };
    }

    private function motivoResultado(ProcesoPrefrio $proceso): ?string
    {
        return match ($proceso->estado) {
            EstadoProcesoPrefrio::Aprobado => 'prefrio_aprobado',
            EstadoProcesoPrefrio::RequiereReproceso => 'requiere_reproceso',
            EstadoProcesoPrefrio::Cancelado => 'proceso_cancelado',
            default => null,
        };
    }

    private function asegurarAdministrador(User $usuario): void
    {
        if (! $usuario->activo || $usuario->rol !== RolUsuario::Administrador) {
            throw new OperacionNoAutorizada(
                'Solo un administrador puede corregir procesos históricos de prefrío.',
            );
        }
    }

    private function momento(string $valor): CarbonImmutable
    {
        $momento = CarbonImmutable::parse($valor, config('app.operational_timezone', config('app.timezone')))->utc();
        if ($momento->greaterThan(now()->addMinutes(5))) {
            throw new ConflictoOperacion('La corrección no puede dejar fechas en el futuro.');
        }

        return $momento;
    }

    /**
     * @return array<int, string>
     */
    private function camposProceso(): array
    {
        return [
            'setpoint',
            'duracion_objetivo_minutos',
            'formato_referencia',
            'iniciado_at',
            'pendiente_verificacion_at',
            'finalizado_at',
            'observacion',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function camposAsignacion(): array
    {
        return [
            'posicion_tunel_prefrio_id',
            'estado',
            'temperatura_inicial',
            'temperatura_final',
            'cargado_at',
            'retirado_at',
            'motivo_resultado',
            'observacion',
        ];
    }

    /**
     * @param  array<int, string>  $campos
     * @return array<string, mixed>
     */
    private function snapshot(Model $modelo, array $campos): array
    {
        return $this->normalizar($modelo->only($campos));
    }

    private function textoOpcional(mixed $valor): ?string
    {
        $texto = Str::of((string) $valor)->squish()->toString();

        return $texto !== '' ? $texto : null;
    }

    private function textoObligatorio(mixed $valor): string
    {
        return Str::of((string) $valor)->squish()->toString();
    }

    private function normalizar(mixed $valor): mixed
    {
        if ($valor instanceof BackedEnum) {
            return $valor->value;
        }

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
            throw new DomainException(
                'El contenido de la corrección no es serializable.',
                previous: $exception,
            );
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
}

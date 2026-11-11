<?php

namespace App\Services\Cargas;

use App\Enums\CondicionTermicaFolio;
use App\Enums\ContenidoCamara;
use App\Enums\DominioTransicionOperacional;
use App\Enums\EstadoCamara;
use App\Enums\EstadoCarga;
use App\Enums\EstadoCargaFolio;
use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoIncidenciaCarga;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoProcesoPrefrio;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\ModalidadSalidaCarga;
use App\Enums\OrigenReingresoCamara;
use App\Enums\PrioridadCarga;
use App\Enums\TipoBulto;
use App\Enums\TipoEventoCarga;
use App\Enums\TipoResolucionIncidenciaCarga;
use App\Exceptions\ConflictoOperacion;
use App\Exceptions\DiferenciasCambioFolioCarga;
use App\Exceptions\FoliosCargaInvalidos;
use App\Exceptions\OperacionNoAutorizada;
use App\Models\Anden;
use App\Models\Camara;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\EventoCarga;
use App\Models\Folio;
use App\Models\IncidenciaCargaFolio;
use App\Models\ReingresoFolioCamara;
use App\Models\ReservaCargaFolio;
use App\Models\TareaMovimiento;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\InspeccionSag\ServicioExistenciasSag;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Transiciones\ComandoTransicionOperacional;
use App\Services\Transiciones\MotorTransicionesOperacionales;
use App\Services\Transiciones\NormalizadorTransicionOperacional;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class ServicioCarga
{
    public function __construct(
        private readonly AlcanceOperacionalUsuario $alcance,
        private readonly ServicioTareasCarga $servicioTareas,
        private readonly ServicioTemporadaActiva $temporadaActiva,
        private readonly MotorTransicionesOperacionales $motorTransiciones,
        private readonly NormalizadorTransicionOperacional $normalizadorTransiciones,
        private readonly ServicioInstructivosCarga $instructivos,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos, User $usuario): Carga
    {
        $this->asegurarGestionAutorizada($usuario);

        $accion = function () use ($datos, $usuario): Carga {
            $temporada = $this->temporadaActiva->obtener(bloquear: true);
            $camaraObjetivoId = $datos['camara_objetivo_id'] ?? null;
            $this->asegurarCamaraObjetivoValida($camaraObjetivoId);
            $andenPrevistoId = $datos['anden_previsto_id'] ?? null;
            $this->asegurarAndenValido($andenPrevistoId);

            $carga = Carga::create([
                'temporada_id' => $temporada->id,
                'codigo' => $this->siguienteCodigoBloqueado(),
                'numero_orden_externa' => $this->textoOpcional($datos['numero_orden_externa'] ?? null),
                'estado' => EstadoCarga::Borrador,
                'prioridad' => PrioridadCarga::from(
                    $datos['prioridad'] ?? PrioridadCarga::Normal->value,
                ),
                'camara_objetivo_id' => $camaraObjetivoId,
                'anden_previsto_id' => $andenPrevistoId,
                'observacion' => $this->textoOpcional($datos['observacion'] ?? null),
                'version' => 1,
                'creada_por_user_id' => $usuario->id,
                'actualizada_por_user_id' => $usuario->id,
            ]);

            $this->registrarEvento($carga, TipoEventoCarga::Creada, $usuario);

            return $carga;
        };

        return $this->transicionar(
            'crear',
            $usuario,
            $datos,
            $accion,
            referencia: $datos['numero_orden_externa'] ?? null,
        );
    }

    /**
     * Registra en una sola transición una salida física desde Prefrío hacia
     * andén/camión. No crea ubicaciones ni movimientos de cámara ficticios.
     *
     * @param  array<string, mixed>  $datos
     */
    public function registrarDespachoDirectoPrefrio(array $datos, User $usuario): Carga
    {
        $this->asegurarGestionAutorizada($usuario);

        if (! $this->alcance->puedeCerrarDespachoFrigorifico($usuario)) {
            throw new OperacionNoAutorizada(
                'El usuario no está autorizado para registrar salidas directas desde Prefrío.',
            );
        }

        $operacionId = (string) $datos['operacion_id'];
        $numeros = $this->normalizarNumerosFolio($datos['folios']);
        $salidaAt = CarbonImmutable::parse($datos['ocurrido_at']);
        $ahora = CarbonImmutable::now();

        if ($salidaAt->isFuture()) {
            throw new DomainException('La fecha y hora real de salida no puede estar en el futuro.');
        }

        $patente = mb_strtoupper(trim((string) $datos['patente']));
        $conductor = trim((string) $datos['conductor']);

        if ($patente === '' || $conductor === '') {
            throw new DomainException(
                'La patente y el conductor son obligatorios para registrar la salida directa.',
            );
        }

        $numeroOrden = $this->textoOpcional($datos['numero_orden_externa'] ?? null);
        $observacion = $this->textoOpcional($datos['observacion'] ?? null);
        $prioridad = PrioridadCarga::from(
            $datos['prioridad'] ?? PrioridadCarga::Normal->value,
        );
        $payload = [
            'operacion_id' => $operacionId,
            'folios' => $numeros,
            'numero_orden_externa' => $numeroOrden,
            'prioridad' => $prioridad->value,
            'anden_id' => $datos['anden_id'],
            'patente' => $patente,
            'conductor' => $conductor,
            'ocurrido_at' => $salidaAt->toAtomString(),
            'observacion' => $observacion,
            'usuario_id' => $usuario->id,
        ];
        $payloadHash = $this->normalizadorTransiciones->hash($payload);

        $accion = function () use (
            $operacionId,
            $numeros,
            $salidaAt,
            $ahora,
            $patente,
            $conductor,
            $numeroOrden,
            $observacion,
            $prioridad,
            $datos,
            $usuario,
            $payloadHash,
        ): Carga {
            $existente = Carga::query()
                ->where('operacion_cierre_id', $operacionId)
                ->lockForUpdate()
                ->first();

            if ($existente) {
                return $existente;
            }

            $temporada = $this->temporadaActiva->obtener(bloquear: true);
            $anden = Anden::query()
                ->whereKey($datos['anden_id'])
                ->where('activo', true)
                ->lockForUpdate()
                ->first();

            if (! $anden) {
                throw new DomainException('El andén indicado no existe o se encuentra inactivo.');
            }

            if ($numeroOrden !== null && Carga::query()
                ->where('numero_orden_externa', $numeroOrden)
                ->lockForUpdate()
                ->exists()) {
                throw new DomainException(
                    "Ya existe una carga con el número de orden externa {$numeroOrden}.",
                );
            }

            $folios = Folio::query()
                ->whereIn('numero_folio', $numeros)
                ->with([
                    'ubicacionActual',
                    'procesosPrefrio' => fn ($consulta) => $consulta
                        ->where('estado', EstadoFolioProcesoPrefrio::Aprobado->value)
                        ->whereHas('proceso', fn ($proceso) => $proceso
                            ->where('estado', EstadoProcesoPrefrio::Aprobado->value))
                        ->with('proceso:id,codigo,estado,finalizado_at'),
                ])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('numero_folio');
            $reservas = ReservaCargaFolio::query()
                ->whereIn('folio_id', $folios->pluck('id'))
                ->with('asignacion.carga:id,codigo')
                ->orderBy('folio_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('folio_id');
            $errores = [];

            foreach ($numeros as $numero) {
                /** @var Folio|null $folio */
                $folio = $folios->get($numero);

                if (! $folio) {
                    $errores[] = $this->errorFolio(
                        $numero,
                        'no_existe',
                        "El folio {$numero} no existe en el sistema.",
                    );

                    continue;
                }

                $reserva = $reservas->get($folio->id);

                if ($reserva) {
                    $errores[] = $this->errorFolio(
                        $numero,
                        'asignado_otra_carga',
                        $this->mensajeFolioReservado($numero, $reserva),
                    );

                    continue;
                }

                $error = $this->motivoFolioNoAsignableSalidaDirecta(
                    $folio,
                    $temporada->id,
                    $salidaAt,
                );

                if ($error) {
                    $errores[] = $this->errorFolio(
                        $numero,
                        $error['codigo'],
                        $error['mensaje'],
                    );
                }
            }

            if ($errores !== []) {
                throw new FoliosCargaInvalidos($errores);
            }

            try {
                $carga = Carga::create([
                    'temporada_id' => $temporada->id,
                    'codigo' => $this->siguienteCodigoBloqueado(),
                    'numero_orden_externa' => $numeroOrden,
                    'estado' => EstadoCarga::Cerrada,
                    'modalidad_salida' => ModalidadSalidaCarga::DirectaPrefrio,
                    'prioridad' => $prioridad,
                    'camara_objetivo_id' => null,
                    'anden_previsto_id' => $anden->id,
                    'observacion' => $observacion,
                    'version' => 1,
                    'creada_por_user_id' => $usuario->id,
                    'actualizada_por_user_id' => $usuario->id,
                    'publicada_por_user_id' => $usuario->id,
                    'publicada_at' => $salidaAt,
                    'operacion_cierre_id' => $operacionId,
                    'cierre_payload_hash' => $payloadHash,
                    'patente' => $patente,
                    'conductor' => $conductor,
                    'observacion_cierre' => $observacion,
                    'cerrada_por_user_id' => $usuario->id,
                    'cerrada_at' => $salidaAt,
                    'cierre_registrado_at' => $ahora,
                ]);
            } catch (QueryException $exception) {
                if ($exception->getCode() === '23000') {
                    throw new ConflictoOperacion(
                        'La salida directa entró en conflicto con otra carga registrada al mismo tiempo.',
                        previous: $exception,
                    );
                }

                throw $exception;
            }

            $this->registrarEvento(
                $carga,
                TipoEventoCarga::Creada,
                $usuario,
                datos: [
                    'modalidad_salida' => ModalidadSalidaCarga::DirectaPrefrio->value,
                    'salida_ocurrida_at' => $salidaAt->toAtomString(),
                    'registrada_at' => $ahora->toAtomString(),
                ],
            );

            foreach ($numeros as $numero) {
                /** @var Folio $folio */
                $folio = $folios->get($numero);
                CargaFolio::create([
                    'carga_id' => $carga->id,
                    'folio_id' => $folio->id,
                    'estado' => EstadoCargaFolio::Despachado,
                    'anden_id' => $anden->id,
                    'asignado_por_user_id' => $usuario->id,
                    'asignado_at' => $salidaAt,
                    'enviado_anden_por_user_id' => $usuario->id,
                    'enviado_anden_at' => $salidaAt,
                    'finalizado_por_user_id' => $usuario->id,
                    'finalizado_at' => $salidaAt,
                    'motivo_finalizacion' => 'Salida directa desde Prefrío confirmada',
                ]);
                $folio->update([
                    'estado_operacional' => EstadoOperacionalFolio::Despachado,
                    'activo' => false,
                ]);
                $this->registrarEvento(
                    $carga,
                    TipoEventoCarga::FolioAsignado,
                    $usuario,
                    $folio,
                    [
                        'modalidad_salida' => ModalidadSalidaCarga::DirectaPrefrio->value,
                        'salida_ocurrida_at' => $salidaAt->toAtomString(),
                    ],
                );
                $this->registrarEvento(
                    $carga,
                    TipoEventoCarga::FolioEnviadoAnden,
                    $usuario,
                    $folio,
                    [
                        'anden_id' => $anden->id,
                        'sin_movimiento_camara' => true,
                        'salida_ocurrida_at' => $salidaAt->toAtomString(),
                    ],
                );
                $this->registrarEvento(
                    $carga,
                    TipoEventoCarga::FolioDespachado,
                    $usuario,
                    $folio,
                    ['salida_fisica_at' => $salidaAt->toAtomString()],
                );
            }

            $this->registrarEvento(
                $carga,
                TipoEventoCarga::DespachoDirectoPrefrio,
                $usuario,
                datos: [
                    'anden_id' => $anden->id,
                    'cantidad_folios' => count($numeros),
                    'salida_ocurrida_at' => $salidaAt->toAtomString(),
                    'registrada_at' => $ahora->toAtomString(),
                ],
            );
            $this->registrarEvento(
                $carga,
                TipoEventoCarga::CierreDespacho,
                $usuario,
                datos: [
                    'patente' => $patente,
                    'conductor' => $conductor,
                    'cantidad_folios' => count($numeros),
                    'modalidad_salida' => ModalidadSalidaCarga::DirectaPrefrio->value,
                    'salida_ocurrida_at' => $salidaAt->toAtomString(),
                    'registrada_at' => $ahora->toAtomString(),
                ],
            );

            return $carga->refresh();
        };

        return $this->transicionar(
            'despacho_directo_prefrio',
            $usuario,
            $payload,
            $accion,
            referencia: $numeroOrden,
            operacionId: $operacionId,
            ocurridoAt: $salidaAt,
        );
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(
        Carga $carga,
        array $datos,
        User $usuario,
        int $versionEsperada,
    ): Carga {
        $this->asegurarGestionAutorizada($usuario);

        $accion = function () use ($carga, $datos, $usuario, $versionEsperada): Carga {
            $cargaBloqueada = $this->bloquearCarga($carga);
            $this->asegurarEditable($cargaBloqueada);
            $this->asegurarVersion($cargaBloqueada, $versionEsperada);
            $camaraObjetivoId = $datos['camara_objetivo_id'] ?? null;
            $this->asegurarCamaraObjetivoValida($camaraObjetivoId);
            $andenPrevistoId = array_key_exists('anden_previsto_id', $datos)
                ? $datos['anden_previsto_id']
                : $cargaBloqueada->anden_previsto_id;
            $this->asegurarAndenValido($andenPrevistoId);
            $prioridadAnterior = $cargaBloqueada->prioridad;
            $prioridadNueva = PrioridadCarga::from(
                $datos['prioridad'] ?? $prioridadAnterior->value,
            );

            $cargaBloqueada->update([
                'numero_orden_externa' => $this->textoOpcional(
                    $datos['numero_orden_externa'] ?? null,
                ),
                'prioridad' => $prioridadNueva,
                'camara_objetivo_id' => $camaraObjetivoId,
                'anden_previsto_id' => $andenPrevistoId,
                'observacion' => $this->textoOpcional($datos['observacion'] ?? null),
                'version' => $cargaBloqueada->version + 1,
                'actualizada_por_user_id' => $usuario->id,
            ]);

            $this->registrarEvento(
                $cargaBloqueada,
                TipoEventoCarga::Actualizada,
                $usuario,
                datos: [
                    'prioridad_anterior' => $prioridadAnterior->value,
                    'prioridad_nueva' => $prioridadNueva->value,
                ],
            );

            return $cargaBloqueada->refresh();
        };

        return $this->transicionar(
            'actualizar',
            $usuario,
            [...$datos, 'version_esperada' => $versionEsperada],
            $accion,
            $carga,
        );
    }

    /**
     * @param  array<int, string>  $numerosFolio
     */
    public function agregarFolios(
        Carga $carga,
        array $numerosFolio,
        User $usuario,
        int $versionEsperada,
        ?string $instructivoId = null,
    ): Carga {
        $this->asegurarGestionAutorizada($usuario);

        $accion = function () use (
            $carga,
            $numerosFolio,
            $usuario,
            $versionEsperada,
            $instructivoId,
        ): Carga {
            $cargaBloqueada = $this->bloquearCarga($carga);
            $this->asegurarEditable($cargaBloqueada);
            $this->asegurarVersion($cargaBloqueada, $versionEsperada);

            $numeros = $this->normalizarNumerosFolio($numerosFolio);
            $instructivo = $this->instructivos->paraAsignacion($cargaBloqueada, $instructivoId, count($numeros));
            $cantidadActual = CargaFolio::query()
                ->where('carga_id', $cargaBloqueada->id)
                ->whereHas('reservaActiva')
                ->lockForUpdate()
                ->count();

            if ($cantidadActual + count($numeros) > 26) {
                throw new DomainException(
                    'Una carga no puede contener más de 26 folios.',
                );
            }

            $folios = Folio::query()
                ->whereIn('numero_folio', $numeros)
                ->with('ubicacionActual.posicion.camara')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('numero_folio');
            $reservas = ReservaCargaFolio::query()
                ->whereIn('folio_id', $folios->pluck('id'))
                ->with('asignacion.carga:id,codigo')
                ->orderBy('folio_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('folio_id');
            $errores = [];

            foreach ($numeros as $numero) {
                /** @var Folio|null $folio */
                $folio = $folios->get($numero);

                if (! $folio) {
                    $errores[] = $this->errorFolio(
                        $numero,
                        'no_existe',
                        "El folio {$numero} no existe en el sistema.",
                    );

                    continue;
                }

                $reserva = $reservas->get($folio->id);

                if ($reserva) {
                    $asignacion = $reserva->asignacion;
                    $codigo = $asignacion?->carga_id === $cargaBloqueada->id
                        ? 'ya_asignado_carga'
                        : 'asignado_otra_carga';
                    $errores[] = $this->errorFolio(
                        $numero,
                        $codigo,
                        $this->mensajeFolioReservado($numero, $reserva),
                    );

                    continue;
                }

                $error = $this->motivoFolioNoAsignable($folio, $cargaBloqueada->temporada_id);

                if ($error) {
                    $errores[] = $this->errorFolio(
                        $numero,
                        $error['codigo'],
                        $error['mensaje'],
                    );
                }
            }

            if ($errores !== []) {
                throw new FoliosCargaInvalidos($errores);
            }

            $asignados = [];

            foreach ($numeros as $numero) {
                /** @var Folio $folio */
                $folio = $folios->get($numero);

                try {
                    $asignacion = CargaFolio::create([
                        'carga_id' => $cargaBloqueada->id,
                        'instructivo_embarque_id' => $instructivo?->id,
                        'folio_id' => $folio->id,
                        'estado' => EstadoCargaFolio::Pendiente,
                        'asignado_por_user_id' => $usuario->id,
                        'asignado_at' => now(),
                    ]);
                    ReservaCargaFolio::create([
                        'folio_id' => $folio->id,
                        'carga_folio_id' => $asignacion->id,
                    ]);
                } catch (QueryException $exception) {
                    if ($exception->getCode() === '23000') {
                        throw new ConflictoOperacion(sprintf(
                            'El folio %s fue asignado a otra carga mientras se procesaba la orden.',
                            $folio->numero_folio,
                        ), previous: $exception);
                    }

                    throw $exception;
                }

                $asignados[] = $folio;
            }

            $this->incrementarVersion($cargaBloqueada, $usuario);

            foreach ($asignados as $folio) {
                $this->registrarEvento(
                    $cargaBloqueada,
                    TipoEventoCarga::FolioAsignado,
                    $usuario,
                    $folio,
                );
            }

            if ($cargaBloqueada->estado === EstadoCarga::Pendiente) {
                $this->servicioTareas->sincronizar($cargaBloqueada);
            }

            return $cargaBloqueada->refresh();
        };

        return $this->transicionar(
            'asignar_folios',
            $usuario,
            [
                'carga_id' => $carga->id,
                'folios' => $numerosFolio,
                'instructivo_embarque_id' => $instructivoId,
                'version_esperada' => $versionEsperada,
            ],
            $accion,
            $carga,
        );
    }

    public function segregarFolios(Carga $carga, array $numerosFolio, User $usuario, int $versionEsperada, ?string $instructivoId): Carga
    {
        $this->asegurarGestionAutorizada($usuario);

        return $this->transicionar('segregar_folios', $usuario, [
            'carga_id' => $carga->id, 'folios' => $numerosFolio,
            'version_esperada' => $versionEsperada, 'instructivo_embarque_id' => $instructivoId,
        ], function () use ($carga, $numerosFolio, $usuario, $versionEsperada, $instructivoId): Carga {
            $carga = $this->bloquearCarga($carga);
            $this->asegurarEditable($carga);
            $this->asegurarVersion($carga, $versionEsperada);
            $numeros = $this->normalizarNumerosFolio($numerosFolio);
            $asignaciones = CargaFolio::query()->where('carga_id', $carga->id)->whereHas('reservaActiva')
                ->whereHas('folio', fn ($q) => $q->whereIn('numero_folio', $numeros))
                ->with('folio')->orderBy('folio_id')->lockForUpdate()->get();
            if ($asignaciones->count() !== count($numeros)) {
                throw new DomainException('Todos los pallets deben pertenecer actualmente a esta orden de preparación.');
            }
            if (TareaMovimiento::query()->whereIn('folio_id', $asignaciones->pluck('folio_id'))
                ->whereIn('estado', ['asumida', 'en_proceso'])->exists()) {
                throw new ConflictoOperacion('Hay un pallet con una tarea tomada. Libera la tarea antes de cambiar su instructivo.');
            }
            $instructivo = $this->instructivos->paraAsignacion($carga, $instructivoId, $asignaciones->count(), $asignaciones->pluck('id')->all());
            foreach ($asignaciones as $asignacion) {
                $anterior = $asignacion->instructivo_embarque_id;
                $asignacion->update(['instructivo_embarque_id' => $instructivo?->id]);
                $this->registrarEvento($carga, TipoEventoCarga::FolioSegregado, $usuario, $asignacion->folio, [
                    'instructivo_anterior_id' => $anterior, 'instructivo_embarque_id' => $instructivo?->id,
                ]);
            }
            $this->incrementarVersion($carga, $usuario);

            return $carga->refresh();
        }, $carga);
    }

    public function quitarFolio(
        Carga $carga,
        Folio $folio,
        User $usuario,
        int $versionEsperada,
        ?string $motivo = null,
    ): Carga {
        $this->asegurarGestionAutorizada($usuario);

        $accion = function () use (
            $carga,
            $folio,
            $usuario,
            $versionEsperada,
            $motivo,
        ): Carga {
            $cargaBloqueada = $this->bloquearCarga($carga);
            $this->asegurarAjustable($cargaBloqueada);
            $this->asegurarVersion($cargaBloqueada, $versionEsperada);
            $enCurso = $this->enCurso($cargaBloqueada);
            $motivoNormalizado = $this->textoOpcional($motivo);
            if ($enCurso && $motivoNormalizado === null) {
                throw new DomainException('Indica por qué el pallet sale de la carga.');
            }

            $asignaciones = CargaFolio::query()
                ->where('carga_id', $cargaBloqueada->id)
                ->whereHas('reservaActiva')
                ->orderBy('folio_id')
                ->lockForUpdate()
                ->get();
            $asignacion = $asignaciones->firstWhere('folio_id', $folio->id);

            if (! $asignacion) {
                throw new DomainException(
                    'El folio no pertenece actualmente a esta carga.',
                );
            }

            if ($cargaBloqueada->estado !== EstadoCarga::Borrador
                && $asignaciones->count() === 1) {
                throw new DomainException(
                    'Una carga publicada debe conservar al menos un folio. Si ya no sale ningún pallet, cancela la carga.',
                );
            }

            $estadoPrevio = $asignacion->estado;
            $reingreso = $this->liberarAsignacion(
                $cargaBloqueada,
                $asignacion->setRelation('folio', $folio),
                EstadoCargaFolio::Descartado,
                OrigenReingresoCamara::PalletQuitado,
                $motivoNormalizado,
                $usuario,
            );
            if ($enCurso) {
                $this->recalcularEstadoDespacho($cargaBloqueada);
            }
            $this->incrementarVersion($cargaBloqueada, $usuario);
            $this->registrarEvento(
                $cargaBloqueada,
                TipoEventoCarga::FolioDesasignado,
                $usuario,
                $folio,
                [
                    'motivo' => $motivoNormalizado,
                    'causa' => 'pallet_quitado',
                    'estado_carga_previo' => $estadoPrevio->value,
                    'reingreso_id' => $reingreso?->id,
                ],
            );

            $this->sincronizarOperacion($cargaBloqueada, $usuario);

            return $cargaBloqueada->refresh();
        };

        return $this->transicionar(
            'desasignar_folio',
            $usuario,
            [
                'carga_id' => $carga->id,
                'folio_id' => $folio->id,
                'version_esperada' => $versionEsperada,
                'motivo' => $motivo,
            ],
            $accion,
            $carga,
        );
    }

    /**
     * Cambio comercial de un pallet por otro en cualquier etapa antes del cierre.
     * Las diferencias de cliente, especie, variedad, calibre o embalaje no
     * bloquean: se devuelven como advertencias que oficina debe confirmar.
     */
    public function cambiarFolio(
        Carga $carga,
        Folio $saliente,
        string $numeroEntrante,
        User $usuario,
        int $versionEsperada,
        string $motivo,
        bool $confirmarDiferencias = false,
    ): Carga {
        $this->asegurarGestionAutorizada($usuario);
        $numero = mb_strtoupper(trim($numeroEntrante));

        $accion = function () use (
            $carga,
            $saliente,
            $numero,
            $usuario,
            $versionEsperada,
            $motivo,
            $confirmarDiferencias,
        ): Carga {
            $cargaBloqueada = $this->bloquearCarga($carga);
            $this->asegurarAjustable($cargaBloqueada);
            $this->asegurarVersion($cargaBloqueada, $versionEsperada);
            $motivoNormalizado = $this->textoOpcional($motivo);
            if ($motivoNormalizado === null || mb_strlen($motivoNormalizado) < 3) {
                throw new DomainException('Indica el motivo del cambio de pallet.');
            }
            if ($numero === '' || $numero === mb_strtoupper($saliente->numero_folio)) {
                throw new DomainException('Indica un folio de reemplazo distinto al que sale.');
            }

            $asignacion = CargaFolio::query()
                ->where('carga_id', $cargaBloqueada->id)
                ->where('folio_id', $saliente->id)
                ->whereHas('reservaActiva')
                ->lockForUpdate()
                ->first();
            if (! $asignacion) {
                throw new DomainException('El folio que sale no pertenece actualmente a esta carga.');
            }

            $entrante = Folio::query()
                ->where('numero_folio', $numero)
                ->with('ubicacionActual.posicion.camara')
                ->lockForUpdate()
                ->first();
            if (! $entrante) {
                throw new DomainException("El folio {$numero} no existe en el sistema.");
            }
            $reservaEntrante = ReservaCargaFolio::query()
                ->where('folio_id', $entrante->id)
                ->with('asignacion.carga:id,codigo')
                ->lockForUpdate()
                ->first();
            if ($reservaEntrante) {
                throw new DomainException($this->mensajeFolioReservado($numero, $reservaEntrante));
            }
            $error = $this->motivoFolioNoAsignable($entrante, $cargaBloqueada->temporada_id);
            if ($error) {
                throw new DomainException($error['mensaje']);
            }

            $diferencias = $this->diferenciasComerciales($saliente, $entrante);
            if ($diferencias !== [] && ! $confirmarDiferencias) {
                throw new DiferenciasCambioFolioCarga($diferencias);
            }

            $estadoPrevio = $asignacion->estado;
            $reingreso = $this->liberarAsignacion(
                $cargaBloqueada,
                $asignacion->setRelation('folio', $saliente),
                EstadoCargaFolio::Reemplazado,
                OrigenReingresoCamara::PalletCambiado,
                $motivoNormalizado,
                $usuario,
            );

            try {
                $nueva = CargaFolio::create([
                    'carga_id' => $cargaBloqueada->id,
                    'instructivo_embarque_id' => $asignacion->instructivo_embarque_id,
                    'folio_id' => $entrante->id,
                    'estado' => EstadoCargaFolio::Pendiente,
                    'reemplaza_a_carga_folio_id' => $asignacion->id,
                    'asignado_por_user_id' => $usuario->id,
                    'asignado_at' => now(),
                ]);
                ReservaCargaFolio::create([
                    'folio_id' => $entrante->id,
                    'carga_folio_id' => $nueva->id,
                ]);
            } catch (QueryException $exception) {
                if ($exception->getCode() === '23000') {
                    throw new ConflictoOperacion(
                        "El folio {$numero} fue asignado a otra carga mientras se procesaba el cambio.",
                        previous: $exception,
                    );
                }

                throw $exception;
            }

            if ($this->enCurso($cargaBloqueada)) {
                $this->recalcularEstadoDespacho($cargaBloqueada);
            }
            $this->incrementarVersion($cargaBloqueada, $usuario);
            $this->registrarEvento(
                $cargaBloqueada,
                TipoEventoCarga::FolioReemplazado,
                $usuario,
                $saliente,
                [
                    'causa' => 'cambio_comercial',
                    'motivo' => $motivoNormalizado,
                    'folio_saliente' => $saliente->numero_folio,
                    'folio_reemplazo_id' => $entrante->id,
                    'folio_entrante' => $entrante->numero_folio,
                    'estado_carga_previo' => $estadoPrevio->value,
                    'diferencias' => array_column($diferencias, 'codigo'),
                    'reingreso_id' => $reingreso?->id,
                ],
            );

            $this->sincronizarOperacion($cargaBloqueada, $usuario);

            return $cargaBloqueada->refresh();
        };

        return $this->transicionar(
            'cambiar_folio',
            $usuario,
            [
                'carga_id' => $carga->id,
                'folio_saliente_id' => $saliente->id,
                'folio_entrante' => $numero,
                'version_esperada' => $versionEsperada,
                'motivo' => $motivo,
                'confirmar_diferencias' => $confirmarDiferencias,
            ],
            $accion,
            $carga,
        );
    }

    public function publicar(
        Carga $carga,
        User $usuario,
        int $versionEsperada,
    ): Carga {
        $this->asegurarGestionAutorizada($usuario);

        $accion = function () use ($carga, $usuario, $versionEsperada): Carga {
            $cargaBloqueada = $this->bloquearCarga($carga);
            $this->asegurarBorrador($cargaBloqueada);
            $this->asegurarVersion($cargaBloqueada, $versionEsperada);
            $this->asegurarCamaraObjetivoValida($cargaBloqueada->camara_objetivo_id);

            $asignaciones = CargaFolio::query()
                ->where('carga_id', $cargaBloqueada->id)
                ->whereIn('estado', [
                    EstadoCargaFolio::Pendiente->value,
                    EstadoCargaFolio::ConIncidencia->value,
                    EstadoCargaFolio::EnAnden->value,
                    EstadoCargaFolio::Cargado->value,
                ])
                ->with('reservaActiva')
                ->orderBy('folio_id')
                ->lockForUpdate()
                ->get();

            if ($this->instructivos->instructivos($cargaBloqueada)->isNotEmpty()
                && $asignaciones->contains(fn (CargaFolio $a): bool => $a->instructivo_embarque_id === null)) {
                throw new DomainException('Asigna cada pallet a su instructivo antes de publicar la orden de preparación.');
            }

            if ($asignaciones->count() < 1 || $asignaciones->count() > 26) {
                throw new DomainException(
                    'Una carga debe contener entre 1 y 26 folios antes de publicarse.',
                );
            }

            $folios = Folio::query()
                ->whereIn('id', $asignaciones->pluck('folio_id'))
                ->with('ubicacionActual.posicion.camara')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $errores = [];

            foreach ($folios as $folio) {
                $error = $this->motivoFolioNoAsignable($folio, $cargaBloqueada->temporada_id);

                if ($error) {
                    $errores[] = $this->errorFolio(
                        $folio->numero_folio,
                        $error['codigo'],
                        $error['mensaje'],
                    );
                }

                $asignacion = $asignaciones->firstWhere('folio_id', $folio->id);

                if ($asignacion && ! $asignacion->reservaActiva) {
                    $errores[] = $this->errorFolio(
                        $folio->numero_folio,
                        'reserva_inconsistente',
                        "El folio {$folio->numero_folio} no posee una reserva de carga vigente.",
                    );
                }
            }

            if ($errores !== []) {
                throw new FoliosCargaInvalidos($errores);
            }

            $cargaBloqueada->update([
                'estado' => EstadoCarga::Pendiente,
                'version' => $cargaBloqueada->version + 1,
                'actualizada_por_user_id' => $usuario->id,
                'publicada_por_user_id' => $usuario->id,
                'publicada_at' => now(),
            ]);

            $this->registrarEvento(
                $cargaBloqueada,
                TipoEventoCarga::Publicada,
                $usuario,
                datos: ['cantidad_folios' => $asignaciones->count()],
            );

            $this->servicioTareas->sincronizar($cargaBloqueada);
            $this->registrarEvento(
                $cargaBloqueada,
                TipoEventoCarga::TareasGeneradas,
                $usuario,
                datos: [
                    'camaras_origen' => $cargaBloqueada->tareas()
                        ->where('estado', '!=', 'completada')
                        ->pluck('camara_origen_id')
                        ->values()
                        ->all(),
                ],
            );

            return $cargaBloqueada->refresh();
        };

        return $this->transicionar(
            'publicar',
            $usuario,
            ['carga_id' => $carga->id, 'version_esperada' => $versionEsperada],
            $accion,
            $carga,
        );
    }

    /**
     * Cancelación comercial en cualquier etapa antes del cierre. Los pallets que
     * siguen en cámara quedan libres; los que ya salieron (andén o camión) quedan
     * pendientes de reingreso y no vuelven a ofrecerse hasta ubicarse.
     */
    public function cancelar(
        Carga $carga,
        User $usuario,
        int $versionEsperada,
        ?string $motivo = null,
    ): Carga {
        $this->asegurarGestionAutorizada($usuario);

        $accion = function () use (
            $carga,
            $usuario,
            $versionEsperada,
            $motivo,
        ): Carga {
            $cargaBloqueada = $this->bloquearCarga($carga);
            $this->asegurarVersion($cargaBloqueada, $versionEsperada);

            if (in_array($cargaBloqueada->estado, EstadoCarga::terminales(), true)) {
                throw new DomainException(match ($cargaBloqueada->estado) {
                    EstadoCarga::Cerrada => 'La carga ya está cerrada: si el camión volvió a planta, registra el retorno.',
                    EstadoCarga::Cancelada => 'La carga ya está cancelada.',
                    default => 'La carga ya retornó a planta y no puede cancelarse.',
                });
            }
            $motivoNormalizado = $this->textoOpcional($motivo);
            if ($cargaBloqueada->estado !== EstadoCarga::Borrador && $motivoNormalizado === null) {
                throw new DomainException('Indica el motivo comercial de la cancelación.');
            }

            $asignaciones = CargaFolio::query()
                ->where('carga_id', $cargaBloqueada->id)
                ->whereHas('reservaActiva')
                ->with('folio:id,numero_folio,temporada_id')
                ->orderBy('folio_id')
                ->lockForUpdate()
                ->get();
            $estadoPrevio = $cargaBloqueada->estado;

            $cargaBloqueada->update([
                'estado' => EstadoCarga::Cancelada,
                'version' => $cargaBloqueada->version + 1,
                'actualizada_por_user_id' => $usuario->id,
                'cancelada_por_user_id' => $usuario->id,
                'cancelada_at' => now(),
                'motivo_cancelacion' => $motivoNormalizado,
            ]);
            app(ServicioPresenciaCargaAnden::class)->finalizarPorTermino(
                $cargaBloqueada,
                $usuario,
                (string) Str::uuid(),
                'Carga cancelada: '.($motivoNormalizado ?? 'sin motivo'),
            );

            $liberados = [];
            $pendientesReingreso = [];
            foreach ($asignaciones as $asignacion) {
                $this->registrarEvento(
                    $cargaBloqueada,
                    TipoEventoCarga::FolioDesasignado,
                    $usuario,
                    $asignacion->folio,
                    [
                        'motivo' => $motivoNormalizado,
                        'causa' => 'cancelacion_carga',
                        'estado_carga_previo' => $asignacion->estado->value,
                    ],
                );
                $reingreso = $this->liberarAsignacion(
                    $cargaBloqueada,
                    $asignacion,
                    EstadoCargaFolio::Descartado,
                    OrigenReingresoCamara::CancelacionCarga,
                    $motivoNormalizado,
                    $usuario,
                );
                if ($reingreso) {
                    $pendientesReingreso[] = $asignacion->folio->numero_folio;
                } else {
                    $liberados[] = $asignacion->folio->numero_folio;
                }
            }

            $this->registrarEvento(
                $cargaBloqueada,
                TipoEventoCarga::Cancelada,
                $usuario,
                datos: [
                    'motivo' => $motivoNormalizado,
                    'estado_previo' => $estadoPrevio->value,
                    'folios_liberados' => $liberados,
                    'pendientes_reingreso' => $pendientesReingreso,
                ],
            );

            $this->servicioTareas->sincronizar($cargaBloqueada);

            return $cargaBloqueada->refresh();
        };

        return $this->transicionar(
            'cancelar',
            $usuario,
            [
                'carga_id' => $carga->id,
                'version_esperada' => $versionEsperada,
                'motivo' => $motivo,
            ],
            $accion,
            $carga,
        );
    }

    /**
     * El camión volvió a planta después del cierre: total (todos los pallets
     * despachados) o parcial (los indicados). Los folios vuelven a la temporada
     * con su mismo número, sin ubicación y pendientes de reingreso a cámara.
     *
     * @param  array<int, string>|null  $folioIds  null = todos los despachados
     */
    public function registrarRetorno(
        Carga $carga,
        ?array $folioIds,
        User $usuario,
        int $versionEsperada,
        string $motivo,
        ?DateTimeInterface $llegadaAt = null,
    ): Carga {
        $this->asegurarGestionAutorizada($usuario);
        $llegada = $llegadaAt ? CarbonImmutable::instance($llegadaAt) : CarbonImmutable::now();
        if ($llegada->isFuture()) {
            throw new DomainException('La hora de llegada a planta no puede estar en el futuro.');
        }
        $seleccion = $folioIds === null ? null : array_values(array_unique($folioIds));
        if ($seleccion !== null && $seleccion === []) {
            throw new DomainException('Indica al menos un pallet que volvió a planta.');
        }

        $accion = function () use ($carga, $seleccion, $usuario, $versionEsperada, $motivo, $llegada): Carga {
            $cargaBloqueada = $this->bloquearCarga($carga);
            $this->asegurarVersion($cargaBloqueada, $versionEsperada);
            if (! $cargaBloqueada->estado->admiteRetorno()) {
                throw new DomainException('Solo una carga cerrada (o con retorno parcial) puede registrar un retorno a planta.');
            }
            $motivoNormalizado = $this->textoOpcional($motivo);
            if ($motivoNormalizado === null || mb_strlen($motivoNormalizado) < 3) {
                throw new DomainException('Indica el motivo del retorno a planta.');
            }
            if ($cargaBloqueada->cerrada_at && $llegada->lt($cargaBloqueada->cerrada_at)) {
                throw new DomainException('La llegada a planta no puede ser anterior a la salida del camión.');
            }

            $despachadas = CargaFolio::query()
                ->where('carga_id', $cargaBloqueada->id)
                ->where('estado', EstadoCargaFolio::Despachado->value)
                ->orderBy('folio_id')
                ->lockForUpdate()
                ->get();
            $retornan = $seleccion === null
                ? $despachadas
                : $despachadas->whereIn('folio_id', $seleccion)->values();
            if ($seleccion !== null && $retornan->count() !== count($seleccion)) {
                throw new DomainException('Uno o más pallets indicados no salieron despachados en esta carga o ya retornaron.');
            }
            if ($retornan->isEmpty()) {
                throw new DomainException('La carga no tiene pallets despachados por retornar.');
            }

            $folios = Folio::query()
                ->whereIn('id', $retornan->pluck('folio_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            foreach ($retornan as $asignacion) {
                $folio = $folios->get($asignacion->folio_id);
                if (! $folio || $folio->temporada_id !== $cargaBloqueada->temporada_id) {
                    throw new DomainException('El pallet no pertenece a la temporada de la carga.');
                }
                if ($folio->activo || $folio->estado_operacional !== EstadoOperacionalFolio::Despachado) {
                    throw new DomainException("El folio {$folio->numero_folio} ya no figura como despachado.");
                }
                $folio->update([
                    'activo' => true,
                    'estado_operacional' => EstadoOperacionalFolio::Disponible,
                ]);
                $asignacion->update([
                    'estado' => EstadoCargaFolio::Retornado,
                    'retornado_por_user_id' => $usuario->id,
                    'retornado_at' => $llegada,
                ]);
                app(ServicioReingresoCamara::class)->registrar(
                    $folio,
                    $cargaBloqueada,
                    $asignacion->setRelation('folio', $folio),
                    OrigenReingresoCamara::RetornoCarga,
                    $motivoNormalizado,
                    $usuario,
                    EstadoCargaFolio::Despachado,
                );
                $this->registrarEvento($cargaBloqueada, TipoEventoCarga::FolioDesasignado, $usuario, $folio, [
                    'causa' => 'retorno_carga',
                    'motivo' => $motivoNormalizado,
                    'llegada_at' => $llegada->toAtomString(),
                ]);
            }

            $parcial = $retornan->count() < $despachadas->count();
            $cargaBloqueada->update([
                'estado' => $parcial ? EstadoCarga::RetornoParcial : EstadoCarga::Retornada,
                'motivo_retorno' => $cargaBloqueada->motivo_retorno
                    ? $cargaBloqueada->motivo_retorno.' · '.$motivoNormalizado
                    : $motivoNormalizado,
                'retornada_por_user_id' => $usuario->id,
                'retornada_at' => $llegada,
                'version' => $cargaBloqueada->version + 1,
                'actualizada_por_user_id' => $usuario->id,
            ]);
            $this->registrarEvento($cargaBloqueada, TipoEventoCarga::Retornada, $usuario, datos: [
                'motivo' => $motivoNormalizado,
                'parcial' => $parcial,
                'llegada_at' => $llegada->toAtomString(),
                'folios' => $retornan->map(fn (CargaFolio $asignacion): string => $folios->get($asignacion->folio_id)->numero_folio)->values()->all(),
                'siguen_despachados' => $despachadas->count() - $retornan->count(),
            ]);
            // El saldo SAG vuelve a contar los pallets que regresaron a planta.
            app(ServicioExistenciasSag::class)->sincronizar($retornan->pluck('folio_id')->all(), 'retorno_despacho', $cargaBloqueada->codigo);

            return $cargaBloqueada->refresh();
        };

        return $this->transicionar(
            'registrar_retorno',
            $usuario,
            [
                'carga_id' => $carga->id,
                'folios' => $seleccion,
                'version_esperada' => $versionEsperada,
                'motivo' => $motivo,
                'llegada_at' => $llegada->toAtomString(),
            ],
            $accion,
            $carga,
            ocurridoAt: $llegada,
        );
    }

    /**
     * Saca un pallet de la carga. Si ya salió de la cámara, queda pendiente de
     * reingreso; las incidencias abiertas se cierran como «retirado de carga».
     */
    private function liberarAsignacion(
        Carga $carga,
        CargaFolio $asignacion,
        EstadoCargaFolio $estadoFinal,
        OrigenReingresoCamara $origen,
        ?string $motivo,
        User $usuario,
    ): ?ReingresoFolioCamara {
        $estadoPrevio = $asignacion->estado;
        $asignacion->reservaActiva()->lockForUpdate()->first()?->delete();
        IncidenciaCargaFolio::query()
            ->where('carga_folio_id', $asignacion->id)
            ->where('estado', EstadoIncidenciaCarga::Abierta->value)
            ->lockForUpdate()
            ->get()
            ->each(fn (IncidenciaCargaFolio $incidencia) => $incidencia->update([
                'estado' => EstadoIncidenciaCarga::Resuelta,
                'tipo_resolucion' => TipoResolucionIncidenciaCarga::RetiradoDeCarga,
                'observacion_resolucion' => $motivo ?? $origen->etiqueta(),
                'resuelta_por_user_id' => $usuario->id,
                'resuelta_at' => now(),
            ]));
        $asignacion->update([
            'estado' => $estadoFinal,
            'finalizado_por_user_id' => $usuario->id,
            'finalizado_at' => now(),
            'motivo_finalizacion' => $motivo,
        ]);

        if (! $estadoPrevio->fueraDeCamara()) {
            return null;
        }

        return app(ServicioReingresoCamara::class)->registrar(
            $asignacion->folio,
            $carga,
            $asignacion,
            $origen,
            $motivo ?? $origen->etiqueta(),
            $usuario,
            $estadoPrevio,
        );
    }

    /** Recalcula el estado de salida según dónde quedaron los pallets reservados. */
    private function recalcularEstadoDespacho(Carga $carga): void
    {
        $estados = CargaFolio::query()
            ->where('carga_id', $carga->id)
            ->whereHas('reservaActiva')
            ->lockForUpdate()
            ->pluck('estado');
        $fuera = $estados->filter(fn (EstadoCargaFolio $estado): bool => $estado->fueraDeCamara())->count();
        $nuevo = match (true) {
            $estados->isNotEmpty() && $fuera === $estados->count() => EstadoCarga::Despachada,
            $fuera > 0 => EstadoCarga::DespachoParcial,
            in_array($carga->estado, [EstadoCarga::Despachada, EstadoCarga::DespachoParcial], true) => EstadoCarga::EnPreparacion,
            default => $carga->estado,
        };
        if ($nuevo !== $carga->estado) {
            $carga->update(['estado' => $nuevo]);
        }
    }

    private function sincronizarOperacion(Carga $carga, User $usuario): void
    {
        if ($carga->estado === EstadoCarga::Borrador) {
            return;
        }

        $this->servicioTareas->sincronizar($carga);
        $presencia = $carga->presenciaAndenActiva()->first();
        if ($presencia) {
            app(ServicioPlanDespachoDirecto::class)->sincronizar($presencia, $usuario);
        }
    }

    /** @return array<int, array{codigo: string, titulo: string, mensaje: string}> */
    private function diferenciasComerciales(Folio $original, Folio $reemplazo): array
    {
        $campos = [
            'cliente' => ['Cliente', fn (Folio $folio) => $folio->exportadora],
            'especie' => ['Especie', fn (Folio $folio) => data_get($folio->datos_externos, 'especie')],
            'variedad' => ['Variedad', fn (Folio $folio) => $folio->variedad],
            'calibre' => ['Calibre', fn (Folio $folio) => $folio->calibre],
            'embalaje' => ['Embalaje', fn (Folio $folio) => data_get($folio->datos_externos, 'envase')],
        ];
        $diferencias = [];
        foreach ($campos as $codigo => [$titulo, $valor]) {
            $antes = $this->textoOpcional($valor($original));
            $despues = $this->textoOpcional($valor($reemplazo));
            if (mb_strtoupper((string) $antes) !== mb_strtoupper((string) $despues)) {
                $diferencias[] = [
                    'codigo' => "diferencia_{$codigo}",
                    'titulo' => "{$titulo} distinto",
                    'mensaje' => sprintf('%s: %s sale con «%s» y %s entra con «%s».', $titulo, $original->numero_folio, $antes ?? 'sin dato', $reemplazo->numero_folio, $despues ?? 'sin dato'),
                ];
            }
        }

        return $diferencias;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function transicionar(
        string $tipo,
        User $usuario,
        array $payload,
        Closure $accion,
        ?Carga $carga = null,
        ?string $referencia = null,
        ?string $operacionId = null,
        ?DateTimeInterface $ocurridoAt = null,
    ): mixed {
        return $this->motorTransiciones->ejecutar(
            new ComandoTransicionOperacional(
                dominio: DominioTransicionOperacional::Cargas,
                tipo: $tipo,
                usuario: $usuario,
                payload: $payload,
                sujetoTipo: Carga::class,
                sujetoId: $carga ? (string) $carga->id : null,
                referencia: $carga?->codigo ?? $referencia,
                operacionId: $operacionId,
                ocurridoAt: $ocurridoAt,
            ),
            $accion,
        );
    }

    private function asegurarGestionAutorizada(User $usuario): void
    {
        if (! $this->alcance->puedeGestionarCargas($usuario)) {
            throw new OperacionNoAutorizada(
                'El usuario no está autorizado para gestionar cargas de productos.',
            );
        }
    }

    private function bloquearCarga(Carga $carga): Carga
    {
        return Carga::query()
            ->lockForUpdate()
            ->findOrFail($carga->id);
    }

    private function asegurarBorrador(Carga $carga): void
    {
        if ($carga->estado !== EstadoCarga::Borrador) {
            throw new DomainException('Solo una carga en borrador puede publicarse.');
        }
    }

    private function asegurarEditable(Carga $carga): void
    {
        if (! in_array($carga->estado, [
            EstadoCarga::Borrador,
            EstadoCarga::Pendiente,
        ], true)) {
            throw new DomainException(
                'La carga ya inició su separación y no admite cambios.',
            );
        }
    }

    private function asegurarAjustable(Carga $carga): void
    {
        if (in_array($carga->estado, EstadoCarga::terminales(), true)) {
            throw new DomainException('La carga ya está cerrada, cancelada o retornada y no admite cambios de pallets.');
        }
    }

    /** Publicada y con la separación iniciada: los cambios exigen motivo. */
    private function enCurso(Carga $carga): bool
    {
        return ! in_array($carga->estado, [EstadoCarga::Borrador, EstadoCarga::Pendiente], true);
    }

    private function asegurarVersion(Carga $carga, int $versionEsperada): void
    {
        if ($carga->version !== $versionEsperada) {
            throw new ConflictoOperacion(sprintf(
                'La carga %s fue modificada por otro usuario. Se esperaba la versión %d y la versión actual es %d.',
                $carga->codigo,
                $versionEsperada,
                $carga->version,
            ));
        }
    }

    private function incrementarVersion(Carga $carga, User $usuario): void
    {
        $carga->update([
            'version' => $carga->version + 1,
            'actualizada_por_user_id' => $usuario->id,
        ]);
    }

    /**
     * @return array{codigo: string, mensaje: string}|null
     */
    private function motivoFolioNoAsignable(Folio $folio, ?string $temporadaId = null): ?array
    {
        if ($temporadaId !== null && $folio->temporada_id !== $temporadaId) {
            return [
                'codigo' => 'temporada_no_coincide',
                'mensaje' => "El folio {$folio->numero_folio} pertenece a otra temporada operacional.",
            ];
        }

        if (! in_array($folio->tipo_bulto, [TipoBulto::Pallet, TipoBulto::Saldo], true)) {
            return [
                'codigo' => 'tipo_bulto_no_permitido',
                'mensaje' => "El folio {$folio->numero_folio} corresponde a materiales y no puede incorporarse a una carga CAR-*.",
            ];
        }

        if (! $folio->activo) {
            return [
                'codigo' => 'inactivo',
                'mensaje' => "El folio {$folio->numero_folio} está inactivo.",
            ];
        }

        if (! in_array($folio->estado_operacional, [
            EstadoOperacionalFolio::Disponible,
            EstadoOperacionalFolio::PendientePrefrio,
            EstadoOperacionalFolio::PendienteUbicacion,
        ], true)) {
            return [
                'codigo' => 'estado_no_disponible',
                'mensaje' => sprintf(
                    'El folio %s no está disponible; su estado es %s.',
                    $folio->numero_folio,
                    $folio->estado_operacional->value,
                ),
            ];
        }

        if ($folio->condicion_termica === CondicionTermicaFolio::Retenido
            || $folio->habilitacion_almacenamiento === HabilitacionAlmacenamientoFolio::Retenido
            || $folio->retencionOperacionalActiva()->exists()) {
            return [
                'codigo' => 'retenido',
                'mensaje' => "El folio {$folio->numero_folio} está retenido.",
            ];
        }

        // Esta es una reserva comercial, no un movimiento físico. La ubicación
        // (incluido un reingreso pendiente) la resuelve cámaras; no se inventa
        // una posición ni se habilita la extracción al reservar o publicar.

        return null;
    }

    /**
     * @return array{codigo: string, mensaje: string}|null
     */
    private function motivoFolioNoAsignableSalidaDirecta(
        Folio $folio,
        string $temporadaId,
        CarbonImmutable $salidaAt,
    ): ?array {
        if ($folio->temporada_id !== $temporadaId) {
            return [
                'codigo' => 'temporada_no_coincide',
                'mensaje' => "El folio {$folio->numero_folio} pertenece a otra temporada operacional.",
            ];
        }

        if (! in_array($folio->tipo_bulto, [TipoBulto::Pallet, TipoBulto::Saldo], true)) {
            return [
                'codigo' => 'tipo_bulto_no_permitido',
                'mensaje' => "El folio {$folio->numero_folio} no corresponde a un pallet o saldo de producto.",
            ];
        }

        if (! $folio->activo) {
            return [
                'codigo' => 'inactivo',
                'mensaje' => "El folio {$folio->numero_folio} se encuentra inactivo.",
            ];
        }

        if (! in_array($folio->estado_operacional, [
            EstadoOperacionalFolio::PendientePrefrio,
            EstadoOperacionalFolio::PendienteUbicacion,
            EstadoOperacionalFolio::Disponible,
        ], true)) {
            return [
                'codigo' => 'estado_no_permitido',
                'mensaje' => "El folio {$folio->numero_folio} no está pendiente de ubicación después de Prefrío.",
            ];
        }

        if ($folio->condicion_termica !== CondicionTermicaFolio::PrefrioAprobado
            || $folio->habilitacion_almacenamiento !== HabilitacionAlmacenamientoFolio::Habilitado) {
            return [
                'codigo' => 'prefrio_no_aprobado',
                'mensaje' => "El folio {$folio->numero_folio} no posee un Prefrío aprobado y habilitado.",
            ];
        }

        if ($folio->ubicacionActual) {
            return [
                'codigo' => 'folio_ubicado',
                'mensaje' => "El folio {$folio->numero_folio} ya posee una ubicación; debe seguir el flujo normal desde cámara.",
            ];
        }

        $finalizadoAt = $folio->procesosPrefrio
            ->map(fn ($asignacion) => $asignacion->proceso?->finalizado_at)
            ->filter()
            ->sortDesc()
            ->first();

        if (! $finalizadoAt) {
            return [
                'codigo' => 'sin_prefrio_trazable',
                'mensaje' => "El folio {$folio->numero_folio} no posee un proceso de Prefrío aprobado y finalizado.",
            ];
        }

        if ($salidaAt->lt($finalizadoAt)) {
            return [
                'codigo' => 'salida_antes_prefrio',
                'mensaje' => sprintf(
                    'La salida del folio %s no puede ser anterior al término de su Prefrío (%s).',
                    $folio->numero_folio,
                    $finalizadoAt->format('d-m-Y H:i'),
                ),
            ];
        }

        return null;
    }

    private function asegurarCamaraObjetivoValida(?string $camaraId): void
    {
        if ($camaraId === null) {
            return;
        }

        $camara = Camara::query()
            ->whereKey($camaraId)
            ->where('estado', EstadoCamara::Activa->value)
            ->where('contenido', ContenidoCamara::Productos->value)
            ->lockForUpdate()
            ->first(['id']);

        if (! $camara) {
            throw new DomainException(
                'La cámara objetivo debe estar activa y clasificada como cámara de productos.',
            );
        }
    }

    private function asegurarAndenValido(?string $andenId): void
    {
        if ($andenId === null) {
            return;
        }

        $existe = Anden::query()
            ->whereKey($andenId)
            ->where('activo', true)
            ->lockForUpdate()
            ->exists();

        if (! $existe) {
            throw new DomainException('El andén previsto debe existir y estar activo.');
        }
    }

    private function mensajeFolioReservado(string $numero, ReservaCargaFolio $reserva): string
    {
        // La reserva bloqueada es una lectura actual. Sus relaciones informativas
        // pueden no existir aún en el snapshot REPEATABLE READ de esta transacción.
        $codigoCarga = $reserva->asignacion?->carga?->codigo;

        return $codigoCarga
            ? "El folio {$numero} ya está asignado a la carga {$codigoCarga}."
            : "El folio {$numero} ya está asignado a otra carga.";
    }

    /**
     * @return array{folio: string, codigo: string, mensaje: string}
     */
    private function errorFolio(string $folio, string $codigo, string $mensaje): array
    {
        return compact('folio', 'codigo', 'mensaje');
    }

    /**
     * @param  array<int, string>  $numerosFolio
     * @return array<int, string>
     */
    private function normalizarNumerosFolio(array $numerosFolio): array
    {
        $numeros = collect($numerosFolio)
            ->map(fn (string $numero): string => trim($numero))
            ->filter()
            ->unique()
            ->values();

        if ($numeros->isEmpty()) {
            throw new DomainException('Debe indicar al menos un folio.');
        }

        if ($numeros->count() > 26) {
            throw new DomainException(
                'Una carga no puede recibir más de 26 folios por operación.',
            );
        }

        return $numeros->all();
    }

    private function siguienteCodigoBloqueado(): string
    {
        $ultimoNumero = DB::table('secuencias_documentos')
            ->where('clave', 'cargas')
            ->lockForUpdate()
            ->value('ultimo_numero');

        if ($ultimoNumero === null) {
            throw new LogicException('No existe la secuencia configurada para las cargas.');
        }

        $siguienteNumero = ((int) $ultimoNumero) + 1;
        DB::table('secuencias_documentos')
            ->where('clave', 'cargas')
            ->update(['ultimo_numero' => $siguienteNumero]);

        return sprintf('CAR-%06d', $siguienteNumero);
    }

    /**
     * @param  array<string, mixed>|null  $datos
     */
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

    private function textoOpcional(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}

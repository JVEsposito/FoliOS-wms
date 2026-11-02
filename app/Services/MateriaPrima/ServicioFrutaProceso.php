<?php

namespace App\Services\MateriaPrima;

use App\Enums\EstadoLoteMateriaPrima;
use App\Enums\TipoEnvaseRomana;
use App\Exceptions\ConflictoOperacion;
use App\Models\AsignacionCamaraLoteMateriaPrima;
use App\Models\EntregaFrutaProceso;
use App\Models\EventoLoteMateriaPrima;
use App\Models\LoteMateriaPrima;
use App\Models\LoteVersionOrdenMp;
use App\Models\OrdenProcesoMp;
use App\Models\PersonalAccessToken;
use App\Models\ReservaLoteOrdenProcesoMp;
use App\Models\User;
use App\Models\VersionOrdenProcesoMp;
use App\Services\Autenticacion\ServicioPinOperacional;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\MateriaPrima\OrdenesProceso\ServicioOrdenProcesoMp;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * Entregas de bins desde cámara MP hacia Packing.
 *
 * Con orden de proceso el bloqueo sigue el mismo orden que las órdenes: orden, lotes de la
 * orden por id (incluido el lote entregado) y después reservas. La temporada se lee con
 * bloqueo compartido para que la primera lectura simple, que abre el snapshot, ocurra con el
 * lote ya bloqueado y vea todas sus entregas confirmadas.
 */
class ServicioFrutaProceso
{
    public function __construct(
        private readonly AlcanceOperacionalUsuario $alcance,
        private readonly ServicioOrdenProcesoMp $ordenes,
        private readonly ServicioPinOperacional $pines,
    ) {}

    /** @param array<string, mixed> $datos */
    public function registrar(
        LoteMateriaPrima $lote,
        array $datos,
        User $usuario,
    ): LoteMateriaPrima {
        $ordenId = $datos['orden_proceso_mp_id'] ?? null;
        if (! $ordenId && config('materia_prima.fruta_proceso_exige_orden')) {
            throw ValidationException::withMessages([
                'orden_proceso_mp_id' => 'Elige la orden de proceso de la línea antes de registrar el viaje.',
            ]);
        }
        // El PIN se verifica fuera de la transacción: los intentos fallidos deben quedar
        // guardados aunque la entrega se rechace.
        $autorizador = $ordenId && filled($datos['autorizacion']['user_id'] ?? null)
            ? $this->verificarAutorizador($datos['autorizacion'])
            : null;
        $payload = $this->payload($datos);
        $hash = $this->hash($payload);

        try {
            return DB::transaction(function () use ($lote, $datos, $usuario, $hash, $ordenId, $autorizador): LoteMateriaPrima {
                $existente = EntregaFrutaProceso::query()
                    ->where('operacion_id', $datos['operacion_id'])
                    ->lockForUpdate()
                    ->first();
                if ($existente) {
                    return $this->resolverEntregaRepetida($existente, $lote, $hash);
                }
                if (EventoLoteMateriaPrima::query()
                    ->where('operacion_id', $datos['operacion_id'])
                    ->lockForUpdate()
                    ->exists()) {
                    throw new ConflictoOperacion(
                        'El identificador de entrega ya fue utilizado con otra operación.',
                    );
                }

                $orden = null;
                if ($ordenId) {
                    $orden = OrdenProcesoMp::query()->lockForUpdate()->find($ordenId);
                    if (! $orden) {
                        throw ValidationException::withMessages(['orden_proceso_mp_id' => 'La orden de proceso no existe.']);
                    }
                    if (! $orden->estado->abierta()) {
                        throw new ConflictoOperacion("{$orden->codigo} ya terminó. Elige otra orden de la línea.");
                    }
                    $this->ordenes->bloquearLotes(collect($orden->lote_ids_vigentes ?? [])->push($lote->id));
                }
                $temporadaId = app(ServicioTemporadaActiva::class)->buscarConBloqueoCompartido()?->id;
                $lote = LoteMateriaPrima::query()
                    ->where('temporada_id', $temporadaId)
                    ->lockForUpdate()
                    ->findOrFail($lote->id);
                if ($orden && $orden->temporada_id !== $lote->temporada_id) {
                    throw new ConflictoOperacion('La orden pertenece a otra temporada.');
                }
                if (! in_array($lote->estado, [
                    EstadoLoteMateriaPrima::DisponibleProceso,
                    EstadoLoteMateriaPrima::AsignadoCamara,
                    EstadoLoteMateriaPrima::EntregaParcialProceso,
                ], true)) {
                    throw new ConflictoOperacion(
                        'El lote debe estar disponible desde Hidrocooler o cámara y mantener bins disponibles.',
                    );
                }
                if ($lote->envase_primario !== TipoEnvaseRomana::Bins) {
                    throw ValidationException::withMessages([
                        'lote' => 'Fruta a proceso solo admite lotes cuyo envase primario sea bins.',
                    ]);
                }

                $asignacion = AsignacionCamaraLoteMateriaPrima::query()
                    ->where('lote_materia_prima_id', $lote->id)
                    ->lockForUpdate()
                    ->first();
                if (array_key_exists('camara_origen_id', $datos) && ($datos['camara_origen_id'] ?? null) !== $asignacion?->camara_id) {
                    throw new ConflictoOperacion('La cámara del lote cambió. Actualiza antes de registrar el viaje.');
                }
                $esSalidaDirecta = ! $asignacion
                    && $lote->hidrocooler()
                        ->where('destino_salida', 'proceso')
                        ->exists();
                if (! $asignacion && ! $esSalidaDirecta) {
                    throw new ConflictoOperacion(
                        'El lote no posee una asignación vigente a cámara ni una salida directa aprobada.',
                    );
                }

                $entregados = $this->cantidadEntregada($lote);
                $total = $lote->cantidad_envases_primarios;
                $disponibles = max(0, $total - $entregados);
                $cantidad = (int) $datos['cantidad_envases'];
                if ($cantidad > $disponibles) {
                    throw ValidationException::withMessages([
                        'cantidad_envases' => "Solo quedan {$disponibles} bins disponibles en el lote.",
                    ]);
                }

                if (! $orden) {
                    // También las APK anteriores respetan las reservas. Con el lote bloqueado,
                    // una publicación no puede comprometer estos bins mientras sale el viaje.
                    $comprometidos = (int) ReservaLoteOrdenProcesoMp::query()
                        ->where('lote_materia_prima_id', $lote->id)
                        ->lockForUpdate()
                        ->get()
                        ->sum('bins_pendientes');
                    $libres = max(0, $disponibles - $comprometidos);
                    if ($cantidad > $libres) {
                        throw ValidationException::withMessages([
                            'cantidad_envases' => "Solo quedan {$libres} bins libres de reservas. Elige la orden de proceso para entregar los bins programados.",
                        ]);
                    }
                }

                $vinculo = $orden
                    ? $this->vincularOrden($orden, $lote, $cantidad, $datos, $autorizador)
                    : null;
                if ($orden) {
                    $this->ordenes->aplicarEntrega($orden, $lote, $cantidad, $usuario);
                }

                $saldoPosterior = $disponibles - $cantidad;
                $token = $usuario->currentAccessToken();
                $entrega = EntregaFrutaProceso::create([
                    'operacion_id' => $datos['operacion_id'],
                    'payload_hash' => $hash,
                    'lote_materia_prima_id' => $lote->id,
                    'orden_proceso_mp_id' => $orden?->id,
                    'version_orden_proceso_mp_id' => $vinculo['version_id'] ?? null,
                    'fuera_de_orden' => $vinculo['fuera_de_orden'] ?? false,
                    'autorizado_por_user_id' => ($vinculo['fuera_de_orden'] ?? false) ? $autorizador?->id : null,
                    'asignacion_camara_lote_id' => $asignacion?->id,
                    'camara_id' => $asignacion?->camara_id,
                    'cantidad_envases' => $cantidad,
                    'kilos_enviados' => filled($datos['kilos_enviados'] ?? null)
                        ? round((float) $datos['kilos_enviados'], 3)
                        : null,
                    'saldo_anterior' => $disponibles,
                    'saldo_posterior' => $saldoPosterior,
                    // Con orden, línea y número salen de ella: así Finanzas y los retornos por bin
                    // siguen leyendo los mismos campos.
                    'linea_proceso' => $orden ? $orden->linea->nombre : trim((string) $datos['linea_proceso']),
                    'turno' => strtoupper(trim((string) $datos['turno'])),
                    'numero_orden' => $orden ? ($vinculo['numero_proceso'] ?? $orden->codigo) : trim((string) $datos['numero_orden']),
                    'observacion' => filled($datos['observacion'] ?? null)
                        ? trim((string) $datos['observacion'])
                        : null,
                    'entregado_por_user_id' => $usuario->id,
                    'dispositivo_id' => $token instanceof PersonalAccessToken
                        ? $token->dispositivo_id
                        : null,
                    'entregado_at' => now(),
                ]);

                $estadoAnterior = $lote->estado;
                $estadoNuevo = $saldoPosterior === 0
                    ? EstadoLoteMateriaPrima::EntregadoProceso
                    : EstadoLoteMateriaPrima::EntregaParcialProceso;
                $lote->update([
                    'estado' => $estadoNuevo,
                    'version' => $lote->version + 1,
                    'actualizado_por_user_id' => $usuario->id,
                ]);
                $this->registrarEvento(
                    $lote,
                    'fruta_entregada_proceso',
                    $usuario,
                    $datos['operacion_id'],
                    $estadoAnterior,
                    $estadoNuevo,
                    [
                        'entrega_id' => $entrega->id,
                        'cantidad_envases' => $cantidad,
                        'kilos_enviados' => $entrega->kilos_enviados,
                        'saldo_anterior' => $disponibles,
                        'saldo_posterior' => $saldoPosterior,
                        'linea_proceso' => $entrega->linea_proceso,
                        'turno' => $entrega->turno,
                        'numero_orden' => $entrega->numero_orden,
                        'orden_proceso_mp_id' => $orden?->id,
                        'orden_proceso_codigo' => $orden?->codigo,
                        'fuera_de_orden' => $entrega->fuera_de_orden,
                        'autorizado_por_user_id' => $entrega->autorizado_por_user_id,
                        'camara_id' => $asignacion?->camara_id,
                        'camara_origen_id' => $asignacion?->camara_id,
                        'camara_origen_codigo' => $asignacion?->camara?->codigo,
                        'camara_origen_nombre' => $asignacion?->camara?->nombre,
                        'tipo_envase' => $lote->envase_primario->value,
                        'usuario_nombre' => $usuario->name,
                        'observacion' => $entrega->observacion,
                        'origen_operacional' => $esSalidaDirecta
                            ? 'hidrocooler_directo'
                            : 'camara_materia_prima',
                    ],
                );

                return $this->cargarLote($lote);
            }, attempts: 3);
        } catch (QueryException $excepcion) {
            $existente = EntregaFrutaProceso::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->first();
            if (! $existente) {
                throw $excepcion;
            }

            return $this->resolverEntregaRepetida($existente, $lote, $hash);
        }
    }

    public function anular(
        EntregaFrutaProceso $entrega,
        string $operacionId,
        string $motivo,
        User $usuario,
    ): LoteMateriaPrima {
        try {
            return DB::transaction(function () use (
                $entrega,
                $operacionId,
                $motivo,
                $usuario,
            ): LoteMateriaPrima {
                // La orden de una entrega no cambia: se bloquea primero, como en toda operación
                // sobre órdenes, y después la entrega y el lote como hasta ahora.
                $orden = $entrega->orden_proceso_mp_id
                    ? OrdenProcesoMp::query()->lockForUpdate()->find($entrega->orden_proceso_mp_id)
                    : null;
                $entrega = EntregaFrutaProceso::query()
                    ->lockForUpdate()
                    ->findOrFail($entrega->id);
                $temporadaId = app(ServicioTemporadaActiva::class)->buscarConBloqueoCompartido()?->id;
                $lote = LoteMateriaPrima::query()
                    ->where('temporada_id', $temporadaId)
                    ->lockForUpdate()
                    ->findOrFail($entrega->lote_materia_prima_id);

                if ($entrega->anulado_at !== null) {
                    if ($entrega->operacion_anulacion_id !== $operacionId) {
                        throw new ConflictoOperacion('La entrega ya fue anulada con otra operación.');
                    }

                    return $this->cargarLote($lote);
                }
                $operacionUsada = EntregaFrutaProceso::query()
                    ->where('operacion_anulacion_id', $operacionId)
                    ->where('id', '!=', $entrega->id)
                    ->exists();
                if ($operacionUsada) {
                    throw new ConflictoOperacion(
                        'El identificador de anulación ya fue utilizado en otra entrega.',
                    );
                }
                if (EventoLoteMateriaPrima::query()
                    ->where('operacion_id', $operacionId)
                    ->lockForUpdate()
                    ->exists()) {
                    throw new ConflictoOperacion(
                        'El identificador de anulación ya fue utilizado con otra operación.',
                    );
                }

                $this->asegurarPuedeAnular($entrega, $lote, $usuario);
                $estadoAnterior = $lote->estado;
                $entrega->update([
                    'operacion_anulacion_id' => $operacionId,
                    'anulado_por_user_id' => $usuario->id,
                    'anulado_at' => now(),
                    'motivo_anulacion' => trim($motivo),
                ]);
                if ($orden) {
                    $this->ordenes->revertirEntrega($orden, $lote, $entrega->cantidad_envases, $usuario);
                }

                $entregados = $this->cantidadEntregada($lote);
                $tieneCamara = AsignacionCamaraLoteMateriaPrima::query()
                    ->where('lote_materia_prima_id', $lote->id)
                    ->exists();
                $estadoNuevo = $entregados === 0
                    ? ($tieneCamara
                        ? EstadoLoteMateriaPrima::AsignadoCamara
                        : EstadoLoteMateriaPrima::DisponibleProceso)
                    : EstadoLoteMateriaPrima::EntregaParcialProceso;
                $lote->update([
                    'estado' => $estadoNuevo,
                    'version' => $lote->version + 1,
                    'actualizado_por_user_id' => $usuario->id,
                ]);
                $this->registrarEvento(
                    $lote,
                    'entrega_fruta_proceso_anulada',
                    $usuario,
                    $operacionId,
                    $estadoAnterior,
                    $estadoNuevo,
                    [
                        'entrega_id' => $entrega->id,
                        'cantidad_envases' => $entrega->cantidad_envases,
                        'motivo' => trim($motivo),
                        'cantidad_entregada_vigente' => $entregados,
                        'saldo_vigente' => $lote->cantidad_envases_primarios - $entregados,
                        'camara_destino_id' => $lote->asignacionCamara?->camara_id,
                        'camara_destino_codigo' => $lote->asignacionCamara?->camara?->codigo,
                        'camara_destino_nombre' => $lote->asignacionCamara?->camara?->nombre,
                        'linea_proceso' => $entrega->linea_proceso,
                        'tipo_envase' => $lote->envase_primario->value,
                        'usuario_nombre' => $usuario->name,
                    ],
                );

                return $this->cargarLote($lote);
            }, attempts: 3);
        } catch (QueryException $excepcion) {
            $existente = EntregaFrutaProceso::query()
                ->where('operacion_anulacion_id', $operacionId)
                ->first();
            if (! $existente) {
                throw $excepcion;
            }
            if ($existente->id !== $entrega->id) {
                throw new ConflictoOperacion(
                    'El identificador de anulación ya fue utilizado en otra entrega.',
                );
            }

            return $this->cargarLote($existente->lote);
        }
    }

    public function puedeAnular(
        EntregaFrutaProceso $entrega,
        LoteMateriaPrima $lote,
        User $usuario,
        ?string $ultimaEntregaVigenteId = null,
    ): bool {
        if ($entrega->anulado_at !== null) {
            return false;
        }
        if ($entrega->camara_id !== $lote->asignacionCamara?->camara_id) {
            return false;
        }
        if ($entrega->retornos()->whereNull('anulado_at')->exists()) {
            return false;
        }
        if ($this->alcance->puedeCorregirEntregasFrutaProceso($usuario)) {
            return true;
        }
        if (! $this->alcance->puedeEntregarFrutaProceso($usuario)
            || $entrega->entregado_por_user_id !== $usuario->id
            || $lote->estado === EstadoLoteMateriaPrima::EntregadoProceso) {
            return false;
        }

        $ultimaEntregaVigenteId ??= EntregaFrutaProceso::query()
            ->where('lote_materia_prima_id', $lote->id)
            ->whereNull('anulado_at')
            ->latest('entregado_at')
            ->latest('created_at')
            ->latest('id')
            ->value('id');

        return $ultimaEntregaVigenteId === $entrega->id;
    }

    private function asegurarPuedeAnular(
        EntregaFrutaProceso $entrega,
        LoteMateriaPrima $lote,
        User $usuario,
    ): void {
        if ($entrega->camara_id !== $lote->asignacionCamara?->camara_id) {
            throw new ConflictoOperacion('La cámara del lote cambió desde este viaje. Revisa su ubicación antes de anularlo.');
        }
        if (! $this->puedeAnular($entrega, $lote, $usuario)) {
            abort(403, 'No puedes anular esta entrega. Debe ser tu último viaje abierto y no puede tener retornos de Packing.');
        }
    }

    private function cantidadEntregada(LoteMateriaPrima $lote): int
    {
        return (int) EntregaFrutaProceso::query()
            ->where('lote_materia_prima_id', $lote->id)
            ->whereNull('anulado_at')
            ->sum('cantidad_envases');
    }

    private function resolverEntregaRepetida(
        EntregaFrutaProceso $entrega,
        LoteMateriaPrima $lote,
        string $hash,
    ): LoteMateriaPrima {
        if ($entrega->lote_materia_prima_id !== $lote->id
            || ! hash_equals($entrega->payload_hash, $hash)) {
            throw new ConflictoOperacion(
                'El identificador de entrega ya fue utilizado con datos diferentes.',
            );
        }

        return $this->cargarLote($entrega->lote);
    }

    /** @param array<string, mixed> $datos */
    private function payload(array $datos): array
    {
        return [
            ...(array_key_exists('camara_origen_id', $datos) ? ['camara_origen_id' => $datos['camara_origen_id']] : []),
            'cantidad_envases' => (int) $datos['cantidad_envases'],
            'kilos_enviados' => filled($datos['kilos_enviados'] ?? null)
                ? round((float) $datos['kilos_enviados'], 3)
                : null,
            'linea_proceso' => trim((string) ($datos['linea_proceso'] ?? '')),
            'turno' => strtoupper(trim((string) $datos['turno'])),
            'numero_orden' => trim((string) ($datos['numero_orden'] ?? '')),
            'observacion' => filled($datos['observacion'] ?? null)
                ? trim((string) $datos['observacion'])
                : null,
            ...(filled($datos['orden_proceso_mp_id'] ?? null) ? [
                'orden_proceso_mp_id' => $datos['orden_proceso_mp_id'],
                'version_orden_proceso_mp_id' => $datos['version_orden_proceso_mp_id'] ?? null,
                'autorizado_por_user_id' => $datos['autorizacion']['user_id'] ?? null,
            ] : []),
        ];
    }

    /**
     * Relaciona la entrega con la orden. Si el lote está en la versión vigente se entrega contra
     * su fila; si no, solo con un supervisor autorizado entra a la orden como nueva versión.
     *
     * @param  array<string, mixed>  $datos
     * @return array{version_id: string, fuera_de_orden: bool, numero_proceso: string|null}
     */
    private function vincularOrden(
        OrdenProcesoMp $orden,
        LoteMateriaPrima $lote,
        int $cantidad,
        array $datos,
        ?User $autorizador,
    ): array {
        $fila = fn (?string $versionId): ?LoteVersionOrdenMp => $versionId ? LoteVersionOrdenMp::query()
            ->where('version_orden_proceso_mp_id', $versionId)
            ->where('lote_materia_prima_id', $lote->id)
            ->first() : null;
        $conocidaId = $datos['version_orden_proceso_mp_id'] ?? null;
        if (! $conocidaId || ! VersionOrdenProcesoMp::query()
            ->whereKey($conocidaId)
            ->where('orden_proceso_mp_id', $orden->id)
            ->exists()) {
            throw new ConflictoOperacion('La versión indicada no pertenece a esta orden. Actualiza antes de entregar.');
        }
        $vigente = $fila($orden->version_vigente_id);
        $cambio = $conocidaId !== null && $conocidaId !== $orden->version_vigente_id;
        $conocida = $cambio ? $fila($conocidaId) : null;

        if ($vigente) {
            if ($cambio && (! $conocida || ! $this->mismaFilaOrden($conocida, $vigente))) {
                throw new ConflictoOperacion(
                    "{$orden->codigo} cambió a la v{$orden->numero_version_vigente} y modificó el lote {$lote->numero_lote}. Actualiza la orden antes de entregar.",
                );
            }

            return [
                'version_id' => $orden->version_vigente_id,
                'fuera_de_orden' => false,
                'numero_proceso' => $vigente->numero_proceso,
            ];
        }
        if ($conocida) {
            throw new ConflictoOperacion(
                "El lote {$lote->numero_lote} salió de {$orden->codigo} en la v{$orden->numero_version_vigente}. Actualiza la orden antes de entregar.",
            );
        }
        if (! $autorizador) {
            throw ValidationException::withMessages([
                'autorizacion' => "El lote {$lote->numero_lote} no está en {$orden->codigo}. Un supervisor de frío debe autorizar la entrega con su PIN.",
            ]);
        }

        $version = $this->ordenes->agregarFueraDeOrden($orden, $lote, $cantidad, $autorizador);

        return ['version_id' => $version->id, 'fuera_de_orden' => true, 'numero_proceso' => null];
    }

    /**
     * Campos de la fila que definen qué se entrega. Los kilos y las cajas estimadas
     * se derivan de ellos. El horario, la secuencia y las copias de maestros quedan
     * fuera: cambian solos cuando se modifica otro lote de la orden y el horario es
     * estimativo, así que no deben bloquear la entrega de un lote que no cambió.
     */
    private const CAMPOS_FILA_ENTREGA = [
        'lote_materia_prima_id',
        'bins_programados',
        'totes_programados',
        'numero_proceso',
        'embalaje',
        'kilos_por_caja',
        'mercado_destino',
    ];

    private function mismaFilaOrden(LoteVersionOrdenMp $anterior, LoteVersionOrdenMp $vigente): bool
    {
        // Lista explícita: una columna nueva no invalida entregas sin una decisión.
        $campos = array_flip(self::CAMPOS_FILA_ENTREGA);

        return array_intersect_key($anterior->attributesToArray(), $campos)
            == array_intersect_key($vigente->attributesToArray(), $campos);
    }

    /** @param array<string, mixed> $autorizacion */
    private function verificarAutorizador(array $autorizacion): User
    {
        $autorizador = User::query()->find($autorizacion['user_id']);
        if (! $autorizador || ! $this->alcance->puedeCorregirEntregasFrutaProceso($autorizador)) {
            throw ValidationException::withMessages([
                'autorizacion.user_id' => 'Solo un supervisor de frío o administrador puede autorizar una entrega fuera de orden.',
            ]);
        }
        $this->pines->verificar($autorizador, (string) ($autorizacion['pin'] ?? ''), 'autorizacion.pin');

        return $autorizador;
    }

    /** @param array<string, mixed> $payload */
    private function hash(array $payload): string
    {
        try {
            return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'payload' => 'La entrega contiene datos que no pueden procesarse.',
            ]);
        }
    }

    /** @param array<string, mixed> $datos */
    private function registrarEvento(
        LoteMateriaPrima $lote,
        string $tipo,
        User $usuario,
        string $operacionId,
        EstadoLoteMateriaPrima $estadoAnterior,
        EstadoLoteMateriaPrima $estadoNuevo,
        array $datos,
    ): void {
        EventoLoteMateriaPrima::create([
            'lote_materia_prima_id' => $lote->id,
            'operacion_id' => $operacionId,
            'tipo' => $tipo,
            'estado_anterior' => $estadoAnterior->value,
            'estado_nuevo' => $estadoNuevo->value,
            'user_id' => $usuario->id,
            'ocurrido_at' => now(),
            'datos' => $datos,
        ]);
    }

    private function cargarLote(LoteMateriaPrima $lote): LoteMateriaPrima
    {
        return $lote->fresh([
            'temporada',
            'cliente',
            'recepcion',
            'asignacionCamara.camara',
            'reservasOrdenProceso.orden.linea',
            'entregasProceso.entregadoPor',
            'entregasProceso.anuladoPor',
            'entregasProceso.dispositivo',
            'entregasProceso.ordenProceso:id,codigo',
            'entregasProceso.autorizadoPor:id,name',
            'entregasProceso.retornos.registradoPor',
            'entregasProceso.retornos.anuladoPor',
            'entregasProceso.retornos.dispositivo',
            'entregasProceso.retornos.resultados.tipoResultado',
            'entregasProceso.retornos.resultados.camara',
            'entregasProceso.retornos.resultados.ubicadoPor',
            'entregasProceso.retornos.resultados.dispositivoUbicacion',
        ]);
    }
}

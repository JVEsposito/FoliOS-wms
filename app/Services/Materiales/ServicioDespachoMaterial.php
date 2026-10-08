<?php

namespace App\Services\Materiales;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoDespachoMaterial;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoPosicion;
use App\Enums\EstadoReservaMaterial;
use App\Enums\EstadoSesionEstiba;
use App\Enums\OrigenDespachoMaterial;
use App\Enums\TipoMovimientoInventarioMaterial;
use App\Exceptions\ConflictoOperacion;
use App\Exceptions\OperacionNoAutorizada;
use App\Models\AsignacionDespachoMaterial;
use App\Models\BloqueoCamara;
use App\Models\DespachoMaterial;
use App\Models\DestinoMaterial;
use App\Models\DetalleDespachoMaterial;
use App\Models\Dispositivo;
use App\Models\FolioMaterial;
use App\Models\ItemMaterial;
use App\Models\MovimientoInventarioMaterial;
use App\Models\OperacionRetiroMaterial;
use App\Models\ReservaMaterial;
use App\Models\RetiroMaterial;
use App\Models\SesionEstiba;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Notificaciones\ServicioNotificacionesOperacionales;
use App\Services\Temporadas\ServicioTemporadaActiva;
use DomainException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class ServicioDespachoMaterial
{
    public function __construct(
        private readonly AlcanceOperacionalUsuario $alcance,
        private readonly ServicioNotificacionesOperacionales $notificaciones,
        private readonly ServicioTemporadaActiva $temporadaActiva,
        private readonly ServicioReservaFifoMaterial $reservaFifo,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(
        array $datos,
        User $usuario,
        ?Dispositivo $dispositivo,
        bool $notificar = true,
    ): DespachoMaterial {
        if (! $this->alcance->puedeGestionarDespachosMateriales($usuario)) {
            throw new OperacionNoAutorizada(
                'El usuario no está autorizado para crear despachos de materiales.',
            );
        }

        return DB::transaction(function () use ($datos, $usuario, $dispositivo, $notificar): DespachoMaterial {
            $payloadHash = $this->payloadHash($datos);
            $existente = DespachoMaterial::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->lockForUpdate()
                ->first();

            if ($existente) {
                if ($existente->creado_por_user_id !== $usuario->id
                    || ! hash_equals($existente->payload_hash, $payloadHash)) {
                    throw new ConflictoOperacion(
                        'El UUID de la operación ya fue utilizado con datos diferentes.',
                    );
                }

                return $this->cargar($existente);
            }

            $temporada = $this->temporadaActiva->obtener(bloquear: true);

            $destino = DestinoMaterial::query()
                ->whereKey($datos['destino_material_id'])
                ->where('activo', true)
                ->lockForUpdate()
                ->first();

            if (! $destino) {
                throw new DomainException('El destino no existe o se encuentra inactivo.');
            }

            $asignado = isset($datos['asignado_a_user_id'])
                ? User::query()->find($datos['asignado_a_user_id']) : null;
            if (isset($datos['asignado_a_user_id'])
                && (! $asignado?->activo || ! $asignado->can('retirar-materiales'))) {
                throw new DomainException('El camarero asignado no está activo o no puede retirar materiales.');
            }

            $despacho = DespachoMaterial::create([
                'temporada_id' => $temporada->id,
                'codigo' => $this->siguienteCodigoBloqueado(),
                'operacion_id' => $datos['operacion_id'],
                'payload_hash' => $payloadHash,
                'origen' => $dispositivo
                    ? OrigenDespachoMaterial::Tablet
                    : OrigenDespachoMaterial::Oficina,
                'modalidad' => $datos['modalidad'] ?? 'delegado',
                'asignado_a_user_id' => $asignado?->id,
                'estado' => EstadoDespachoMaterial::Pendiente,
                'destino_material_id' => $destino->id,
                'destino_nombre' => $destino->nombre,
                'destino_centro_costo' => $destino->centro_costo,
                'observacion' => $datos['observacion'] ?? null,
                'creado_por_user_id' => $usuario->id,
                'creado_desde_dispositivo_id' => $dispositivo?->id,
            ]);

            if ($asignado) {
                AsignacionDespachoMaterial::create([
                    'operacion_id' => $datos['operacion_id'],
                    'despacho_material_id' => $despacho->id,
                    'asignado_a_user_id' => $asignado->id,
                    'asignado_por_user_id' => $usuario->id,
                    'motivo' => 'Asignación inicial del despacho delegado.',
                ]);
            }

            foreach ($datos['items'] as $linea) {
                $item = ItemMaterial::query()
                    ->whereKey($linea['item_material_id'])
                    ->where('activo', true)
                    ->whereHas('cliente', fn ($consulta) => $consulta
                        ->where('activo', true)
                        ->whereHas('temporada', fn ($temporadaMaterial) => $temporadaMaterial
                            ->where('temporada_id', $temporada->id)
                            ->where('activa', true)))
                    ->lockForUpdate()
                    ->first();

                if (! $item) {
                    throw new DomainException('Uno de los ítems no existe o se encuentra inactivo.');
                }

                $detalle = DetalleDespachoMaterial::create([
                    'despacho_material_id' => $despacho->id,
                    'item_material_id' => $item->id,
                    'cantidad_solicitada' => $this->cantidad($linea['cantidad']),
                    'cantidad_despachada' => 0,
                    'unidad_medida' => $item->unidad_medida,
                ]);
                $this->reservarFifo($detalle);
            }

            if ($notificar) {
                $this->notificaciones->notificarDespachoMaterialCreado($despacho);
            }

            return $this->cargar($despacho);
        }, attempts: 3);
    }

    /**
     * @param  array<int, array<string, mixed>>  $retiros
     */
    public function retirar(
        DespachoMaterial $despacho,
        string $operacionId,
        array $retiros,
        User $usuario,
        ?Dispositivo $dispositivo,
        bool $requiereSesion = true,
        ?string $motivoExcepcionFifo = null,
    ): DespachoMaterial {
        if (! $this->alcance->puedeRetirarMateriales($usuario)) {
            throw new OperacionNoAutorizada(
                'El usuario no está autorizado para retirar materiales.',
            );
        }

        return DB::transaction(function () use (
            $despacho,
            $operacionId,
            $retiros,
            $usuario,
            $dispositivo,
            $motivoExcepcionFifo,
        ): DespachoMaterial {
            $despacho = DespachoMaterial::query()
                ->with('detalles')
                ->lockForUpdate()
                ->findOrFail($despacho->id);
            $this->asegurarTemporadaVigente($despacho);
            $payload = [
                'despacho_material_id' => $despacho->id,
                'retiros' => $retiros,
            ];
            if ($motivoExcepcionFifo !== null) {
                $payload['motivo_excepcion_fifo'] = $motivoExcepcionFifo;
            }
            $payloadHash = $this->payloadHash($payload);
            $operacionRetiro = OperacionRetiroMaterial::query()
                ->lockForUpdate()
                ->find($operacionId);

            if ($operacionRetiro) {
                if ($operacionRetiro->despacho_material_id !== $despacho->id
                    || $operacionRetiro->user_id !== $usuario->id
                    || $operacionRetiro->dispositivo_id !== $dispositivo?->id
                    || ! hash_equals($operacionRetiro->payload_hash, $payloadHash)) {
                    throw new ConflictoOperacion(
                        'El UUID del retiro ya fue utilizado con datos diferentes.',
                    );
                }

                return $this->cargar($despacho);
            }

            if (! in_array($despacho->estado, [
                EstadoDespachoMaterial::Pendiente,
                EstadoDespachoMaterial::Parcial,
            ], true)) {
                throw new DomainException('El despacho ya no admite retiros.');
            }
            if ($despacho->asignado_a_user_id !== null
                && (int) $despacho->asignado_a_user_id !== (int) $usuario->id) {
                throw new OperacionNoAutorizada('Este despacho está asignado a otro camarero.');
            }

            $operacionRetiro = OperacionRetiroMaterial::create([
                'id' => $operacionId,
                'despacho_material_id' => $despacho->id,
                'user_id' => $usuario->id,
                'dispositivo_id' => $dispositivo->id,
                'payload_hash' => $payloadHash,
            ]);

            $sugerencias = [];
            foreach ($despacho->detalles as $detalle) {
                $sugerencias[$detalle->id] = $this->liberarReservas($detalle)
                    ->map(fn (ReservaMaterial $reserva) => [
                        'folio_id' => $reserva->folio_id,
                        'cantidad' => (float) $reserva->cantidad,
                    ])
                    ->all();
            }

            $retiradoPorDetalle = [];

            foreach ($retiros as $datosRetiro) {
                $folioMaterial = FolioMaterial::query()
                    ->with(['folio.ubicacionActual.camara', 'folio.ubicacionActual.posicion', 'item'])
                    ->lockForUpdate()
                    ->findOrFail($datosRetiro['folio_id']);

                $folioMaterial->asegurarVigente();

                if (! $folioMaterial->folio?->activo
                    || $folioMaterial->motivo_bloqueo !== null
                    || $folioMaterial->folio->estado_operacional !== EstadoOperacionalFolio::Disponible) {
                    throw new DomainException(
                        'El folio se encuentra bloqueado o no está disponible para retiro.',
                    );
                }

                $detalle = $despacho->detalles->firstWhere(
                    'item_material_id',
                    $folioMaterial->item_material_id,
                );

                if (! $detalle) {
                    throw new DomainException(sprintf(
                        'El ítem %s no pertenece al despacho.',
                        $folioMaterial->item->nombre,
                    ));
                }

                $cantidad = $this->cantidad($datosRetiro['cantidad']);
                $yaRetirado = $retiradoPorDetalle[$detalle->id] ?? 0.0;
                $pendiente = (float) $detalle->cantidad_solicitada
                    - (float) $detalle->cantidad_despachada
                    - $yaRetirado;
                $disponible = (float) $folioMaterial->cantidad_actual
                    - (float) $folioMaterial->cantidad_reservada;

                if ($cantidad > $pendiente + 0.0001) {
                    throw new DomainException('La cantidad retirada supera lo pendiente del despacho.');
                }

                if ($cantidad > $disponible + 0.0001) {
                    throw new DomainException('La cantidad retirada supera el saldo disponible del folio.');
                }

                $ubicacion = $folioMaterial->folio->ubicacionActual;
                $posicion = $ubicacion?->posicion;
                $camara = $ubicacion?->camara ?? $posicion?->camara;

                if (! $ubicacion || ! $camara
                    || $camara->contenido !== ContenidoCamara::Materiales
                    || $camara->estado !== EstadoCamara::Activa
                    || ($posicion && $posicion->estado !== EstadoPosicion::Activa)) {
                    throw new DomainException('El folio no posee una ubicación material válida.');
                }

                $sesion = SesionEstiba::query()
                    ->lockForUpdate()
                    ->findOrFail($datosRetiro['sesion_estiba_id']);
                $this->validarSesion($sesion, $camara->id, $usuario, $dispositivo);

                $anterior = (float) $folioMaterial->cantidad_actual;
                $resultante = round($anterior - $cantidad, 3);
                $siguioFifo = $this->sigueReservaFifo(
                    $sugerencias, $detalle->id, $folioMaterial->folio_id, $cantidad,
                );
                if (! $siguioFifo && mb_strlen(trim((string) $motivoExcepcionFifo)) < 5) {
                    throw new DomainException('Justifica la excepción FIFO antes de retirar otro folio.');
                }

                $folioMaterial->update(['cantidad_actual' => $resultante]);
                $retiro = RetiroMaterial::create([
                    'operacion_retiro_material_id' => $operacionRetiro->id,
                    'detalle_despacho_material_id' => $detalle->id,
                    'folio_id' => $folioMaterial->folio_id,
                    'cantidad_anterior' => $anterior,
                    'cantidad_retirada' => $cantidad,
                    'cantidad_resultante' => $resultante,
                    'camara_id' => $camara->id,
                    'posicion_id' => $posicion?->id,
                    'user_id' => $usuario->id,
                    'dispositivo_id' => $dispositivo->id,
                    'siguio_fifo' => $siguioFifo,
                    'motivo_excepcion_fifo' => $siguioFifo ? null : $motivoExcepcionFifo,
                    'retirado_at' => now(),
                ]);
                MovimientoInventarioMaterial::create([
                    'folio_id' => $folioMaterial->folio_id,
                    'item_material_id' => $folioMaterial->item_material_id,
                    'tipo' => TipoMovimientoInventarioMaterial::Despacho,
                    'cantidad' => -$cantidad,
                    'cantidad_anterior' => $anterior,
                    'cantidad_resultante' => $resultante,
                    'despacho_material_id' => $despacho->id,
                    'retiro_material_id' => $retiro->id,
                    'user_id' => $usuario->id,
                    'dispositivo_id' => $dispositivo->id,
                    'destino_nombre' => $despacho->destino_nombre,
                    'destino_centro_costo' => $despacho->destino_centro_costo,
                    'motivo' => 'Despacho de materiales.',
                    'metadatos' => [
                        'siguio_fifo' => $siguioFifo,
                        'motivo_excepcion_fifo' => $siguioFifo ? null : $motivoExcepcionFifo,
                        'camara' => $camara->codigo,
                        'posicion' => $posicion?->etiqueta,
                    ],
                    'ocurrido_at' => now(),
                ]);

                $retiradoPorDetalle[$detalle->id] = $yaRetirado + $cantidad;
                $sesion->update(['ultima_actividad_at' => now()]);

                if ($resultante <= 0.0001) {
                    UbicacionActual::query()->whereKey($ubicacion->id)->delete();
                    $folioMaterial->folio->update([
                        'estado_operacional' => EstadoOperacionalFolio::Despachado,
                        'activo' => false,
                    ]);
                    $camara->increment('version_plano');
                }
            }

            foreach ($retiradoPorDetalle as $detalleId => $cantidad) {
                DetalleDespachoMaterial::query()
                    ->whereKey($detalleId)
                    ->increment('cantidad_despachada', $cantidad);
            }

            $despacho->load('detalles');
            $completo = $despacho->detalles->every(
                fn (DetalleDespachoMaterial $detalle): bool => (float) $detalle->cantidad_despachada + 0.0001
                    >= (float) $detalle->cantidad_solicitada,
            );
            $despacho->update([
                'estado' => $completo
                    ? EstadoDespachoMaterial::Completado
                    : EstadoDespachoMaterial::Parcial,
                'completado_at' => $completo ? now() : null,
            ]);

            if (! $completo) {
                foreach ($despacho->detalles as $detalle) {
                    $this->reservarFifo($detalle->refresh());
                }
            }

            $operacionRetiro->update(['procesada_at' => now()]);

            return $this->cargar($despacho->refresh());
        }, attempts: 3);
    }

    public function cancelar(
        DespachoMaterial $despacho,
        string $operacionId,
        string $motivo,
        User $usuario,
        ?Dispositivo $dispositivo,
    ): DespachoMaterial {
        if (! $this->alcance->puedeCancelarDespachosMateriales($usuario)) {
            throw new OperacionNoAutorizada(
                'El usuario no está autorizado para cancelar despachos de materiales.',
            );
        }

        if (! Str::isUuid($operacionId)) {
            throw new DomainException('El identificador de la cancelación debe ser un UUID válido.');
        }

        $motivo = trim($motivo);

        if (mb_strlen($motivo) < 3) {
            throw new DomainException('El motivo de cancelación debe contener al menos 3 caracteres.');
        }

        try {
            return DB::transaction(function () use (
                $despacho,
                $operacionId,
                $motivo,
                $usuario,
                $dispositivo,
            ): DespachoMaterial {
                $despacho = DespachoMaterial::query()
                    ->with('detalles')
                    ->lockForUpdate()
                    ->findOrFail($despacho->id);
                $this->asegurarTemporadaVigente($despacho);
                $payloadHash = $this->payloadHash([
                    'despacho_material_id' => $despacho->id,
                    'motivo' => $motivo,
                ]);
                $otraCancelacion = DespachoMaterial::query()
                    ->where('cancelacion_operacion_id', $operacionId)
                    ->where('id', '!=', $despacho->id)
                    ->lockForUpdate()
                    ->exists();

                if ($otraCancelacion) {
                    throw new ConflictoOperacion(
                        'El UUID de cancelación ya fue utilizado en otro despacho.',
                    );
                }

                if ($despacho->cancelacion_operacion_id !== null) {
                    $mismaOperacion = $despacho->cancelacion_operacion_id === $operacionId
                        && $despacho->cancelado_por_user_id === $usuario->id
                        && $despacho->cancelado_desde_dispositivo_id === $dispositivo?->id
                        && hash_equals(
                            (string) $despacho->cancelacion_payload_hash,
                            $payloadHash,
                        );

                    if (! $mismaOperacion) {
                        throw new ConflictoOperacion(
                            'El despacho ya fue cancelado con una operación diferente.',
                        );
                    }

                    return $this->cargar($despacho);
                }

                if ($despacho->estado === EstadoDespachoMaterial::Completado) {
                    throw new DomainException('Un despacho completado no puede cancelarse.');
                }

                if ($despacho->estado === EstadoDespachoMaterial::Cancelado) {
                    throw new DomainException(
                        'El despacho ya se encontraba cancelado antes de registrar esta operación.',
                    );
                }

                foreach ($despacho->detalles as $detalle) {
                    $this->liberarReservas($detalle);
                }

                $despacho->update([
                    'estado' => EstadoDespachoMaterial::Cancelado,
                    'cancelado_at' => now(),
                    'cancelacion_operacion_id' => $operacionId,
                    'cancelacion_payload_hash' => $payloadHash,
                    'cancelado_por_user_id' => $usuario->id,
                    'cancelado_desde_dispositivo_id' => $dispositivo?->id,
                    'cancelacion_motivo' => $motivo,
                ]);

                return $this->cargar($despacho->refresh());
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConflictoOperacion(
                'El UUID de cancelación fue utilizado por otra operación concurrente.',
                previous: $exception,
            );
        }
    }

    public function cargar(DespachoMaterial $despacho): DespachoMaterial
    {
        return $despacho->load($this->relacionesCarga());
    }

    public function reasignar(
        DespachoMaterial $despacho,
        string $operacionId,
        int $asignadoA,
        string $motivo,
        User $usuario,
    ): DespachoMaterial {
        if (! $this->alcance->puedeGestionarDespachosMateriales($usuario)) {
            throw new OperacionNoAutorizada('No tienes permiso para reasignar despachos.');
        }

        return DB::transaction(function () use ($despacho, $operacionId, $asignadoA, $motivo, $usuario): DespachoMaterial {
            $despacho = DespachoMaterial::query()->lockForUpdate()->findOrFail($despacho->id);
            $this->asegurarTemporadaVigente($despacho);
            $existente = AsignacionDespachoMaterial::query()->where('operacion_id', $operacionId)->first();
            if ($existente) {
                if ($existente->despacho_material_id !== $despacho->id
                    || $existente->asignado_por_user_id !== $usuario->id
                    || $existente->asignado_a_user_id !== $asignadoA
                    || $existente->motivo !== $motivo) {
                    throw new ConflictoOperacion('El UUID de reasignación ya fue utilizado con otros datos.');
                }

                return $this->cargar($despacho);
            }

            if ($despacho->modalidad !== 'delegado'
                || ! in_array($despacho->estado, [EstadoDespachoMaterial::Pendiente, EstadoDespachoMaterial::Parcial], true)) {
                throw new DomainException('Solo se pueden reasignar despachos delegados pendientes.');
            }
            $destinatario = User::query()->find($asignadoA);
            if (! $destinatario?->activo || ! $destinatario->can('retirar-materiales')) {
                throw new DomainException('El camarero elegido no está activo o no puede retirar materiales.');
            }
            if ((int) $despacho->asignado_a_user_id === $asignadoA) {
                throw new DomainException('El despacho ya está asignado a este camarero.');
            }

            $despacho->update(['asignado_a_user_id' => $asignadoA]);
            AsignacionDespachoMaterial::create([
                'operacion_id' => $operacionId,
                'despacho_material_id' => $despacho->id,
                'asignado_a_user_id' => $asignadoA,
                'asignado_por_user_id' => $usuario->id,
                'motivo' => $motivo,
            ]);

            return $this->cargar($despacho);
        }, attempts: 3);
    }

    /**
     * @param  EloquentCollection<int, DespachoMaterial>  $despachos
     * @return EloquentCollection<int, DespachoMaterial>
     */
    public function cargarColeccion(EloquentCollection $despachos): EloquentCollection
    {
        $despachos->load($this->relacionesCarga());

        return $despachos;
    }

    /**
     * @param  EloquentCollection<int, DespachoMaterial>  $despachos
     * @return EloquentCollection<int, DespachoMaterial>
     */
    public function cargarColeccionResumen(EloquentCollection $despachos): EloquentCollection
    {
        $despachos->load([
            'temporada:id,codigo,nombre,activa',
            'detalles' => fn ($consulta) => $consulta->withSum(
                ['reservas as cantidad_reservada_resumen' => fn ($reservas) => $reservas
                    ->where('estado', EstadoReservaMaterial::Activa->value)],
                'cantidad',
            ),
            'detalles.item.cliente.temporada',
        ]);

        return $despachos;
    }

    /**
     * @param  EloquentCollection<int, DespachoMaterial>  $despachos
     * @return EloquentCollection<int, DespachoMaterial>
     */
    public function cargarColeccionOperacion(EloquentCollection $despachos): EloquentCollection
    {
        $despachos->load([
            'temporada:id,codigo,nombre,activa',
            'detalles.item.cliente.temporada',
            'detalles.reservas' => fn ($consulta) => $consulta
                ->where('estado', EstadoReservaMaterial::Activa->value)
                ->orderBy('orden_fifo'),
            'detalles.reservas.folioMaterial.folio.ubicacionActual.posicion.camara',
        ]);

        return $despachos;
    }

    /**
     * @return array<int, mixed>
     */
    private function relacionesCarga(): array
    {
        return [
            'temporada:id,codigo,nombre,activa',
            'creadoPor:id,name',
            'asignadoA:id,name',
            'asignaciones.asignadoA:id,name',
            'asignaciones.asignadoPor:id,name',
            'dispositivo:id,codigo,nombre',
            'canceladoPor:id,name',
            'dispositivoCancelacion:id,codigo,nombre',
            'detalles.item.cliente.temporada',
            'detalles.reservas' => fn ($consulta) => $consulta
                ->where('estado', EstadoReservaMaterial::Activa->value)
                ->orderBy('orden_fifo'),
            'detalles.reservas.folioMaterial.folio.ubicacionActual.posicion.camara',
            'detalles.historialReservas.folioMaterial.folio',
            'detalles.retiros.folioMaterial.folio',
            'detalles.retiros.camara:id,codigo,nombre',
            'detalles.retiros.posicion:id,camara_id,etiqueta',
            'detalles.retiros.usuario:id,name',
            'detalles.retiros.dispositivo:id,codigo,nombre',
        ];
    }

    private function siguienteCodigoBloqueado(): string
    {
        $ultimoNumero = DB::table('secuencias_documentos')
            ->where('clave', 'despachos_materiales')
            ->lockForUpdate()
            ->value('ultimo_numero');

        if ($ultimoNumero === null) {
            throw new LogicException(
                'No existe la secuencia configurada para los despachos de materiales.',
            );
        }

        $siguienteNumero = ((int) $ultimoNumero) + 1;
        DB::table('secuencias_documentos')
            ->where('clave', 'despachos_materiales')
            ->update(['ultimo_numero' => $siguienteNumero]);

        return sprintf('MAT-DES-%06d', $siguienteNumero);
    }

    private function reservarFifo(DetalleDespachoMaterial $detalle): void
    {
        $pendiente = round(
            (float) $detalle->cantidad_solicitada - (float) $detalle->cantidad_despachada,
            3,
        );

        if ($pendiente <= 0) {
            return;
        }

        $this->reservaFifo->reservar(
            $detalle->item_material_id,
            $pendiente,
            function (FolioMaterial $folio, float $cantidad, int $orden) use ($detalle): void {
                ReservaMaterial::updateOrCreate([
                    'detalle_despacho_material_id' => $detalle->id,
                    'folio_id' => $folio->folio_id,
                ], [
                    'cantidad' => $cantidad,
                    'estado' => EstadoReservaMaterial::Activa,
                    'orden_fifo' => $orden,
                ]);
            },
        );
    }

    /**
     * @return Collection<int, ReservaMaterial>
     */
    private function liberarReservas(DetalleDespachoMaterial $detalle): Collection
    {
        $reservas = ReservaMaterial::query()
            ->where('detalle_despacho_material_id', $detalle->id)
            ->where('estado', EstadoReservaMaterial::Activa->value)
            ->orderBy('orden_fifo')
            ->lockForUpdate()
            ->get();

        foreach ($reservas as $reserva) {
            $folio = FolioMaterial::query()->lockForUpdate()->findOrFail($reserva->folio_id);
            $folio->update([
                'cantidad_reservada' => max(
                    0,
                    round((float) $folio->cantidad_reservada - (float) $reserva->cantidad, 3),
                ),
            ]);
            $reserva->update(['estado' => EstadoReservaMaterial::Liberada]);
        }

        return $reservas;
    }

    /**
     * Compara el retiro con la cantidad y el orden reservados antes de liberar
     * FIFO. Retirar de un folio sugerido mientras el anterior sigue pendiente
     * también constituye una excepción.
     *
     * @param  array<string, array<int, array{folio_id: string, cantidad: float}>>  $reservas
     */
    protected function sigueReservaFifo(array &$reservas, string $detalleId, string $folioId, float $cantidad): bool
    {
        if (! isset($reservas[$detalleId])) {
            return false;
        }
        $anteriorPendiente = false;
        foreach ($reservas[$detalleId] as &$reserva) {
            if ($reserva['folio_id'] === $folioId) {
                $cubierto = $cantidad <= $reserva['cantidad'] + 0.0001;
                $reserva['cantidad'] = max(0, round($reserva['cantidad'] - $cantidad, 3));

                return ! $anteriorPendiente && $cubierto;
            }
            $anteriorPendiente = $anteriorPendiente || $reserva['cantidad'] > 0.0001;
        }

        return false;
    }

    protected function asegurarTemporadaVigente(DespachoMaterial $despacho): void
    {
        if ($despacho->temporada_id !== $this->temporadaActiva->buscar()?->id) {
            throw new DomainException('El despacho pertenece a una temporada histórica y no admite operaciones.');
        }
    }

    private function validarSesion(
        SesionEstiba $sesion,
        string $camaraId,
        User $usuario,
        Dispositivo $dispositivo,
    ): void {
        if ($sesion->camara_id !== $camaraId
            || $sesion->user_id !== $usuario->id
            || $sesion->dispositivo_id !== $dispositivo->id
            || $sesion->estado !== EstadoSesionEstiba::Abierta) {
            throw new DomainException('La sesión no autoriza retiros en la cámara del folio.');
        }

        $bloqueo = BloqueoCamara::query()
            ->where('camara_id', $camaraId)
            ->where('sesion_estiba_id', $sesion->id)
            ->exists();

        if (! $bloqueo) {
            throw new DomainException('La sesión no posee el bloqueo de la cámara.');
        }
    }

    private function cantidad(mixed $valor): float
    {
        $cantidad = round((float) $valor, 3);

        if ($cantidad <= 0) {
            throw new DomainException('Las cantidades deben ser mayores que cero.');
        }

        return $cantidad;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->normalizarPayload($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function normalizarPayload(mixed $valor): mixed
    {
        if (! is_array($valor)) {
            return $valor;
        }

        if (array_is_list($valor)) {
            $normalizado = array_map(
                fn (mixed $item): mixed => $this->normalizarPayload($item),
                $valor,
            );
            usort($normalizado, fn (mixed $a, mixed $b): int => strcmp(
                json_encode($a, JSON_THROW_ON_ERROR),
                json_encode($b, JSON_THROW_ON_ERROR),
            ));

            return $normalizado;
        }

        ksort($valor, SORT_STRING);

        return array_map(
            fn (mixed $item): mixed => $this->normalizarPayload($item),
            $valor,
        );
    }
}

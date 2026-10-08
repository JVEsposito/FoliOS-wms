<?php

namespace App\Services\Materiales;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoDespachoMaterial;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoPosicion;
use App\Enums\EstadoReservaMaterial;
use App\Enums\EstadoSesionEstiba;
use App\Enums\TipoAlmacenMaterial;
use App\Enums\TipoMovimientoAlmacenMaterial;
use App\Enums\TipoMovimientoInventarioMaterial;
use App\Exceptions\ConflictoOperacion;
use App\Exceptions\OperacionNoAutorizada;
use App\Models\BloqueoCamara;
use App\Models\DespachoMaterial;
use App\Models\DestinoMaterial;
use App\Models\DetalleDespachoMaterial;
use App\Models\Dispositivo;
use App\Models\FolioMaterial;
use App\Models\MovimientoAlmacenMaterial;
use App\Models\MovimientoInventarioMaterial;
use App\Models\OperacionRetiroMaterial;
use App\Models\ReservaMaterial;
use App\Models\RetiroMaterial;
use App\Models\SaldoMaterialAlmacen;
use App\Models\SesionEstiba;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Notificaciones\ServicioNotificacionesOperacionales;
use App\Services\Temporadas\ServicioTemporadaActiva;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServicioDespachoMaterialDistribuido extends ServicioDespachoMaterial
{
    public function __construct(
        private readonly AlcanceOperacionalUsuario $alcanceDistribuido,
        ServicioNotificacionesOperacionales $notificaciones,
        ServicioTemporadaActiva $temporadaActiva,
        ServicioReservaFifoMaterial $reservaFifo,
        private readonly ServicioAlmacenMaterial $almacenes,
    ) {
        parent::__construct(
            $alcanceDistribuido,
            $notificaciones,
            $temporadaActiva,
            $reservaFifo,
        );
    }

    /**
     * Crea y completa una entrega desde la Oficina de Materiales.
     *
     * @param  array<string, mixed>  $datos
     */
    public function despacharDirecto(
        array $datos,
        User $usuario,
    ): DespachoMaterial {
        if (! $this->alcanceDistribuido->puedeGestionarDespachosMateriales($usuario)
            || ! $this->alcanceDistribuido->puedeRetirarMateriales($usuario)) {
            throw new OperacionNoAutorizada(
                'El usuario no está autorizado para realizar despachos directos.',
            );
        }

        return DB::transaction(function () use ($datos, $usuario): DespachoMaterial {
            $retiros = $datos['retiros'] ?? [[
                'folio_id' => $datos['folio_id'],
                'cantidad' => $datos['cantidad'],
            ]];
            $folios = FolioMaterial::query()
                ->whereIn('folio_id', array_column($retiros, 'folio_id'))
                ->get()
                ->keyBy('folio_id');
            $cantidades = [];
            foreach ($retiros as $retiro) {
                $folio = $folios->get($retiro['folio_id']);
                if (! $folio) {
                    throw new DomainException('Uno de los folios no existe.');
                }
                $cantidades[$folio->item_material_id] = round(
                    ($cantidades[$folio->item_material_id] ?? 0) + (float) $retiro['cantidad'], 3,
                );
            }
            if (count($cantidades) > 50) {
                throw new DomainException('La entrega admite hasta 50 ítems distintos.');
            }
            $items = isset($datos['retiros'])
                ? collect($cantidades)->map(fn ($cantidad, $itemId) => [
                    'item_material_id' => $itemId, 'cantidad' => $cantidad,
                ])->values()->all()
                : [[
                    'item_material_id' => $folios->get($datos['folio_id'])->item_material_id,
                    'cantidad' => $datos['cantidad'],
                ]];
            $despacho = $this->crear([
                'operacion_id' => $datos['operacion_id'],
                'destino_material_id' => $datos['destino_material_id'],
                'observacion' => $datos['observacion'] ?? null,
                'items' => $items,
            ], $usuario, null, notificar: false);
            if ($despacho->modalidad !== 'directo') {
                $despacho->update(['modalidad' => 'directo']);
            }

            return $this->retirar(
                $despacho,
                $datos['operacion_id'],
                $retiros,
                $usuario,
                null,
                requiereSesion: false,
                motivoExcepcionFifo: $datos['motivo_excepcion_fifo'] ?? null,
            );
        }, attempts: 3);
    }

    /**
     * La entrega deja de ser una salida terminal: transfiere custodia desde
     * Bodega Central hacia el almacén virtual asociado al destino.
     *
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
        if (! $this->alcanceDistribuido->puedeRetirarMateriales($usuario)) {
            throw new OperacionNoAutorizada(
                'El usuario no está autorizado para entregar materiales.',
            );
        }

        if ($requiereSesion && ! $dispositivo) {
            throw new DomainException(
                'Las entregas desde tablet requieren un dispositivo autorizado.',
            );
        }

        return DB::transaction(function () use (
            $despacho,
            $operacionId,
            $retiros,
            $usuario,
            $dispositivo,
            $requiereSesion,
            $motivoExcepcionFifo,
        ): DespachoMaterial {
            $despacho = DespachoMaterial::query()
                ->with(['detalles', 'destino'])
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
                        'El UUID de la entrega ya fue utilizado con datos diferentes.',
                    );
                }

                return $this->cargar($despacho);
            }

            if (! in_array($despacho->estado, [
                EstadoDespachoMaterial::Pendiente,
                EstadoDespachoMaterial::Parcial,
            ], true)) {
                throw new DomainException('La solicitud ya no admite entregas.');
            }
            if ($despacho->asignado_a_user_id !== null
                && (int) $despacho->asignado_a_user_id !== (int) $usuario->id) {
                throw new OperacionNoAutorizada('Este despacho está asignado a otro camarero.');
            }

            $destinoCatalogo = DestinoMaterial::query()
                ->whereKey($despacho->destino_material_id)
                ->where('activo', true)
                ->lockForUpdate()
                ->first();

            if (! $destinoCatalogo) {
                throw new DomainException('El destino de la solicitud ya no está activo.');
            }

            $bodega = $this->almacenes->bodegaCentral($usuario, bloquear: true);
            $almacenDestino = $this->almacenes->almacenDesdeDestino(
                $destinoCatalogo,
                $usuario,
            );

            if ($almacenDestino->id === $bodega->id
                || $almacenDestino->tipo !== TipoAlmacenMaterial::Virtual) {
                throw new DomainException(
                    'El despacho debe dirigirse a un centro de costo virtual.',
                );
            }

            $operacionRetiro = OperacionRetiroMaterial::create([
                'id' => $operacionId,
                'despacho_material_id' => $despacho->id,
                'user_id' => $usuario->id,
                'dispositivo_id' => $dispositivo?->id,
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
            $secuencia = 1;

            foreach ($retiros as $datosRetiro) {
                $folioMaterial = FolioMaterial::query()
                    ->with(['folio', 'item'])
                    ->lockForUpdate()
                    ->findOrFail($datosRetiro['folio_id']);

                $folioMaterial->asegurarVigente();

                if (! $folioMaterial->folio?->activo
                    || $folioMaterial->motivo_bloqueo !== null
                    || $folioMaterial->folio->estado_operacional !== EstadoOperacionalFolio::Disponible) {
                    throw new DomainException(
                        'El folio se encuentra bloqueado o no está disponible para entrega.',
                    );
                }

                $detalle = $despacho->detalles->firstWhere(
                    'item_material_id',
                    $folioMaterial->item_material_id,
                );

                if (! $detalle) {
                    throw new DomainException(sprintf(
                        'El ítem %s no pertenece a la solicitud.',
                        $folioMaterial->item->nombre,
                    ));
                }

                $cantidad = $this->cantidad($datosRetiro['cantidad']);
                $yaRetirado = $retiradoPorDetalle[$detalle->id] ?? 0.0;
                $pendiente = (float) $detalle->cantidad_solicitada
                    - (float) $detalle->cantidad_despachada
                    - $yaRetirado;

                if ($cantidad > $pendiente + 0.0001) {
                    throw new DomainException(
                        'La cantidad entregada supera lo pendiente de la solicitud.',
                    );
                }

                $saldoOrigen = SaldoMaterialAlmacen::query()
                    ->with(['camara', 'posicion'])
                    ->where('folio_id', $folioMaterial->folio_id)
                    ->where('almacen_material_id', $bodega->id)
                    ->lockForUpdate()
                    ->first();

                if (! $saldoOrigen) {
                    throw new DomainException(
                        'El folio no posee saldo bajo custodia de Bodega Central.',
                    );
                }

                $disponible = round(
                    (float) $saldoOrigen->cantidad_actual
                        - (float) $saldoOrigen->cantidad_reservada,
                    3,
                );

                if ($cantidad > $disponible + 0.0001) {
                    throw new DomainException(
                        'La cantidad entregada supera el saldo disponible en Bodega.',
                    );
                }

                $camara = $saldoOrigen->camara;
                $posicion = $saldoOrigen->posicion;

                if (! $camara
                    || $camara->contenido !== ContenidoCamara::Materiales
                    || $camara->estado !== EstadoCamara::Activa
                    || ($posicion && $posicion->estado !== EstadoPosicion::Activa)) {
                    throw new DomainException(
                        'El saldo de Bodega no posee una ubicación material válida.',
                    );
                }

                $sesion = null;
                if ($requiereSesion) {
                    $sesion = SesionEstiba::query()
                        ->lockForUpdate()
                        ->findOrFail($datosRetiro['sesion_estiba_id'] ?? null);
                    $this->validarSesion($sesion, $camara->id, $usuario, $dispositivo);
                }

                $origenAnterior = (float) $saldoOrigen->cantidad_actual;
                $origenResultante = round($origenAnterior - $cantidad, 3);
                $saldoDestino = $this->almacenes->saldo(
                    $folioMaterial,
                    $almacenDestino,
                );
                $destinoAnterior = (float) $saldoDestino->cantidad_actual;
                $destinoResultante = round($destinoAnterior + $cantidad, 3);
                $siguioFifo = $this->sigueReservaFifo(
                    $sugerencias, $detalle->id, $folioMaterial->folio_id, $cantidad,
                );
                if (! $siguioFifo && mb_strlen(trim((string) $motivoExcepcionFifo)) < 5) {
                    throw new DomainException('Debes indicar el motivo de la excepción FIFO (mínimo 5 caracteres).');
                }

                $saldoOrigen->update([
                    'cantidad_actual' => $origenResultante,
                    'camara_id' => $origenResultante > 0 ? $camara->id : null,
                    'posicion_id' => $origenResultante > 0 ? $posicion?->id : null,
                ]);
                $saldoDestino->update([
                    'cantidad_actual' => $destinoResultante,
                    'camara_id' => null,
                    'posicion_id' => null,
                ]);

                $retiro = RetiroMaterial::create([
                    'operacion_retiro_material_id' => $operacionRetiro->id,
                    'detalle_despacho_material_id' => $detalle->id,
                    'folio_id' => $folioMaterial->folio_id,
                    'cantidad_anterior' => $origenAnterior,
                    'cantidad_retirada' => $cantidad,
                    'cantidad_resultante' => $origenResultante,
                    'camara_id' => $camara->id,
                    'posicion_id' => $posicion?->id,
                    'user_id' => $usuario->id,
                    'dispositivo_id' => $dispositivo?->id,
                    'siguio_fifo' => $siguioFifo,
                    'motivo_excepcion_fifo' => $siguioFifo ? null : $motivoExcepcionFifo,
                    'retirado_at' => now(),
                ]);
                MovimientoAlmacenMaterial::create([
                    'operacion_id' => $operacionId,
                    'secuencia' => $secuencia++,
                    'payload_hash' => $payloadHash,
                    'tipo' => TipoMovimientoAlmacenMaterial::Entrega,
                    'folio_id' => $folioMaterial->folio_id,
                    'item_material_id' => $folioMaterial->item_material_id,
                    'almacen_origen_id' => $bodega->id,
                    'almacen_destino_id' => $almacenDestino->id,
                    'cantidad' => $cantidad,
                    'saldo_origen_anterior' => $origenAnterior,
                    'saldo_origen_resultante' => $origenResultante,
                    'saldo_destino_anterior' => $destinoAnterior,
                    'saldo_destino_resultante' => $destinoResultante,
                    'centro_costo' => $almacenDestino->centro_costo,
                    'motivo' => 'Entrega de materiales como transferencia interna.',
                    'documento_relacionado' => $despacho->codigo,
                    'despacho_material_id' => $despacho->id,
                    'retiro_material_id' => $retiro->id,
                    'user_id' => $usuario->id,
                    'dispositivo_id' => $dispositivo?->id,
                    'metadatos' => [
                        'siguio_fifo' => $siguioFifo,
                        'motivo_excepcion_fifo' => $siguioFifo ? null : $motivoExcepcionFifo,
                        'camara_origen' => $camara->codigo,
                        'posicion_origen' => $posicion?->etiqueta,
                        'existencia_total_empresa' => (float) $folioMaterial->cantidad_actual,
                        'origen_operacion' => $requiereSesion ? 'tablet' : 'oficina',
                    ],
                    'ocurrido_at' => now(),
                ]);
                MovimientoInventarioMaterial::create([
                    'folio_id' => $folioMaterial->folio_id,
                    'item_material_id' => $folioMaterial->item_material_id,
                    'tipo' => TipoMovimientoInventarioMaterial::TransferenciaInterna,
                    'cantidad' => 0,
                    'cantidad_anterior' => $folioMaterial->cantidad_actual,
                    'cantidad_resultante' => $folioMaterial->cantidad_actual,
                    'despacho_material_id' => $despacho->id,
                    'retiro_material_id' => $retiro->id,
                    'user_id' => $usuario->id,
                    'dispositivo_id' => $dispositivo?->id,
                    'destino_nombre' => $almacenDestino->nombre,
                    'destino_centro_costo' => $almacenDestino->centro_costo,
                    'motivo' => 'Transferencia interna: no disminuye la existencia total.',
                    'metadatos' => [
                        'cantidad_transferida' => $cantidad,
                        'almacen_origen_id' => $bodega->id,
                        'almacen_destino_id' => $almacenDestino->id,
                    ],
                    'ocurrido_at' => now(),
                ]);

                $retiradoPorDetalle[$detalle->id] = $yaRetirado + $cantidad;
                $sesion?->update(['ultima_actividad_at' => now()]);

                if ($origenResultante <= 0.0001) {
                    UbicacionActual::query()
                        ->where('folio_id', $folioMaterial->folio_id)
                        ->delete();
                    $camara->increment('version_plano');
                }

                $folioMaterial->folio->update([
                    'estado_operacional' => EstadoOperacionalFolio::Disponible,
                    'activo' => true,
                ]);
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
            $folio = FolioMaterial::query()
                ->lockForUpdate()
                ->findOrFail($reserva->folio_id);
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

    private function reservarFifo(DetalleDespachoMaterial $detalle): void
    {
        $pendiente = round(
            (float) $detalle->cantidad_solicitada
                - (float) $detalle->cantidad_despachada,
            3,
        );

        if ($pendiente <= 0) {
            $detalle->update(['cantidad_sin_reserva_por_vencimiento' => 0]);

            return;
        }

        $faltante = app(ServicioReservaFifoMaterial::class)->reservar(
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
        if ((float) $detalle->cantidad_sin_reserva_por_vencimiento > 0) {
            $detalle->update(['cantidad_sin_reserva_por_vencimiento' => $faltante]);
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
            throw new DomainException(
                'La sesión no autoriza entregas en la cámara del folio.',
            );
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

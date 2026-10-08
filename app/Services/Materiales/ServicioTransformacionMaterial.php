<?php

namespace App\Services\Materiales;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\EstadoLoteTransformacionMaterial;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoOrdenTransformacionMaterial;
use App\Enums\EstadoReservaMaterial;
use App\Enums\EstadoVersionRecetaMaterial;
use App\Enums\TipoBulto;
use App\Enums\TipoEventoTransformacionMaterial;
use App\Enums\TipoMovimientoInventarioMaterial;
use App\Exceptions\ConflictoOperacion;
use App\Models\Cliente;
use App\Models\ConsumoTransformacionMaterial;
use App\Models\DetalleVersionRecetaMaterial;
use App\Models\Dispositivo;
use App\Models\EventoTransformacionMaterial;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\ItemMaterial;
use App\Models\LoteTransformacionMaterial;
use App\Models\MovimientoInventarioMaterial;
use App\Models\OrdenTransformacionMaterial;
use App\Models\RecetaMaterial;
use App\Models\ReservaTransformacionMaterial;
use App\Models\SalidaTransformacionMaterial;
use App\Models\TrabajoImpresionMaterial;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Models\VersionRecetaMaterial;
use App\Services\Temporadas\ServicioTemporadaActiva;
use BackedEnum;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use JsonException;

class ServicioTransformacionMaterial
{
    public function __construct(
        private readonly ServicioTemporadaActiva $temporadaActiva,
        private readonly ServicioCorrelativoFolioMaterial $correlativoFolio,
        private readonly ServicioReservaFifoMaterial $reservaFifo,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crearReceta(array $datos, User $usuario): RecetaMaterial
    {
        return DB::transaction(function () use ($datos, $usuario): RecetaMaterial {
            $temporada = $this->temporadaActiva->obtener(bloquear: true);
            $cliente = Cliente::query()
                ->whereKey($datos['cliente_id'])
                ->where('activo', true)
                ->lockForUpdate()
                ->firstOrFail();
            $salida = $this->validarItem(
                $datos['item_salida_id'],
                $cliente,
                $temporada->id,
                [
                    CategoriaOperacionalMaterial::MaterialMp,
                    CategoriaOperacionalMaterial::MaterialPt,
                ],
                bloquear: true,
            );
            $componentes = collect($datos['componentes']);

            if ($componentes->where('es_componente_principal', true)->count() !== 1) {
                throw new DomainException('La receta debe tener exactamente un componente principal.');
            }

            $receta = RecetaMaterial::create([
                'temporada_id' => $temporada->id,
                'cliente_id' => $cliente->id,
                'item_salida_id' => $salida->id,
                'nombre' => trim($datos['nombre']),
                'activa' => true,
                'creado_por_user_id' => $usuario->id,
                'actualizado_por_user_id' => $usuario->id,
            ]);
            $version = VersionRecetaMaterial::create([
                'receta_material_id' => $receta->id,
                'numero_version' => 1,
                'estado' => EstadoVersionRecetaMaterial::Activa,
                'cantidad_base_salida' => $this->cantidad($datos['cantidad_base_salida']),
                'unidades_por_folio_salida' => isset($datos['unidades_por_folio_salida'])
                    ? $this->cantidad($datos['unidades_por_folio_salida'])
                    : null,
                'unidad_medida_salida' => $salida->unidad_medida,
                'creado_por_user_id' => $usuario->id,
                'activado_at' => now(),
            ]);
            $detallesSnapshot = [];
            $salidaMpComoPrincipal = false;

            foreach ($componentes as $componente) {
                $item = $this->validarItem(
                    $componente['item_entrada_id'],
                    $cliente,
                    $temporada->id,
                    [
                        CategoriaOperacionalMaterial::Insumo,
                        CategoriaOperacionalMaterial::MaterialMp,
                    ],
                    bloquear: true,
                );

                if ($item->id === $salida->id) {
                    if ($salida->categoria_operacional !== CategoriaOperacionalMaterial::MaterialMp
                        || ! (bool) $componente['es_componente_principal']) {
                        throw new DomainException(
                            'Un ítem solo puede conservar su código al pasar de Material MP a Material PT y debe ser el componente principal.',
                        );
                    }

                    $salidaMpComoPrincipal = true;
                }

                $detalle = DetalleVersionRecetaMaterial::create([
                    'version_receta_material_id' => $version->id,
                    'item_entrada_id' => $item->id,
                    'cantidad_estandar' => $this->cantidad($componente['cantidad_estandar']),
                    'unidad_medida' => $item->unidad_medida,
                    'es_componente_principal' => (bool) $componente['es_componente_principal'],
                    'factor_conversion' => $this->cantidad($componente['factor_conversion'] ?? 1),
                    'merma_estandar_porcentaje' => $this->porcentaje(
                        $componente['merma_estandar_porcentaje'] ?? 0,
                    ),
                    'tolerancia_porcentaje' => $this->porcentaje(
                        $componente['tolerancia_porcentaje'] ?? 0,
                    ),
                ]);
                $detallesSnapshot[] = [
                    'id' => $detalle->id,
                    'item_id' => $item->id,
                    'codigo' => $item->codigo,
                    'nombre' => $item->nombre,
                    'categoria_operacional' => $item->categoria_operacional->value,
                    'cantidad_estandar' => $detalle->cantidad_estandar,
                    'unidad_medida' => $detalle->unidad_medida,
                    'es_componente_principal' => $detalle->es_componente_principal,
                    'factor_conversion' => $detalle->factor_conversion,
                    'merma_estandar_porcentaje' => $detalle->merma_estandar_porcentaje,
                    'tolerancia_porcentaje' => $detalle->tolerancia_porcentaje,
                ];
            }

            if ($salida->categoria_operacional === CategoriaOperacionalMaterial::MaterialMp
                && ! $salidaMpComoPrincipal) {
                throw new DomainException(
                    'Para transformar un Material MP con el mismo código, ese ítem debe ser la salida y el componente principal de la receta.',
                );
            }

            $version->update(['snapshot' => [
                'receta' => [
                    'id' => $receta->id,
                    'nombre' => $receta->nombre,
                ],
                'cliente' => [
                    'id' => $cliente->id,
                    'codigo' => $cliente->codigo,
                    'nombre' => $cliente->nombre,
                ],
                'salida' => [
                    'item_id' => $salida->id,
                    'codigo' => $salida->codigo,
                    'nombre' => $salida->nombre,
                    'cantidad_base' => $version->cantidad_base_salida,
                    'unidades_por_folio' => $version->unidades_por_folio_salida,
                    'unidad_medida' => $salida->unidad_medida,
                    'categoria_operacional' => CategoriaOperacionalMaterial::MaterialPt->value,
                    'categoria_item_origen' => $salida->categoria_operacional->value,
                ],
                'componentes' => $detallesSnapshot,
            ]]);

            return $this->cargarReceta($receta->refresh());
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crearOrden(array $datos, User $usuario): OrdenTransformacionMaterial
    {
        $payloadHash = $this->payloadHash($datos);

        return DB::transaction(function () use ($datos, $usuario, $payloadHash): OrdenTransformacionMaterial {
            $existente = OrdenTransformacionMaterial::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->lockForUpdate()
                ->first();

            if ($existente) {
                if ($existente->creado_por_user_id !== $usuario->id
                    || ! hash_equals($existente->payload_hash, $payloadHash)) {
                    throw new ConflictoOperacion(
                        'El UUID de creación de la orden ya fue utilizado con datos diferentes.',
                    );
                }

                return $this->cargarOrden($existente);
            }

            $temporada = $this->temporadaActiva->obtener(bloquear: true);
            $version = VersionRecetaMaterial::query()
                ->with(['receta.itemSalida', 'receta.cliente', 'detalles.itemEntrada'])
                ->whereKey($datos['version_receta_material_id'])
                ->where('estado', EstadoVersionRecetaMaterial::Activa->value)
                ->lockForUpdate()
                ->firstOrFail();
            $receta = $version->receta;

            if (! $receta->activa || $receta->temporada_id !== $temporada->id) {
                throw new DomainException('La receta no pertenece a la temporada global activa.');
            }

            $orden = OrdenTransformacionMaterial::create([
                'operacion_id' => $datos['operacion_id'],
                'payload_hash' => $payloadHash,
                'temporada_id' => $temporada->id,
                'cliente_id' => $receta->cliente_id,
                'version_receta_material_id' => $version->id,
                'estado' => EstadoOrdenTransformacionMaterial::Borrador,
                'cantidad_planificada_salida' => $this->cantidad($datos['cantidad_planificada_salida']),
                'linea' => $this->textoOpcional($datos['linea'] ?? null),
                'turno' => $this->textoOpcional($datos['turno'] ?? null),
                'fecha_operacional' => $datos['fecha_operacional'],
                'version' => 1,
                'snapshot_receta' => $version->snapshot,
                'observacion' => $this->textoOpcional($datos['observacion'] ?? null),
                'creado_por_user_id' => $usuario->id,
            ]);
            $this->registrarEvento(
                $orden,
                TipoEventoTransformacionMaterial::Creada,
                $usuario,
                $datos['operacion_id'],
                ['cantidad_planificada_salida' => $orden->cantidad_planificada_salida],
            );

            return $this->cargarOrden($orden->refresh());
        }, attempts: 3);
    }

    public function planificar(
        OrdenTransformacionMaterial $orden,
        string $operacionId,
        int $versionConocida,
        User $usuario,
    ): OrdenTransformacionMaterial {
        return DB::transaction(function () use (
            $orden,
            $operacionId,
            $versionConocida,
            $usuario,
        ): OrdenTransformacionMaterial {
            $eventoExistente = EventoTransformacionMaterial::query()
                ->where('operacion_id', $operacionId)
                ->lockForUpdate()
                ->first();

            if ($eventoExistente) {
                if ($eventoExistente->orden_transformacion_material_id !== $orden->id
                    || $eventoExistente->tipo !== TipoEventoTransformacionMaterial::Planificada
                    || $eventoExistente->user_id !== $usuario->id
                    || (int) data_get($eventoExistente->datos, 'version_conocida') !== $versionConocida) {
                    throw new ConflictoOperacion(
                        'El UUID de planificación ya fue utilizado por otra operación.',
                    );
                }

                return $this->cargarOrden($orden->refresh());
            }

            $temporada = $this->temporadaActiva->obtener(bloquear: true);
            $orden = OrdenTransformacionMaterial::query()
                ->lockForUpdate()
                ->findOrFail($orden->id);

            if ($orden->temporada_id !== $temporada->id) {
                throw new DomainException(
                    'La orden pertenece a una temporada histórica y no puede planificarse.',
                );
            }

            if ($orden->estado !== EstadoOrdenTransformacionMaterial::Borrador) {
                throw new DomainException('La orden ya no se encuentra en borrador.');
            }

            if ($orden->version !== $versionConocida) {
                throw new ConflictoOperacion('La orden cambió desde la última lectura.');
            }

            $snapshot = $orden->snapshot_receta;
            $cantidadBaseSalida = round((float) data_get($snapshot, 'salida.cantidad_base'), 3);
            $componentes = data_get($snapshot, 'componentes');

            if ($cantidadBaseSalida <= 0 || ! is_array($componentes) || $componentes === []) {
                throw new DomainException('El snapshot de receta de la orden no es válido.');
            }

            $requerimientos = [];

            foreach ($componentes as $componente) {
                $itemId = (string) ($componente['item_id'] ?? '');
                $codigo = trim((string) ($componente['codigo'] ?? ''));
                $unidadMedida = trim((string) ($componente['unidad_medida'] ?? ''));
                $cantidadEstandar = round((float) ($componente['cantidad_estandar'] ?? 0), 3);

                if ($itemId === '' || $codigo === '' || $unidadMedida === '' || $cantidadEstandar <= 0) {
                    throw new DomainException('El snapshot de receta contiene un componente inválido.');
                }

                $requerido = round(
                    $cantidadEstandar
                    * (float) $orden->cantidad_planificada_salida
                    / $cantidadBaseSalida,
                    3,
                );
                if ($requerido <= 0) {
                    throw new DomainException(sprintf(
                        'La cantidad requerida para el ítem %s es inferior a la precisión operacional.',
                        $codigo,
                    ));
                }

                $pendiente = $this->reservaFifo->reservar(
                    $itemId,
                    $requerido,
                    function (
                        FolioMaterial $folio,
                        float $cantidad,
                        int $ordenFifo,
                    ) use ($orden, $itemId): void {
                        ReservaTransformacionMaterial::create([
                            'orden_transformacion_material_id' => $orden->id,
                            'folio_id' => $folio->folio_id,
                            'item_material_id' => $itemId,
                            'cantidad' => $cantidad,
                            'estado' => EstadoReservaMaterial::Activa,
                            'orden_fifo' => $ordenFifo,
                        ]);
                    },
                    isset($componente['categoria_operacional'])
                        ? [(string) $componente['categoria_operacional']]
                        : null,
                );

                if ($pendiente > 0.0001) {
                    throw new DomainException(sprintf(
                        'No existe saldo disponible suficiente para el ítem %s. Faltan %.3f %s.',
                        $codigo,
                        $pendiente,
                        $unidadMedida,
                    ));
                }

                $requerimientos[] = [
                    'item_material_id' => $itemId,
                    'codigo' => $codigo,
                    'cantidad_requerida' => number_format($requerido, 3, '.', ''),
                    'unidad_medida' => $unidadMedida,
                ];
            }

            $orden->update([
                'estado' => EstadoOrdenTransformacionMaterial::Planificada,
                'version' => $orden->version + 1,
            ]);
            $this->registrarEvento(
                $orden,
                TipoEventoTransformacionMaterial::Planificada,
                $usuario,
                $operacionId,
                [
                    'version_conocida' => $versionConocida,
                    'requerimientos' => $requerimientos,
                ],
            );

            return $this->cargarOrden($orden->refresh());
        }, attempts: 3);
    }

    public function cancelar(
        OrdenTransformacionMaterial $orden,
        string $operacionId,
        string $motivo,
        User $usuario,
    ): OrdenTransformacionMaterial {
        $motivo = trim($motivo);

        return DB::transaction(function () use ($orden, $operacionId, $motivo, $usuario): OrdenTransformacionMaterial {
            $eventoExistente = EventoTransformacionMaterial::query()
                ->where('operacion_id', $operacionId)
                ->lockForUpdate()
                ->first();

            if ($eventoExistente) {
                if ($eventoExistente->orden_transformacion_material_id !== $orden->id
                    || $eventoExistente->tipo !== TipoEventoTransformacionMaterial::Cancelada
                    || $eventoExistente->user_id !== $usuario->id
                    || $eventoExistente->observacion !== $motivo) {
                    throw new ConflictoOperacion(
                        'El UUID de cancelación ya fue utilizado por otra operación.',
                    );
                }

                return $this->cargarOrden($orden->refresh());
            }

            $orden = OrdenTransformacionMaterial::query()
                ->lockForUpdate()
                ->findOrFail($orden->id);
            $estadoAnterior = $orden->estado;

            if (! in_array($estadoAnterior, [
                EstadoOrdenTransformacionMaterial::Borrador,
                EstadoOrdenTransformacionMaterial::Planificada,
                EstadoOrdenTransformacionMaterial::EnProceso,
            ], true)) {
                throw new DomainException('La orden ya no puede cancelarse sin movimientos compensatorios.');
            }

            $lotes = LoteTransformacionMaterial::query()
                ->where('orden_transformacion_material_id', $orden->id)
                ->lockForUpdate()
                ->get();

            if ($lotes->contains(
                fn (LoteTransformacionMaterial $lote): bool => $lote->estado
                    === EstadoLoteTransformacionMaterial::Cerrado,
            )) {
                throw new DomainException(
                    'La orden posee lotes cerrados. Revierta primero sus movimientos antes de cancelarla.',
                );
            }

            $ahora = now();
            $lotesAbiertos = $lotes->filter(
                fn (LoteTransformacionMaterial $lote): bool => $lote->estado
                    === EstadoLoteTransformacionMaterial::Abierto,
            );

            foreach ($lotesAbiertos as $lote) {
                $lote->update([
                    'estado' => EstadoLoteTransformacionMaterial::Anulado,
                    'reversado_por_user_id' => $usuario->id,
                    'reversado_at' => $ahora,
                    'motivo_reversa' => "Cancelación de orden: {$motivo}",
                ]);
            }

            $reservas = ReservaTransformacionMaterial::query()
                ->where('orden_transformacion_material_id', $orden->id)
                ->where('estado', EstadoReservaMaterial::Activa->value)
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

            $orden->update([
                'estado' => EstadoOrdenTransformacionMaterial::Cancelada,
                'version' => $orden->version + 1,
                'cancelado_por_user_id' => $usuario->id,
                'cancelado_at' => $ahora,
                'motivo_cancelacion' => $motivo,
            ]);
            $this->registrarEvento(
                $orden,
                TipoEventoTransformacionMaterial::Cancelada,
                $usuario,
                $operacionId,
                [
                    'estado_anterior' => $estadoAnterior->value,
                    'reservas_liberadas' => $reservas->count(),
                    'lotes_abiertos_descartados' => $lotesAbiertos->count(),
                ],
                $motivo,
            );

            return $this->cargarOrden($orden->refresh());
        }, attempts: 3);
    }

    public function iniciar(
        OrdenTransformacionMaterial $orden,
        string $operacionId,
        int $versionConocida,
        User $usuario,
        ?Dispositivo $dispositivo,
    ): OrdenTransformacionMaterial {
        $datosOperacion = [
            'operacion_id' => $operacionId,
            'version_conocida' => $versionConocida,
        ];
        $payloadHash = $this->payloadHash($datosOperacion);

        return DB::transaction(function () use (
            $orden,
            $operacionId,
            $versionConocida,
            $usuario,
            $dispositivo,
            $payloadHash,
        ): OrdenTransformacionMaterial {
            if ($this->eventoYaProcesado(
                $orden,
                $operacionId,
                TipoEventoTransformacionMaterial::Iniciada,
                $usuario,
                $dispositivo,
                $payloadHash,
            )) {
                return $this->cargarOrden($orden->refresh());
            }

            $orden = OrdenTransformacionMaterial::query()
                ->lockForUpdate()
                ->findOrFail($orden->id);
            $this->validarVersion($orden, $versionConocida);

            if ($orden->estado !== EstadoOrdenTransformacionMaterial::Planificada) {
                throw new DomainException('Solo una orden planificada puede iniciar su ejecución.');
            }

            if (! ReservaTransformacionMaterial::query()
                ->where('orden_transformacion_material_id', $orden->id)
                ->where('estado', EstadoReservaMaterial::Activa->value)
                ->exists()) {
                throw new DomainException('La orden no posee reservas activas para iniciar.');
            }

            $orden->update([
                'estado' => EstadoOrdenTransformacionMaterial::EnProceso,
                'version' => $orden->version + 1,
                'iniciado_por_user_id' => $usuario->id,
                'iniciado_at' => now(),
            ]);
            $this->registrarEvento(
                $orden,
                TipoEventoTransformacionMaterial::Iniciada,
                $usuario,
                $operacionId,
                [
                    'version_conocida' => $versionConocida,
                    'payload_hash' => $payloadHash,
                ],
                dispositivo: $dispositivo,
            );

            return $this->cargarOrden($orden->refresh());
        }, attempts: 3);
    }

    public function abrirLote(
        OrdenTransformacionMaterial $orden,
        string $operacionId,
        int $versionConocida,
        float $cantidadPlanificada,
        User $usuario,
        ?Dispositivo $dispositivo,
    ): OrdenTransformacionMaterial {
        $cantidadPlanificada = $this->cantidad($cantidadPlanificada);
        $datosOperacion = [
            'operacion_id' => $operacionId,
            'version_conocida' => $versionConocida,
            'cantidad_planificada_salida' => $cantidadPlanificada,
        ];
        $payloadHash = $this->payloadHash($datosOperacion);

        return DB::transaction(function () use (
            $orden,
            $operacionId,
            $versionConocida,
            $cantidadPlanificada,
            $usuario,
            $dispositivo,
            $payloadHash,
        ): OrdenTransformacionMaterial {
            if ($this->eventoYaProcesado(
                $orden,
                $operacionId,
                TipoEventoTransformacionMaterial::LoteAbierto,
                $usuario,
                $dispositivo,
                $payloadHash,
            )) {
                return $this->cargarOrden($orden->refresh());
            }

            $orden = OrdenTransformacionMaterial::query()
                ->lockForUpdate()
                ->findOrFail($orden->id);
            $this->validarVersion($orden, $versionConocida);

            if ($orden->estado !== EstadoOrdenTransformacionMaterial::EnProceso) {
                throw new DomainException('La orden no se encuentra disponible para abrir un lote.');
            }

            $lotes = LoteTransformacionMaterial::query()
                ->where('orden_transformacion_material_id', $orden->id)
                ->lockForUpdate()
                ->get();

            if ($lotes->contains(
                fn (LoteTransformacionMaterial $lote): bool => $lote->estado === EstadoLoteTransformacionMaterial::Abierto,
            )) {
                throw new DomainException('La orden ya posee un lote abierto.');
            }

            $planificadoAnterior = round($lotes
                ->reject(
                    fn (LoteTransformacionMaterial $lote): bool => $lote->estado === EstadoLoteTransformacionMaterial::Anulado,
                )
                ->sum(fn (LoteTransformacionMaterial $lote): float => (float) $lote->cantidad_planificada_salida), 3);
            $unidadesPorFolio = data_get($orden->snapshot_receta, 'salida.unidades_por_folio');

            if ($unidadesPorFolio !== null) {
                $unidadesPorFolio = $this->cantidad($unidadesPorFolio);
                $restantePlanificado = round(
                    (float) $orden->cantidad_planificada_salida - $planificadoAnterior,
                    3,
                );
                $cantidadEsperada = min($unidadesPorFolio, $restantePlanificado);

                if ($cantidadEsperada <= 0
                    || abs($cantidadPlanificada - $cantidadEsperada) > 0.0001) {
                    throw new DomainException(sprintf(
                        'El siguiente folio debe planificarse con %.3f unidades de salida.',
                        max(0, $cantidadEsperada),
                    ));
                }
            }

            $totalPlanificado = round($planificadoAnterior + $cantidadPlanificada, 3);

            if ($totalPlanificado - (float) $orden->cantidad_planificada_salida > 0.0001) {
                throw new DomainException('La suma de lotes supera la salida planificada de la orden.');
            }

            $numeroLote = ((int) $lotes->max('numero_lote')) + 1;
            $lote = LoteTransformacionMaterial::create([
                'orden_transformacion_material_id' => $orden->id,
                'numero_lote' => $numeroLote,
                'estado' => EstadoLoteTransformacionMaterial::Abierto,
                'cantidad_planificada_salida' => $cantidadPlanificada,
                'iniciado_por_user_id' => $usuario->id,
                'iniciado_at' => now(),
            ]);
            $orden->update(['version' => $orden->version + 1]);
            $this->registrarEvento(
                $orden,
                TipoEventoTransformacionMaterial::LoteAbierto,
                $usuario,
                $operacionId,
                [
                    'version_conocida' => $versionConocida,
                    'payload_hash' => $payloadHash,
                    'lote_id' => $lote->id,
                    'numero_lote' => $numeroLote,
                    'cantidad_planificada_salida' => $lote->cantidad_planificada_salida,
                ],
                dispositivo: $dispositivo,
            );

            return $this->cargarOrden($orden->refresh());
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function cerrarLote(
        LoteTransformacionMaterial $lote,
        array $datos,
        User $usuario,
        ?Dispositivo $dispositivo,
    ): OrdenTransformacionMaterial {
        $payloadHash = $this->payloadHash($datos);
        $operacionId = (string) $datos['operacion_id'];
        $versionConocida = (int) $datos['version_conocida'];
        $cantidadRealSalida = $this->cantidad($datos['cantidad_real_salida']);

        return DB::transaction(function () use (
            $lote,
            $datos,
            $usuario,
            $dispositivo,
            $payloadHash,
            $operacionId,
            $versionConocida,
            $cantidadRealSalida,
        ): OrdenTransformacionMaterial {
            $lote = LoteTransformacionMaterial::query()
                ->lockForUpdate()
                ->findOrFail($lote->id);
            $orden = OrdenTransformacionMaterial::query()
                ->lockForUpdate()
                ->findOrFail($lote->orden_transformacion_material_id);

            if ($this->eventoYaProcesado(
                $orden,
                $operacionId,
                TipoEventoTransformacionMaterial::LoteCerrado,
                $usuario,
                $dispositivo,
                $payloadHash,
            )) {
                return $this->cargarOrden($orden->refresh());
            }

            $this->validarVersion($orden, $versionConocida);

            if ($orden->estado !== EstadoOrdenTransformacionMaterial::EnProceso
                || $lote->estado !== EstadoLoteTransformacionMaterial::Abierto) {
                throw new DomainException('El lote ya no se encuentra abierto para registrar consumos.');
            }

            $unidadesPorFolio = data_get($orden->snapshot_receta, 'salida.unidades_por_folio');

            if ($unidadesPorFolio !== null) {
                $maximoSalida = min(
                    $this->cantidad($unidadesPorFolio),
                    (float) $lote->cantidad_planificada_salida,
                );

                if ($cantidadRealSalida - $maximoSalida > 0.0001) {
                    throw new DomainException(sprintf(
                        'Un folio de salida no puede superar %.3f unidades.',
                        $maximoSalida,
                    ));
                }
            }

            $componentes = collect(data_get($orden->snapshot_receta, 'componentes', []));
            $principal = $componentes->firstWhere('es_componente_principal', true);

            if (! is_array($principal) || $componentes->isEmpty()) {
                throw new DomainException('El snapshot de receta no permite calcular la transformación.');
            }

            $lineas = collect($datos['consumos']);
            foreach (FolioMaterial::query()->whereIn('folio_id', $lineas->pluck('folio_id'))->lockForUpdate()->get() as $materialConsumido) {
                $materialConsumido->asegurarVigente();
            }
            $idsComponentes = $componentes
                ->pluck('item_id')
                ->filter()
                ->map(fn ($id): string => (string) $id);
            $componentesPorItem = $componentes->keyBy(
                fn (array $componente): string => (string) ($componente['item_id'] ?? ''),
            );
            $reservas = ReservaTransformacionMaterial::query()
                ->where('orden_transformacion_material_id', $orden->id)
                ->where('estado', EstadoReservaMaterial::Activa->value)
                ->orderBy('item_material_id')
                ->orderBy('orden_fifo')
                ->lockForUpdate()
                ->get();
            $reservasPorFolio = $reservas->keyBy('folio_id');
            $consumosPreparados = [];

            foreach ($lineas->groupBy(
                fn (array $linea): string => (string) ($reservasPorFolio->get($linea['folio_id'])?->item_material_id ?? ''),
            ) as $itemId => $lineasItem) {
                if ($itemId === '' || ! $idsComponentes->contains($itemId)) {
                    throw new DomainException('Uno de los folios no está reservado para un componente de la orden.');
                }

                $reservasItem = $reservas->where('item_material_id', $itemId)->values();
                $solicitado = round($lineasItem->sum(
                    fn (array $linea): float => $this->cantidad($linea['cantidad']),
                ), 3);
                $disponibleReservado = round($reservasItem->sum(
                    fn (ReservaTransformacionMaterial $reserva): float => max(
                        0,
                        round((float) $reserva->cantidad - (float) $reserva->cantidad_consumida, 3),
                    ),
                ), 3);

                if ($solicitado - $disponibleReservado > 0.0001) {
                    throw new DomainException('El consumo supera la cantidad reservada para uno de los ítems.');
                }

                $pendienteFifo = $solicitado;
                $esperadoPorFolio = [];

                foreach ($reservasItem as $reserva) {
                    $restante = max(
                        0,
                        round((float) $reserva->cantidad - (float) $reserva->cantidad_consumida, 3),
                    );
                    $esperado = min($pendienteFifo, $restante);
                    $esperadoPorFolio[$reserva->folio_id] = $esperado;
                    $pendienteFifo = round($pendienteFifo - $esperado, 3);
                }

                foreach ($lineasItem as $linea) {
                    $reserva = $reservasPorFolio->get($linea['folio_id']);

                    if (! $reserva) {
                        throw new DomainException('El folio seleccionado no posee una reserva activa en la orden.');
                    }

                    $cantidad = $this->cantidad($linea['cantidad']);
                    $restanteReserva = round(
                        (float) $reserva->cantidad - (float) $reserva->cantidad_consumida,
                        3,
                    );

                    if ($cantidad - $restanteReserva > 0.0001) {
                        throw new DomainException('El consumo supera el saldo reservado del folio.');
                    }

                    $siguioFifo = $cantidad - ($esperadoPorFolio[$reserva->folio_id] ?? 0) <= 0.0001;
                    $motivoDesviacion = $this->textoOpcional($linea['motivo_desviacion_fifo'] ?? null);

                    if (! $siguioFifo && mb_strlen((string) $motivoDesviacion) < 5) {
                        throw new DomainException(
                            'Debe indicar el motivo al consumir un folio fuera del orden FIFO.',
                        );
                    }

                    $consumosPreparados[] = [
                        'reserva' => $reserva,
                        'cantidad' => $cantidad,
                        'siguio_fifo' => $siguioFifo,
                        'motivo_desviacion_fifo' => $siguioFifo ? null : $motivoDesviacion,
                    ];
                }
            }

            $itemsConsumidos = collect($consumosPreparados)
                ->map(fn (array $consumo): string => (string) $consumo['reserva']->item_material_id)
                ->unique();

            if ($idsComponentes->diff($itemsConsumidos)->isNotEmpty()) {
                throw new DomainException('Debe registrar consumo real para cada componente de la receta.');
            }

            $ahora = now();

            foreach ($consumosPreparados as $consumoPreparado) {
                /** @var ReservaTransformacionMaterial $reserva */
                $reserva = $consumoPreparado['reserva'];
                $folioMaterial = FolioMaterial::query()
                    ->with('folio.ubicacionActual.camara', 'folio.ubicacionActual.posicion')
                    ->lockForUpdate()
                    ->findOrFail($reserva->folio_id);
                $folio = $folioMaterial->folio;
                $categoriaEsperada = (string) data_get(
                    $componentesPorItem->get((string) $reserva->item_material_id),
                    'categoria_operacional',
                    '',
                );

                $folioMaterial->asegurarVigente();

                if (! $folio->activo
                    || $folio->estado_operacional !== EstadoOperacionalFolio::Disponible
                    || $folioMaterial->motivo_bloqueo !== null) {
                    throw new DomainException(sprintf(
                        'El folio %s ya no está disponible para transformación.',
                        $folio->numero_folio,
                    ));
                }

                if ($categoriaEsperada !== ''
                    && $folioMaterial->categoria_operacional?->value !== $categoriaEsperada) {
                    throw new DomainException(sprintf(
                        'El folio %s no conserva la condición MP o insumo exigida por la receta.',
                        $folio->numero_folio,
                    ));
                }

                $cantidad = $consumoPreparado['cantidad'];
                $cantidadAnterior = round((float) $folioMaterial->cantidad_actual, 3);
                $cantidadReservada = round((float) $folioMaterial->cantidad_reservada, 3);

                if ($cantidad - $cantidadAnterior > 0.0001 || $cantidad - $cantidadReservada > 0.0001) {
                    throw new DomainException(sprintf(
                        'El folio %s no posee saldo suficiente para el consumo.',
                        $folio->numero_folio,
                    ));
                }

                $cantidadResultante = max(0, round($cantidadAnterior - $cantidad, 3));
                $reservaConsumida = round((float) $reserva->cantidad_consumida + $cantidad, 3);
                $reservaCompleta = abs($reservaConsumida - (float) $reserva->cantidad) <= 0.0001;
                $ubicacion = $folio->ubicacionActual;
                $camara = $ubicacion?->camara ?? $ubicacion?->posicion?->camara;
                $metadatosUbicacion = $camara ? [
                    'camara' => $camara->codigo,
                    'posicion' => $ubicacion?->posicion?->etiqueta,
                ] : [];

                $folioMaterial->update([
                    'cantidad_actual' => $cantidadResultante,
                    'cantidad_reservada' => max(0, round($cantidadReservada - $cantidad, 3)),
                ]);
                $reserva->update([
                    'cantidad_consumida' => $reservaConsumida,
                    'estado' => $reservaCompleta
                        ? EstadoReservaMaterial::Consumida
                        : EstadoReservaMaterial::Activa,
                ]);
                ConsumoTransformacionMaterial::create([
                    'lote_transformacion_material_id' => $lote->id,
                    'folio_id' => $folio->id,
                    'item_material_id' => $folioMaterial->item_material_id,
                    'cantidad_consumida' => $cantidad,
                    'cantidad_anterior' => $cantidadAnterior,
                    'cantidad_resultante' => $cantidadResultante,
                    'siguio_fifo' => $consumoPreparado['siguio_fifo'],
                    'motivo_desviacion_fifo' => $consumoPreparado['motivo_desviacion_fifo'],
                    'user_id' => $usuario->id,
                    'dispositivo_id' => $dispositivo?->id,
                    'ocurrido_at' => $ahora,
                ]);
                MovimientoInventarioMaterial::create([
                    'folio_id' => $folio->id,
                    'item_material_id' => $folioMaterial->item_material_id,
                    'tipo' => TipoMovimientoInventarioMaterial::ConsumoTransformacion,
                    'cantidad' => -$cantidad,
                    'cantidad_anterior' => $cantidadAnterior,
                    'cantidad_resultante' => $cantidadResultante,
                    'orden_transformacion_material_id' => $orden->id,
                    'lote_transformacion_material_id' => $lote->id,
                    'user_id' => $usuario->id,
                    'dispositivo_id' => $dispositivo?->id,
                    'motivo' => 'Consumo real en transformación de materiales.',
                    'metadatos' => [
                        'siguio_fifo' => $consumoPreparado['siguio_fifo'],
                        'motivo_desviacion_fifo' => $consumoPreparado['motivo_desviacion_fifo'],
                        ...$metadatosUbicacion,
                    ],
                    'ocurrido_at' => $ahora,
                ]);

                if ($cantidadResultante <= 0.0001) {
                    if ($ubicacion) {
                        UbicacionActual::query()->whereKey($ubicacion->id)->delete();
                        $camara?->increment('version_plano');
                    }
                    $folio->update([
                        'estado_operacional' => EstadoOperacionalFolio::RetiradoDefinitivo,
                        'activo' => false,
                    ]);
                }
            }

            $itemPrincipalId = (string) $principal['item_id'];
            $consumoPrincipal = round(collect($consumosPreparados)
                ->filter(fn (array $consumo): bool => $consumo['reserva']->item_material_id === $itemPrincipalId)
                ->sum(fn (array $consumo): float => (float) $consumo['cantidad']), 3);
            $factorConversion = round((float) ($principal['factor_conversion'] ?? 1), 6);
            $porcentajeMerma = round((float) ($principal['merma_estandar_porcentaje'] ?? 0), 4);
            $salidaTeorica = round($consumoPrincipal * $factorConversion, 3);
            $mermaEstandar = round($salidaTeorica * $porcentajeMerma / 100, 3);
            $mermaReal = round($salidaTeorica - $cantidadRealSalida, 3);
            $desviacionMerma = round($mermaReal - $mermaEstandar, 3);
            $cliente = Cliente::query()->lockForUpdate()->findOrFail($orden->cliente_id);
            $itemSalida = ItemMaterial::query()
                ->lockForUpdate()
                ->findOrFail((string) data_get($orden->snapshot_receta, 'salida.item_id'));
            $codigoFolio = $this->correlativoFolio->siguiente($cliente);
            $folioSalida = Folio::create([
                'temporada_id' => $orden->temporada_id,
                'numero_folio' => $codigoFolio,
                'tipo_bulto' => TipoBulto::Material,
                'estado_operacional' => EstadoOperacionalFolio::PendienteUbicacion,
                'fecha_ingreso' => $ahora,
                'activo' => true,
                'origen_sistema' => 'transformacion_materiales',
                'identificador_externo' => $lote->id,
                'datos_externos' => [
                    'orden_transformacion_material_id' => $orden->id,
                    'lote_transformacion_material_id' => $lote->id,
                    'numero_lote' => $lote->numero_lote,
                    'cliente' => [
                        'id' => $cliente->id,
                        'codigo' => $cliente->codigo,
                        'nombre' => $cliente->nombre,
                    ],
                ],
            ]);
            FolioMaterial::create([
                'folio_id' => $folioSalida->id,
                'item_material_id' => $itemSalida->id,
                'lote_transformacion_origen_id' => $lote->id,
                'categoria_operacional' => CategoriaOperacionalMaterial::MaterialPt,
                'cantidad_inicial' => $cantidadRealSalida,
                'cantidad_actual' => $cantidadRealSalida,
                'cantidad_reservada' => 0,
                'unidad_medida' => $itemSalida->unidad_medida,
                'lote' => sprintf('TR-%s-%03d', $orden->fecha_operacional->format('ymd'), $lote->numero_lote),
                'observacion' => 'Salida generada por transformación de materiales.',
            ]);
            SalidaTransformacionMaterial::create([
                'lote_transformacion_material_id' => $lote->id,
                'folio_id' => $folioSalida->id,
                'item_material_id' => $itemSalida->id,
                'cantidad_producida' => $cantidadRealSalida,
                'es_salida_principal' => true,
            ]);
            MovimientoInventarioMaterial::create([
                'folio_id' => $folioSalida->id,
                'item_material_id' => $itemSalida->id,
                'tipo' => TipoMovimientoInventarioMaterial::ProduccionTransformacion,
                'cantidad' => $cantidadRealSalida,
                'cantidad_anterior' => 0,
                'cantidad_resultante' => $cantidadRealSalida,
                'orden_transformacion_material_id' => $orden->id,
                'lote_transformacion_material_id' => $lote->id,
                'user_id' => $usuario->id,
                'dispositivo_id' => $dispositivo?->id,
                'motivo' => 'Salida producida por transformación de materiales.',
                'metadatos' => [
                    'numero_lote' => $lote->numero_lote,
                    'categoria_operacional' => CategoriaOperacionalMaterial::MaterialPt->value,
                    'estado_ubicacion' => 'pendiente_ubicacion',
                    'salida_teorica' => $salidaTeorica,
                    'merma_estandar' => $mermaEstandar,
                    'merma_real' => $mermaReal,
                    'desviacion_merma' => $desviacionMerma,
                ],
                'ocurrido_at' => $ahora,
            ]);

            $lote->update([
                'estado' => EstadoLoteTransformacionMaterial::Cerrado,
                'cantidad_real_salida' => $cantidadRealSalida,
                'salida_teorica' => $salidaTeorica,
                'merma_estandar' => $mermaEstandar,
                'merma_real' => $mermaReal,
                'desviacion_merma' => $desviacionMerma,
                'cerrado_por_user_id' => $usuario->id,
                'cerrado_at' => $ahora,
            ]);
            $cantidadRealOrden = round(LoteTransformacionMaterial::query()
                ->where('orden_transformacion_material_id', $orden->id)
                ->where('estado', EstadoLoteTransformacionMaterial::Cerrado->value)
                ->sum('cantidad_real_salida'), 3);
            $orden->update([
                'cantidad_real_salida' => $cantidadRealOrden,
                'estado' => $cantidadRealOrden + 0.0001 >= (float) $orden->cantidad_planificada_salida
                    ? EstadoOrdenTransformacionMaterial::PendienteCierre
                    : EstadoOrdenTransformacionMaterial::EnProceso,
                'version' => $orden->version + 1,
            ]);
            $this->registrarEvento(
                $orden,
                TipoEventoTransformacionMaterial::LoteCerrado,
                $usuario,
                $operacionId,
                [
                    'version_conocida' => $versionConocida,
                    'payload_hash' => $payloadHash,
                    'lote_id' => $lote->id,
                    'numero_lote' => $lote->numero_lote,
                    'folio_salida' => $codigoFolio,
                    'cantidad_real_salida' => $cantidadRealSalida,
                    'salida_teorica' => $salidaTeorica,
                    'merma_estandar' => $mermaEstandar,
                    'merma_real' => $mermaReal,
                    'desviacion_merma' => $desviacionMerma,
                ],
                dispositivo: $dispositivo,
            );

            return $this->cargarOrden($orden->refresh());
        }, attempts: 3);
    }

    public function revertirLote(
        LoteTransformacionMaterial $lote,
        string $operacionId,
        int $versionConocida,
        string $motivo,
        User $usuario,
        ?Dispositivo $dispositivo,
    ): OrdenTransformacionMaterial {
        $motivo = trim($motivo);

        if (mb_strlen($motivo) < 5) {
            throw new DomainException('Debe indicar un motivo suficiente para revertir el lote.');
        }

        $datosOperacion = [
            'operacion_id' => $operacionId,
            'version_conocida' => $versionConocida,
            'motivo' => $motivo,
        ];
        $payloadHash = $this->payloadHash($datosOperacion);

        return DB::transaction(function () use (
            $lote,
            $operacionId,
            $versionConocida,
            $motivo,
            $usuario,
            $dispositivo,
            $payloadHash,
        ): OrdenTransformacionMaterial {
            $lote = LoteTransformacionMaterial::query()
                ->lockForUpdate()
                ->findOrFail($lote->id);
            $orden = OrdenTransformacionMaterial::query()
                ->lockForUpdate()
                ->findOrFail($lote->orden_transformacion_material_id);

            if ($this->eventoYaProcesado(
                $orden,
                $operacionId,
                TipoEventoTransformacionMaterial::LoteReversado,
                $usuario,
                $dispositivo,
                $payloadHash,
            )) {
                return $this->cargarOrden($orden->refresh());
            }

            $this->validarVersion($orden, $versionConocida);

            if (! in_array($orden->estado, [
                EstadoOrdenTransformacionMaterial::EnProceso,
                EstadoOrdenTransformacionMaterial::PendienteCierre,
            ], true)) {
                throw new DomainException(
                    'Solo se puede revertir un lote mientras la orden continúe en ejecución.',
                );
            }

            if ($lote->estado !== EstadoLoteTransformacionMaterial::Cerrado) {
                throw new DomainException('Solo se puede revertir un lote cerrado.');
            }

            $lotes = LoteTransformacionMaterial::query()
                ->where('orden_transformacion_material_id', $orden->id)
                ->orderBy('numero_lote')
                ->lockForUpdate()
                ->get();

            if ($lotes->contains(
                fn (LoteTransformacionMaterial $candidato): bool => $candidato->estado
                    === EstadoLoteTransformacionMaterial::Abierto,
            )) {
                throw new DomainException(
                    'Debe cerrar o descartar el lote abierto antes de revertir un lote anterior.',
                );
            }

            $ultimoLote = $lotes->sortByDesc('numero_lote')->first();

            if (! $ultimoLote || $ultimoLote->id !== $lote->id) {
                throw new DomainException(
                    'Solo se puede revertir el lote más reciente de la orden.',
                );
            }

            $trabajosImpresion = TrabajoImpresionMaterial::query()
                ->where('lote_transformacion_material_id', $lote->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'canal', 'estado']);
            $poseeImpresionRiesgosa = $trabajosImpresion->contains(
                fn (TrabajoImpresionMaterial $trabajo): bool => in_array(
                    $trabajo->estado,
                    ['enviado', 'indeterminado'],
                    true,
                ) || ($trabajo->canal === 'pda_directa' && $trabajo->estado === 'generado'),
            );

            if ($poseeImpresionRiesgosa) {
                throw new DomainException(
                    'El lote posee etiquetas directas pendientes, enviadas o indeterminadas y no puede revertirse.',
                );
            }

            $consumos = ConsumoTransformacionMaterial::query()
                ->where('lote_transformacion_material_id', $lote->id)
                ->orderBy('folio_id')
                ->lockForUpdate()
                ->get();
            $salidas = SalidaTransformacionMaterial::query()
                ->where('lote_transformacion_material_id', $lote->id)
                ->orderBy('folio_id')
                ->lockForUpdate()
                ->get();

            if ($consumos->isEmpty() || $salidas->isEmpty()) {
                throw new DomainException(
                    'El lote no posee la genealogía necesaria para una reversa compensatoria.',
                );
            }

            $folioIds = $consumos->pluck('folio_id')
                ->merge($salidas->pluck('folio_id'))
                ->unique()
                ->sort()
                ->values();
            $materiales = FolioMaterial::query()
                ->whereIn('folio_id', $folioIds)
                ->orderBy('folio_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('folio_id');
            $folios = Folio::query()
                ->whereIn('id', $folioIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $ubicaciones = UbicacionActual::query()
                ->whereIn('folio_id', $folioIds)
                ->orderBy('folio_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('folio_id');
            $reservas = ReservaTransformacionMaterial::query()
                ->where('orden_transformacion_material_id', $orden->id)
                ->whereIn('folio_id', $consumos->pluck('folio_id'))
                ->orderBy('folio_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('folio_id');
            $ahora = now();
            $foliosSalida = [];
            $foliosReubicacion = [];

            foreach ($salidas as $salida) {
                $material = $materiales->get($salida->folio_id);
                $folio = $folios->get($salida->folio_id);

                if (! $material || ! $folio) {
                    throw new DomainException('El folio de salida ya no posee una ficha válida.');
                }

                $cantidadActual = round((float) $material->cantidad_actual, 3);
                $cantidadProducida = round((float) $salida->cantidad_producida, 3);
                $movimientosSalida = MovimientoInventarioMaterial::query()
                    ->where('folio_id', $folio->id)
                    ->orderBy('ocurrido_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $movimientoProduccion = $movimientosSalida->first();
                $salidaIntacta = $movimientosSalida->count() === 1
                    && $movimientoProduccion?->tipo
                        === TipoMovimientoInventarioMaterial::ProduccionTransformacion
                    && $movimientoProduccion->lote_transformacion_material_id === $lote->id;

                if (! $folio->activo
                    || $folio->estado_operacional !== EstadoOperacionalFolio::PendienteUbicacion
                    || $ubicaciones->has($folio->id)
                    || $material->item_material_id !== $salida->item_material_id
                    || abs($cantidadActual - $cantidadProducida) > 0.0001
                    || (float) $material->cantidad_reservada > 0.0001
                    || ! $salidaIntacta) {
                    throw new DomainException(sprintf(
                        'El folio de salida %s ya fue ubicado, reservado o modificado y no puede anularse.',
                        $folio->numero_folio,
                    ));
                }

                MovimientoInventarioMaterial::create([
                    'folio_id' => $folio->id,
                    'item_material_id' => $material->item_material_id,
                    'tipo' => TipoMovimientoInventarioMaterial::ReversaTransformacion,
                    'cantidad' => -$cantidadProducida,
                    'cantidad_anterior' => $cantidadActual,
                    'cantidad_resultante' => 0,
                    'orden_transformacion_material_id' => $orden->id,
                    'lote_transformacion_material_id' => $lote->id,
                    'user_id' => $usuario->id,
                    'dispositivo_id' => $dispositivo?->id,
                    'motivo' => $motivo,
                    'metadatos' => [
                        'sentido' => 'anulacion_salida',
                        'salida_transformacion_material_id' => $salida->id,
                        'numero_lote' => $lote->numero_lote,
                    ],
                    'ocurrido_at' => $ahora,
                ]);
                $material->update([
                    'cantidad_actual' => 0,
                    'cantidad_reservada' => 0,
                ]);
                $folio->update([
                    'estado_operacional' => EstadoOperacionalFolio::Anulado,
                    'activo' => false,
                ]);
                $foliosSalida[] = $folio->numero_folio;
            }

            foreach ($consumos as $consumo) {
                $material = $materiales->get($consumo->folio_id);
                $folio = $folios->get($consumo->folio_id);
                $reserva = $reservas->get($consumo->folio_id);

                if (! $material || ! $folio || ! $reserva) {
                    throw new DomainException(
                        'Uno de los consumos ya no posee folio o reserva recuperable.',
                    );
                }

                $cantidadActual = round((float) $material->cantidad_actual, 3);
                $cantidadEsperada = round((float) $consumo->cantidad_resultante, 3);
                $cantidadConsumida = round((float) $consumo->cantidad_consumida, 3);
                $cantidadReservada = round((float) $material->cantidad_reservada, 3);
                $reservaConsumida = round((float) $reserva->cantidad_consumida, 3);
                $quedoAgotado = $cantidadEsperada <= 0.0001;
                $ultimoMovimiento = MovimientoInventarioMaterial::query()
                    ->where('folio_id', $folio->id)
                    ->orderByDesc('ocurrido_at')
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                if ($material->item_material_id !== $consumo->item_material_id
                    || abs($cantidadActual - $cantidadEsperada) > 0.0001
                    || $reservaConsumida + 0.0001 < $cantidadConsumida
                    || ! $ultimoMovimiento
                    || $ultimoMovimiento->tipo
                        !== TipoMovimientoInventarioMaterial::ConsumoTransformacion
                    || $ultimoMovimiento->lote_transformacion_material_id !== $lote->id) {
                    throw new DomainException(sprintf(
                        'El folio de entrada %s posee movimientos posteriores y no puede restaurarse.',
                        $folio->numero_folio,
                    ));
                }

                if ($quedoAgotado) {
                    if ($folio->activo
                        || $folio->estado_operacional !== EstadoOperacionalFolio::RetiradoDefinitivo
                        || $ubicaciones->has($folio->id)) {
                        throw new DomainException(sprintf(
                            'El folio agotado %s cambió después del consumo y no puede restaurarse.',
                            $folio->numero_folio,
                        ));
                    }
                } elseif (! $folio->activo
                    || $folio->estado_operacional !== EstadoOperacionalFolio::Disponible
                    || ! $ubicaciones->has($folio->id)
                    || $material->motivo_bloqueo !== null) {
                    throw new DomainException(sprintf(
                        'El folio de entrada %s ya no conserva su condición operacional.',
                        $folio->numero_folio,
                    ));
                }

                $cantidadRestaurada = round($cantidadActual + $cantidadConsumida, 3);
                $reservaRestaurada = round($cantidadReservada + $cantidadConsumida, 3);
                $reservaConsumidaRestante = max(
                    0,
                    round($reservaConsumida - $cantidadConsumida, 3),
                );

                if ($reservaRestaurada - $cantidadRestaurada > 0.0001) {
                    throw new DomainException(sprintf(
                        'La reversa dejaría una reserva superior al saldo del folio %s.',
                        $folio->numero_folio,
                    ));
                }

                $material->update([
                    'cantidad_actual' => $cantidadRestaurada,
                    'cantidad_reservada' => $reservaRestaurada,
                ]);
                $reserva->update([
                    'cantidad_consumida' => $reservaConsumidaRestante,
                    'estado' => EstadoReservaMaterial::Activa,
                ]);
                $folio->update([
                    'estado_operacional' => $quedoAgotado
                        ? EstadoOperacionalFolio::PendienteUbicacion
                        : EstadoOperacionalFolio::Disponible,
                    'activo' => true,
                ]);
                MovimientoInventarioMaterial::create([
                    'folio_id' => $folio->id,
                    'item_material_id' => $material->item_material_id,
                    'tipo' => TipoMovimientoInventarioMaterial::ReversaTransformacion,
                    'cantidad' => $cantidadConsumida,
                    'cantidad_anterior' => $cantidadActual,
                    'cantidad_resultante' => $cantidadRestaurada,
                    'orden_transformacion_material_id' => $orden->id,
                    'lote_transformacion_material_id' => $lote->id,
                    'user_id' => $usuario->id,
                    'dispositivo_id' => $dispositivo?->id,
                    'motivo' => $motivo,
                    'metadatos' => [
                        'sentido' => 'restauracion_entrada',
                        'consumo_transformacion_material_id' => $consumo->id,
                        'numero_lote' => $lote->numero_lote,
                        'requiere_reubicacion' => $quedoAgotado,
                    ],
                    'ocurrido_at' => $ahora,
                ]);

                if ($quedoAgotado) {
                    $foliosReubicacion[] = $folio->numero_folio;
                }
            }

            $lote->update([
                'estado' => EstadoLoteTransformacionMaterial::Anulado,
                'reversado_por_user_id' => $usuario->id,
                'reversado_at' => $ahora,
                'motivo_reversa' => $motivo,
            ]);
            $cantidadRealOrden = round(LoteTransformacionMaterial::query()
                ->where('orden_transformacion_material_id', $orden->id)
                ->where('estado', EstadoLoteTransformacionMaterial::Cerrado->value)
                ->sum('cantidad_real_salida'), 3);
            $orden->update([
                'cantidad_real_salida' => $cantidadRealOrden,
                'estado' => $cantidadRealOrden + 0.0001 >= (float) $orden->cantidad_planificada_salida
                    ? EstadoOrdenTransformacionMaterial::PendienteCierre
                    : EstadoOrdenTransformacionMaterial::EnProceso,
                'version' => $orden->version + 1,
            ]);
            $this->registrarEvento(
                $orden,
                TipoEventoTransformacionMaterial::LoteReversado,
                $usuario,
                $operacionId,
                [
                    'version_conocida' => $versionConocida,
                    'payload_hash' => $payloadHash,
                    'lote_id' => $lote->id,
                    'numero_lote' => $lote->numero_lote,
                    'folios_salida_anulados' => $foliosSalida,
                    'folios_entrada_requieren_ubicacion' => $foliosReubicacion,
                ],
                observacion: $motivo,
                dispositivo: $dispositivo,
            );

            return $this->cargarOrden($orden->refresh());
        }, attempts: 3);
    }

    public function cerrarOrden(
        OrdenTransformacionMaterial $orden,
        string $operacionId,
        int $versionConocida,
        ?string $motivoDesviacion,
        User $usuario,
        ?Dispositivo $dispositivo,
    ): OrdenTransformacionMaterial {
        $motivoDesviacion = $this->textoOpcional($motivoDesviacion);
        $datosOperacion = [
            'operacion_id' => $operacionId,
            'version_conocida' => $versionConocida,
            'motivo_desviacion' => $motivoDesviacion,
        ];
        $payloadHash = $this->payloadHash($datosOperacion);

        return DB::transaction(function () use (
            $orden,
            $operacionId,
            $versionConocida,
            $motivoDesviacion,
            $usuario,
            $dispositivo,
            $payloadHash,
        ): OrdenTransformacionMaterial {
            if ($this->eventoYaProcesado(
                $orden,
                $operacionId,
                TipoEventoTransformacionMaterial::Cerrada,
                $usuario,
                $dispositivo,
                $payloadHash,
            )) {
                return $this->cargarOrden($orden->refresh());
            }

            $orden = OrdenTransformacionMaterial::query()
                ->lockForUpdate()
                ->findOrFail($orden->id);
            $this->validarVersion($orden, $versionConocida);

            if (! in_array($orden->estado, [
                EstadoOrdenTransformacionMaterial::EnProceso,
                EstadoOrdenTransformacionMaterial::PendienteCierre,
            ], true)) {
                throw new DomainException('La orden no se encuentra disponible para cierre.');
            }

            $lotes = LoteTransformacionMaterial::query()
                ->where('orden_transformacion_material_id', $orden->id)
                ->lockForUpdate()
                ->get();

            if ($lotes->isEmpty()
                || $lotes->contains(
                    fn (LoteTransformacionMaterial $lote): bool => $lote->estado === EstadoLoteTransformacionMaterial::Abierto,
                )) {
                throw new DomainException('La orden debe tener al menos un lote cerrado y ninguno abierto.');
            }

            $cantidadReal = round($lotes
                ->filter(
                    fn (LoteTransformacionMaterial $lote): bool => $lote->estado === EstadoLoteTransformacionMaterial::Cerrado,
                )
                ->sum(fn (LoteTransformacionMaterial $lote): float => (float) $lote->cantidad_real_salida), 3);
            $desviacion = round($cantidadReal - (float) $orden->cantidad_planificada_salida, 3);

            if (abs($desviacion) > 0.0001 && mb_strlen((string) $motivoDesviacion) < 5) {
                throw new DomainException(
                    'Debe justificar la diferencia entre la salida planificada y la salida real.',
                );
            }

            $reservas = ReservaTransformacionMaterial::query()
                ->where('orden_transformacion_material_id', $orden->id)
                ->where('estado', EstadoReservaMaterial::Activa->value)
                ->lockForUpdate()
                ->get();
            $reservasLiberadas = 0;

            foreach ($reservas as $reserva) {
                $restante = max(
                    0,
                    round((float) $reserva->cantidad - (float) $reserva->cantidad_consumida, 3),
                );
                $folio = FolioMaterial::query()->lockForUpdate()->findOrFail($reserva->folio_id);
                $folio->update([
                    'cantidad_reservada' => max(
                        0,
                        round((float) $folio->cantidad_reservada - $restante, 3),
                    ),
                ]);
                $reserva->update([
                    'estado' => $restante <= 0.0001
                        ? EstadoReservaMaterial::Consumida
                        : EstadoReservaMaterial::Liberada,
                ]);
                $reservasLiberadas += $restante > 0.0001 ? 1 : 0;
            }

            $orden->update([
                'estado' => EstadoOrdenTransformacionMaterial::Cerrada,
                'cantidad_real_salida' => $cantidadReal,
                'version' => $orden->version + 1,
                'cerrado_por_user_id' => $usuario->id,
                'cerrado_at' => now(),
            ]);
            $this->registrarEvento(
                $orden,
                TipoEventoTransformacionMaterial::Cerrada,
                $usuario,
                $operacionId,
                [
                    'version_conocida' => $versionConocida,
                    'payload_hash' => $payloadHash,
                    'cantidad_planificada_salida' => $orden->cantidad_planificada_salida,
                    'cantidad_real_salida' => $cantidadReal,
                    'desviacion' => $desviacion,
                    'reservas_liberadas' => $reservasLiberadas,
                ],
                $motivoDesviacion,
                $dispositivo,
            );

            return $this->cargarOrden($orden->refresh());
        }, attempts: 3);
    }

    public function cargarReceta(RecetaMaterial $receta): RecetaMaterial
    {
        return $receta->load([
            'temporada:id,codigo,nombre,activa',
            'cliente:id,codigo,nombre,codigo_folio_materiales,activo',
            'itemSalida',
            'versiones' => fn ($consulta) => $consulta->orderByDesc('numero_version'),
            'versiones.detalles.itemEntrada',
            'creadoPor:id,name',
            'actualizadoPor:id,name',
        ]);
    }

    public function cargarOrden(OrdenTransformacionMaterial $orden): OrdenTransformacionMaterial
    {
        return $orden->load([
            'temporada:id,codigo,nombre,activa',
            'cliente:id,codigo,nombre,codigo_folio_materiales,activo',
            'versionReceta.receta.itemSalida',
            'versionReceta.detalles.itemEntrada',
            'reservas' => fn ($consulta) => $consulta->orderBy('item_material_id')->orderBy('orden_fifo'),
            'reservas.folioMaterial.folio.ubicacionActual.posicion.camara',
            'lotes' => fn ($consulta) => $consulta->orderBy('numero_lote'),
            'lotes.consumos.folioMaterial.folio',
            'lotes.consumos.item',
            'lotes.salidas.folioMaterial.folio',
            'lotes.salidas.item',
            'lotes.reversadoPor:id,name',
            'eventos' => fn ($consulta) => $consulta->orderBy('ocurrido_at'),
            'eventos.usuario:id,name',
            'creadoPor:id,name',
        ]);
    }

    /**
     * @param  array<int, CategoriaOperacionalMaterial>  $categorias
     */
    private function validarItem(
        string $itemId,
        Cliente $cliente,
        string $temporadaId,
        array $categorias,
        bool $bloquear = false,
    ): ItemMaterial {
        $consulta = ItemMaterial::query()
            ->with(['cliente.cliente', 'cliente.temporada'])
            ->whereKey($itemId)
            ->where('activo', true);

        if ($bloquear) {
            $consulta->lockForUpdate();
        }

        $item = $consulta->first();

        if (! $item
            || ! $item->cliente?->activo
            || ! $item->cliente?->cliente?->activo
            || $item->cliente->cliente_id !== $cliente->id
            || $item->cliente->temporada?->temporada_id !== $temporadaId
            || ! $item->cliente->temporada?->activa) {
            throw new DomainException('Uno de los ítems no pertenece al cliente y temporada activos.');
        }

        if (! in_array($item->categoria_operacional, $categorias, true)) {
            throw new DomainException(sprintf(
                'El ítem %s no posee una categoría operacional válida para esta receta.',
                $item->codigo,
            ));
        }

        return $item;
    }

    private function registrarEvento(
        OrdenTransformacionMaterial $orden,
        TipoEventoTransformacionMaterial $tipo,
        User $usuario,
        ?string $operacionId,
        ?array $datos = null,
        ?string $observacion = null,
        ?Dispositivo $dispositivo = null,
    ): void {
        EventoTransformacionMaterial::create([
            'orden_transformacion_material_id' => $orden->id,
            'operacion_id' => $operacionId,
            'tipo' => $tipo,
            'datos' => $datos,
            'observacion' => $observacion,
            'user_id' => $usuario->id,
            'dispositivo_id' => $dispositivo?->id,
            'ocurrido_at' => now(),
        ]);
    }

    private function validarVersion(
        OrdenTransformacionMaterial $orden,
        int $versionConocida,
    ): void {
        if ($orden->version !== $versionConocida) {
            throw new ConflictoOperacion('La orden cambió desde la última lectura.');
        }
    }

    private function eventoYaProcesado(
        OrdenTransformacionMaterial $orden,
        string $operacionId,
        TipoEventoTransformacionMaterial $tipo,
        User $usuario,
        ?Dispositivo $dispositivo,
        string $payloadHash,
    ): bool {
        $evento = EventoTransformacionMaterial::query()
            ->where('operacion_id', $operacionId)
            ->lockForUpdate()
            ->first();

        if (! $evento) {
            return false;
        }

        if ($evento->orden_transformacion_material_id !== $orden->id
            || $evento->tipo !== $tipo
            || $evento->user_id !== $usuario->id
            || $evento->dispositivo_id !== $dispositivo?->id
            || ! hash_equals((string) data_get($evento->datos, 'payload_hash'), $payloadHash)) {
            throw new ConflictoOperacion(
                'El UUID ya fue utilizado por otra operación o con datos diferentes.',
            );
        }

        return true;
    }

    private function cantidad(mixed $valor): float
    {
        $cantidad = round((float) $valor, 3);

        if ($cantidad <= 0) {
            throw new DomainException('Las cantidades deben ser mayores que cero.');
        }

        return $cantidad;
    }

    private function porcentaje(mixed $valor): float
    {
        $porcentaje = round((float) $valor, 4);

        if ($porcentaje < 0 || $porcentaje > 100) {
            throw new DomainException('Los porcentajes deben encontrarse entre 0 y 100.');
        }

        return $porcentaje;
    }

    private function textoOpcional(mixed $valor): ?string
    {
        $texto = trim((string) ($valor ?? ''));

        return $texto === '' ? null : $texto;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function payloadHash(array $datos): string
    {
        try {
            return hash('sha256', json_encode(
                $this->normalizar($datos),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
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

        if ($valor instanceof DateTimeInterface) {
            return $valor->format(DATE_ATOM);
        }

        if (! is_array($valor)) {
            return $valor;
        }

        if (! array_is_list($valor)) {
            ksort($valor);
        }

        return array_map(fn (mixed $item): mixed => $this->normalizar($item), $valor);
    }
}

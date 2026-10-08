<?php

namespace App\Services\Consultas;

use App\Enums\TipoMovimientoInventarioMaterial;
use App\Models\CorreccionItemFolioMaterial;
use App\Models\EventoBloqueoMaterial;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\Movimiento;
use App\Models\MovimientoAlmacenMaterial;
use App\Models\MovimientoInventarioMaterial;
use App\Models\SaldoMaterialAlmacen;
use BackedEnum;
use Illuminate\Support\Collection;

class ServicioExpedienteMaterial
{
    /** @return array<string, mixed> */
    public function obtener(Folio $folio): array
    {
        $folio->load([
            'temporada',
            'ubicacionActual.camara',
            'ubicacionActual.posicion',
        ]);

        $material = FolioMaterial::query()
            ->with([
                'folio',
                'item.cliente.cliente',
                'item.fotoPrincipal',
                'proveedorMaterial',
                'bultoRecepcion.detalle.recepcion.cliente',
                'bultoRecepcion.detalle.recepcion.proveedor',
                'bultoRecepcion.detalle.recepcion.confirmadoPor',
                'loteTransformacionOrigen.orden.cliente',
            ])
            ->findOrFail($folio->id);

        $movimientosInventario = MovimientoInventarioMaterial::query()
            ->where('folio_id', $folio->id)
            ->with([
                'item',
                'despacho:id,codigo,destino_nombre,destino_centro_costo,estado',
                'usuario:id,name',
                'dispositivo:id,codigo,nombre',
                'ordenTransformacion:id,fecha_operacional,linea,turno,estado',
                'loteTransformacion:id,orden_transformacion_material_id,numero_lote,estado',
            ])
            ->orderBy('ocurrido_at')
            ->orderBy('created_at')
            ->get();

        $movimientosAlmacen = MovimientoAlmacenMaterial::query()
            ->where('folio_id', $folio->id)
            ->with([
                'item',
                'almacenOrigen',
                'almacenDestino',
                'usuario:id,name',
                'dispositivo:id,codigo,nombre',
            ])
            ->orderBy('ocurrido_at')
            ->orderBy('secuencia')
            ->get();

        $movimientosFisicos = Movimiento::query()
            ->where('folio_id', $folio->id)
            ->with([
                'usuario',
                'camaraOrigen',
                'posicionOrigen',
                'camaraDestino',
                'posicionDestino',
            ])
            ->orderBy('recibido_servidor_at')
            ->orderBy('created_at')
            ->get();

        $correcciones = CorreccionItemFolioMaterial::query()
            ->where('folio_id', $folio->id)
            ->with(['itemAnterior', 'itemNuevo', 'usuario:id,name'])
            ->orderBy('ocurrido_at')
            ->get();

        $bloqueos = EventoBloqueoMaterial::query()
            ->where('folio_id', $folio->id)
            ->with('usuario:id,name')
            ->orderBy('ocurrido_at')
            ->get();

        $saldos = SaldoMaterialAlmacen::query()
            ->where('folio_id', $folio->id)
            ->where('cantidad_actual', '>', 0)
            ->with(['almacen', 'camara', 'posicion'])
            ->orderByDesc('cantidad_actual')
            ->get();

        $recepcion = $material->bultoRecepcion?->detalle?->recepcion;
        $eventosInventario = $this->eventosInventario(
            $movimientosInventario,
            $movimientosAlmacen,
            $material->unidad_medida,
            $recepcion !== null,
        );
        $eventosAlmacen = $this->eventosAlmacen($movimientosAlmacen, $material->unidad_medida);
        $eventosFisicos = $this->eventosFisicos($movimientosFisicos);
        $timeline = collect()
            ->concat($this->eventoRecepcion($material))
            ->concat($eventosInventario)
            ->concat($eventosAlmacen)
            ->concat($eventosFisicos)
            ->concat($this->eventosCorrecciones($correcciones, $material->unidad_medida))
            ->concat($this->eventosBloqueo($bloqueos))
            ->filter(fn (array $evento): bool => $evento['fecha'] !== null)
            ->sortBy('fecha')
            ->values()
            ->all();

        $cantidadActual = (float) $material->cantidad_actual;
        $cantidadReservada = (float) $material->cantidad_reservada;
        $ubicacion = $folio->ubicacionActual;
        $loteTransformacion = $material->loteTransformacionOrigen;
        $clienteMaterial = $material->item?->cliente;

        return [
            'folio' => [
                'id' => $folio->id,
                'numero' => $folio->numero_folio,
                'tipo_bulto' => $this->valor($folio->tipo_bulto),
                'estado' => $this->valor($folio->estado_operacional),
                'estado_explicado' => $this->valor($folio->estado_operacional),
                'condicion_termica' => $this->valor($folio->condicion_termica),
                'cantidad_cajas' => null,
                'cantidad_actual' => $cantidadActual,
                'unidad_medida' => $material->unidad_medida,
                'temporada' => $folio->temporada?->codigo,
                'fecha_ingreso' => $folio->fecha_ingreso?->toIso8601String(),
                'especificaciones' => [],
                'ubicacion' => $ubicacion ? [
                    'camara' => $ubicacion->camara?->codigo,
                    'posicion' => $ubicacion->posicion?->etiqueta,
                ] : null,
                'repaletizaje_agotamiento' => null,
            ],
            'material' => [
                'vencimiento' => $material->informacionVencimiento(),
                'foto_principal' => $material->item?->fotoPrincipal?->representar(),
                'identidad' => [
                    'cliente' => $clienteMaterial?->cliente?->nombre ?? $clienteMaterial?->nombre,
                    'codigo' => $material->item?->codigo,
                    'item' => $material->item?->nombre,
                    'categoria' => $material->item?->categoria,
                    'categoria_operacional' => $this->valor($material->categoria_operacional),
                    'proveedor' => $material->proveedorMaterial?->nombre ?? $material->proveedor,
                    'lote' => $material->lote,
                    'fecha_fabricacion' => $material->fecha_fabricacion?->toDateString(),
                    'fecha_vencimiento' => $material->fecha_vencimiento?->toDateString(),
                ],
                'inventario' => [
                    'inicial' => (float) $material->cantidad_inicial,
                    'actual' => $cantidadActual,
                    'reservada' => $cantidadReservada,
                    'disponible' => $material->estaVencido() || $material->motivo_bloqueo !== null ? 0 : max(0, round($cantidadActual - $cantidadReservada, 3)),
                    'unidad_medida' => $material->unidad_medida,
                ],
                'origen' => $this->origen($material),
                'recepcion' => $recepcion ? [
                    'id' => $recepcion->id,
                    'numero_guia' => $recepcion->numero_guia_despacho,
                    'orden_compra' => $recepcion->orden_compra,
                    'fecha_documento' => $recepcion->fecha_documento?->toDateString(),
                    'confirmado_at' => $recepcion->confirmado_at?->toIso8601String(),
                    'confirmado_por' => $recepcion->confirmadoPor?->name,
                    'cliente' => $recepcion->cliente?->nombre,
                    'proveedor' => $recepcion->proveedor?->nombre,
                ] : null,
                'transformacion_origen' => $loteTransformacion ? [
                    'orden_id' => $loteTransformacion->orden_transformacion_material_id,
                    'lote_id' => $loteTransformacion->id,
                    'numero_lote' => $loteTransformacion->numero_lote,
                    'fecha_operacional' => $loteTransformacion->orden?->fecha_operacional?->toDateString(),
                ] : null,
                'saldos' => $saldos->map(fn (SaldoMaterialAlmacen $saldo): array => [
                    'almacen' => $saldo->almacen?->nombre,
                    'codigo' => $saldo->almacen?->codigo,
                    'tipo' => $this->valor($saldo->almacen?->tipo),
                    'centro_costo' => $saldo->almacen?->centro_costo,
                    'cantidad_actual' => (float) $saldo->cantidad_actual,
                    'cantidad_reservada' => (float) $saldo->cantidad_reservada,
                    'cantidad_disponible' => $saldo->cantidadDisponible(),
                    'camara' => $saldo->camara?->codigo,
                    'posicion' => $saldo->posicion?->etiqueta,
                ])->values()->all(),
            ],
            'validacion' => null,
            'repaletizajes' => [],
            'timeline' => $timeline,
            'totales' => [
                'validaciones' => 0,
                'procesos_prefrio' => 0,
                'movimientos' => $movimientosFisicos->count(),
                'repaletizajes' => 0,
                'recepciones_material' => $recepcion ? 1 : 0,
                'movimientos_material' => count($eventosInventario) + count($eventosAlmacen),
                'consumos_material' => $this->contarConsumos($movimientosInventario, $movimientosAlmacen),
                'transformaciones_material' => $movimientosInventario
                    ->pluck('lote_transformacion_material_id')
                    ->filter()
                    ->unique()
                    ->count(),
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function eventoRecepcion(FolioMaterial $material): array
    {
        $bulto = $material->bultoRecepcion;
        $detalle = $bulto?->detalle;
        $recepcion = $detalle?->recepcion;

        if (! $recepcion) {
            return [];
        }

        return [[
            'tipo' => 'recepcion_material',
            'fecha' => $this->fecha($recepcion->confirmado_at ?? $recepcion->created_at),
            'titulo' => 'Recepción de material',
            'descripcion' => sprintf(
                'Guía %s · %s',
                $recepcion->numero_guia_despacho,
                $this->cantidad($bulto->cantidad, $material->unidad_medida),
            ),
            'meta' => array_filter([
                'Recepción ID' => $recepcion->id,
                'Orden de compra' => $recepcion->orden_compra,
                'Cliente' => $recepcion->cliente?->nombre,
                'Proveedor' => $recepcion->proveedor?->nombre,
                'Lote proveedor' => $bulto->lote_proveedor,
                'Confirmado por' => $recepcion->confirmadoPor?->name,
            ], $this->conValor(...)),
        ]];
    }

    /**
     * @param  Collection<int, MovimientoInventarioMaterial>  $movimientos
     * @param  Collection<int, MovimientoAlmacenMaterial>  $movimientosAlmacen
     * @return array<int, array<string, mixed>>
     */
    private function eventosInventario(
        Collection $movimientos,
        Collection $movimientosAlmacen,
        string $unidad,
        bool $omitirIngresoRecepcion,
    ): array {
        return $movimientos
            ->reject(fn (MovimientoInventarioMaterial $movimiento): bool => (
                $omitirIngresoRecepcion
                && $movimiento->tipo === TipoMovimientoInventarioMaterial::IngresoRecepcion
            ) || in_array($movimiento->tipo, [
                TipoMovimientoInventarioMaterial::CorreccionItemSalida,
                TipoMovimientoInventarioMaterial::CorreccionItemEntrada,
            ], true))
            ->reject(fn (MovimientoInventarioMaterial $movimiento): bool => in_array(
                $movimiento->tipo,
                [
                    TipoMovimientoInventarioMaterial::TransferenciaInterna,
                    TipoMovimientoInventarioMaterial::ConsumoCentroCosto,
                ],
                true,
            ) && $this->tieneMovimientoAlmacen($movimiento, $movimientosAlmacen))
            ->map(fn (MovimientoInventarioMaterial $movimiento): array => [
                'tipo' => 'inventario_material',
                'fecha' => $this->fecha($movimiento->ocurrido_at ?? $movimiento->created_at),
                'titulo' => $this->tituloMovimientoInventario($movimiento),
                'descripcion' => sprintf(
                    '%s · %s → %s',
                    $this->cantidadConSigno($movimiento->cantidad, $unidad),
                    $this->cantidad($movimiento->cantidad_anterior, $unidad),
                    $this->cantidad($movimiento->cantidad_resultante, $unidad),
                ),
                'meta' => array_filter([
                    'Ítem' => $movimiento->item?->codigo,
                    'Destino' => $movimiento->destino_nombre,
                    'Centro de costo' => $movimiento->destino_centro_costo,
                    'Despacho' => $movimiento->despacho?->codigo,
                    'Orden transformación' => $movimiento->orden_transformacion_material_id,
                    'Lote transformación' => $movimiento->loteTransformacion?->numero_lote,
                    'Registrado por' => $movimiento->usuario?->name,
                    'Dispositivo' => $movimiento->dispositivo?->codigo,
                    'FIFO' => $this->fifo($movimiento->metadatos['siguio_fifo'] ?? null),
                    'Motivo' => $movimiento->motivo,
                ], $this->conValor(...)),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, MovimientoAlmacenMaterial>  $movimientos
     * @return array<int, array<string, mixed>>
     */
    private function eventosAlmacen(Collection $movimientos, string $unidad): array
    {
        return $movimientos->map(fn (MovimientoAlmacenMaterial $movimiento): array => [
            'tipo' => 'almacen_material',
            'fecha' => $this->fecha($movimiento->ocurrido_at ?? $movimiento->created_at),
            'titulo' => match ($this->valor($movimiento->tipo)) {
                'entrega' => 'Entrega de material',
                'transferencia' => 'Transferencia entre almacenes',
                'devolucion' => 'Devolución de material',
                'consumo' => 'Consumo de material',
                'ajuste' => 'Ajuste de almacén',
                default => 'Movimiento de almacén',
            },
            'descripcion' => sprintf(
                '%s → %s · %s',
                $movimiento->almacenOrigen?->nombre ?? 'Sin almacén de origen',
                $movimiento->almacenDestino?->nombre ?? 'Salida del inventario',
                $this->cantidad($movimiento->cantidad, $unidad),
            ),
            'meta' => array_filter([
                'Centro de costo' => $movimiento->centro_costo,
                'Documento' => $movimiento->documento_relacionado,
                'Saldo origen' => $this->transicionSaldo(
                    $movimiento->saldo_origen_anterior,
                    $movimiento->saldo_origen_resultante,
                    $unidad,
                ),
                'Saldo destino' => $this->transicionSaldo(
                    $movimiento->saldo_destino_anterior,
                    $movimiento->saldo_destino_resultante,
                    $unidad,
                ),
                'Registrado por' => $movimiento->usuario?->name,
                'Dispositivo' => $movimiento->dispositivo?->codigo,
                'FIFO' => $this->fifo($movimiento->metadatos['siguio_fifo'] ?? null),
                'Motivo' => $movimiento->motivo,
            ], $this->conValor(...)),
        ])->all();
    }

    /** @param Collection<int, Movimiento> $movimientos */
    private function eventosFisicos(Collection $movimientos): array
    {
        return $movimientos->map(function (Movimiento $movimiento): array {
            $origen = $this->ubicacion(
                $movimiento->camaraOrigen?->codigo,
                $movimiento->posicionOrigen?->etiqueta,
                'Sin ubicación',
            );
            $destino = $this->ubicacion(
                $movimiento->camaraDestino?->codigo,
                $movimiento->posicionDestino?->etiqueta,
                'Sin ubicación',
            );

            return [
                'tipo' => 'movimiento',
                'fecha' => $this->fecha($movimiento->recibido_servidor_at ?? $movimiento->created_at),
                'titulo' => 'Movimiento físico',
                'descripcion' => $origen.' → '.$destino,
                'meta' => array_filter([
                    'Tipo' => $this->valor($movimiento->tipo_movimiento),
                    'Operador' => $movimiento->usuario?->name,
                    'Motivo' => $movimiento->motivo,
                ], $this->conValor(...)),
            ];
        })->all();
    }

    /** @param Collection<int, CorreccionItemFolioMaterial> $correcciones */
    private function eventosCorrecciones(Collection $correcciones, string $unidad): array
    {
        return $correcciones->map(fn (CorreccionItemFolioMaterial $correccion): array => [
            'tipo' => 'correccion_material',
            'fecha' => $this->fecha($correccion->ocurrido_at ?? $correccion->created_at),
            'titulo' => 'Corrección de ítem',
            'descripcion' => sprintf(
                '%s → %s · %s',
                $correccion->itemAnterior?->codigo ?? 'Ítem anterior',
                $correccion->itemNuevo?->codigo ?? 'Ítem nuevo',
                $this->cantidad($correccion->cantidad, $unidad),
            ),
            'meta' => array_filter([
                'Motivo' => $correccion->motivo,
                'Corregido por' => $correccion->usuario?->name,
            ], $this->conValor(...)),
        ])->all();
    }

    /** @param Collection<int, EventoBloqueoMaterial> $bloqueos */
    private function eventosBloqueo(Collection $bloqueos): array
    {
        return $bloqueos->map(fn (EventoBloqueoMaterial $evento): array => [
            'tipo' => 'bloqueo_material',
            'fecha' => $this->fecha($evento->ocurrido_at ?? $evento->created_at),
            'titulo' => match ($this->valor($evento->tipo)) {
                'bloqueado' => 'Material bloqueado',
                'fecha_corregida' => 'Fecha de vencimiento corregida',
                default => 'Material desbloqueado',
            },
            'descripcion' => sprintf(
                '%s → %s',
                $this->valor($evento->estado_anterior),
                $this->valor($evento->estado_resultante),
            ),
            'meta' => array_filter([
                'Motivo' => $evento->motivo,
                'Registrado por' => $evento->usuario?->name ?? 'Sistema',
                'Fecha anterior' => data_get($evento->metadatos, 'fecha_anterior'),
                'Fecha nueva' => data_get($evento->metadatos, 'fecha_nueva'),
            ], $this->conValor(...)),
        ])->all();
    }

    /** @return array<string, mixed> */
    private function origen(FolioMaterial $material): array
    {
        $recepcion = $material->bultoRecepcion?->detalle?->recepcion;

        if ($recepcion) {
            return [
                'tipo' => 'recepcion',
                'titulo' => 'Recepción de materiales',
                'referencia' => 'Guía '.$recepcion->numero_guia_despacho,
                'fecha' => $this->fecha($recepcion->confirmado_at ?? $recepcion->created_at),
            ];
        }

        $lote = $material->loteTransformacionOrigen;
        if ($lote) {
            return [
                'tipo' => 'transformacion',
                'titulo' => 'Transformación de materiales',
                'referencia' => 'Lote '.$lote->numero_lote,
                'fecha' => $this->fecha($lote->cerrado_at ?? $lote->created_at),
            ];
        }

        return [
            'tipo' => 'regularizacion',
            'titulo' => 'Ingreso de material',
            'referencia' => 'Sin recepción o transformación asociada',
            'fecha' => $this->fecha($material->folio?->fecha_ingreso),
        ];
    }

    private function tituloMovimientoInventario(MovimientoInventarioMaterial $movimiento): string
    {
        return match ($movimiento->tipo) {
            TipoMovimientoInventarioMaterial::Ingreso => 'Ingreso de material',
            TipoMovimientoInventarioMaterial::IngresoRecepcion => 'Recepción de material',
            TipoMovimientoInventarioMaterial::AnulacionRecepcion => 'Recepción anulada',
            TipoMovimientoInventarioMaterial::Despacho => 'Retiro de material',
            TipoMovimientoInventarioMaterial::TransferenciaInterna => 'Transferencia interna',
            TipoMovimientoInventarioMaterial::ConsumoCentroCosto => 'Consumo por centro de costo',
            TipoMovimientoInventarioMaterial::AjusteAlmacen,
            TipoMovimientoInventarioMaterial::Ajuste => 'Ajuste de inventario',
            TipoMovimientoInventarioMaterial::Devolucion => 'Devolución de material',
            TipoMovimientoInventarioMaterial::ConsumoTransformacion => 'Consumo en transformación',
            TipoMovimientoInventarioMaterial::ProduccionTransformacion => 'Producción por transformación',
            TipoMovimientoInventarioMaterial::MermaTransformacion => 'Merma de transformación',
            TipoMovimientoInventarioMaterial::ReversaTransformacion => 'Reversa de transformación',
            default => 'Movimiento de inventario',
        };
    }

    /**
     * @param  Collection<int, MovimientoAlmacenMaterial>  $movimientosAlmacen
     */
    private function tieneMovimientoAlmacen(
        MovimientoInventarioMaterial $movimiento,
        Collection $movimientosAlmacen,
    ): bool {
        return $movimientosAlmacen->contains(
            fn (MovimientoAlmacenMaterial $almacen): bool => (
                $movimiento->retiro_material_id
                && $almacen->retiro_material_id === $movimiento->retiro_material_id
            ) || (
                $movimiento->despacho_material_id
                && $almacen->despacho_material_id === $movimiento->despacho_material_id
            ),
        );
    }

    /**
     * @param  Collection<int, MovimientoInventarioMaterial>  $inventario
     * @param  Collection<int, MovimientoAlmacenMaterial>  $almacenes
     */
    private function contarConsumos(Collection $inventario, Collection $almacenes): int
    {
        $inventarioConsumo = $inventario
            ->filter(function (MovimientoInventarioMaterial $movimiento) use ($almacenes): bool {
                if (! in_array($movimiento->tipo, [
                    TipoMovimientoInventarioMaterial::Despacho,
                    TipoMovimientoInventarioMaterial::ConsumoCentroCosto,
                    TipoMovimientoInventarioMaterial::ConsumoTransformacion,
                ], true)) {
                    return false;
                }

                return $movimiento->tipo !== TipoMovimientoInventarioMaterial::ConsumoCentroCosto
                    || ! $this->tieneMovimientoAlmacen($movimiento, $almacenes);
            })
            ->count();
        $almacenConsumo = $almacenes
            ->filter(fn (MovimientoAlmacenMaterial $movimiento): bool => $this->valor($movimiento->tipo) === 'consumo')
            ->count();

        return $inventarioConsumo + $almacenConsumo;
    }

    private function transicionSaldo(mixed $anterior, mixed $resultante, string $unidad): ?string
    {
        if ($anterior === null && $resultante === null) {
            return null;
        }

        return $this->cantidad($anterior ?? 0, $unidad).' → '.$this->cantidad($resultante ?? 0, $unidad);
    }

    private function fifo(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        return (bool) $valor ? 'Sí' : 'No';
    }

    private function cantidadConSigno(mixed $cantidad, string $unidad): string
    {
        $valor = (float) $cantidad;

        return ($valor > 0 ? '+' : '').$this->cantidad($valor, $unidad);
    }

    private function cantidad(mixed $cantidad, string $unidad): string
    {
        $numero = number_format((float) $cantidad, 3, ',', '.');
        $numero = rtrim(rtrim($numero, '0'), ',');

        return trim($numero.' '.$unidad);
    }

    private function ubicacion(?string $camara, ?string $posicion, string $fallback): string
    {
        if (! $camara && ! $posicion) {
            return $fallback;
        }

        return collect([$camara, $posicion])->filter()->implode(' · ');
    }

    private function fecha(mixed $fecha): ?string
    {
        return $fecha?->toIso8601String();
    }

    private function valor(mixed $valor): mixed
    {
        return $valor instanceof BackedEnum ? $valor->value : $valor;
    }

    private function conValor(mixed $valor): bool
    {
        return $valor !== null && $valor !== '';
    }
}

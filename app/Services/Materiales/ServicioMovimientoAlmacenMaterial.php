<?php

namespace App\Services\Materiales;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoPosicion;
use App\Enums\TipoAlmacenMaterial;
use App\Enums\TipoMovimientoAlmacenMaterial;
use App\Exceptions\ConflictoOperacion;
use App\Exceptions\OperacionNoAutorizada;
use App\Models\AlmacenMaterial;
use App\Models\Camara;
use App\Models\Dispositivo;
use App\Models\FolioMaterial;
use App\Models\MovimientoAlmacenMaterial;
use App\Models\Posicion;
use App\Models\SaldoMaterialAlmacen;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ServicioMovimientoAlmacenMaterial
{
    public function __construct(
        private readonly AlcanceOperacionalUsuario $alcance,
        private readonly ServicioAlmacenMaterial $almacenes,
    ) {}

    /** @param array<string, mixed> $datos */
    public function registrar(
        array $datos,
        User $usuario,
        ?Dispositivo $dispositivo,
    ): MovimientoAlmacenMaterial {
        $tipo = TipoMovimientoAlmacenMaterial::from($datos['tipo']);
        $this->autorizar($tipo, $usuario);
        $hash = $this->payloadHash($datos);

        try {
            return DB::transaction(function () use ($datos, $usuario, $dispositivo, $tipo, $hash) {
                $existente = MovimientoAlmacenMaterial::query()
                    ->where('operacion_id', $datos['operacion_id'])
                    ->where('secuencia', 1)
                    ->lockForUpdate()
                    ->first();

                if ($existente) {
                    return $this->validarReintento($existente, $usuario, $hash);
                }

                $folio = FolioMaterial::query()
                    ->with(['folio', 'item'])
                    ->lockForUpdate()
                    ->findOrFail($datos['folio_id']);

                if (in_array($tipo, [TipoMovimientoAlmacenMaterial::Consumo, TipoMovimientoAlmacenMaterial::Transferencia, TipoMovimientoAlmacenMaterial::Entrega], true)) {
                    $folio->asegurarVigente();
                }

                return match ($tipo) {
                    TipoMovimientoAlmacenMaterial::Consumo => $this->consumir(
                        $folio,
                        $datos,
                        $usuario,
                        $dispositivo,
                        $hash,
                    ),
                    TipoMovimientoAlmacenMaterial::Ajuste => $this->ajustar(
                        $folio,
                        $datos,
                        $usuario,
                        $dispositivo,
                        $hash,
                    ),
                    TipoMovimientoAlmacenMaterial::Devolucion,
                    TipoMovimientoAlmacenMaterial::Transferencia => $this->transferir(
                        $folio,
                        $datos,
                        $tipo,
                        $usuario,
                        $dispositivo,
                        $hash,
                    ),
                    TipoMovimientoAlmacenMaterial::Entrega => throw new DomainException(
                        'Las entregas se registran desde una solicitud de materiales.',
                    ),
                };
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existente = MovimientoAlmacenMaterial::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->where('secuencia', 1)
                ->first();

            if ($existente) {
                return $this->validarReintento($existente, $usuario, $hash);
            }

            throw new ConflictoOperacion(
                'El movimiento entró en conflicto con otra operación concurrente.',
                previous: $exception,
            );
        }
    }

    public function cargar(MovimientoAlmacenMaterial $movimiento): MovimientoAlmacenMaterial
    {
        return $movimiento->load([
            'folioMaterial.folio:id,numero_folio,estado_operacional,activo',
            'item.cliente.temporada',
            'almacenOrigen:id,codigo,nombre,tipo,centro_costo',
            'almacenDestino:id,codigo,nombre,tipo,centro_costo',
            'usuario:id,name',
            'dispositivo:id,codigo,nombre',
        ]);
    }

    /** @param array<string, mixed> $datos */
    private function consumir(
        FolioMaterial $folio,
        array $datos,
        User $usuario,
        ?Dispositivo $dispositivo,
        string $hash,
    ): MovimientoAlmacenMaterial {
        $cantidad = $this->cantidadPositiva($datos['cantidad']);
        $almacenes = $this->almacenesBloqueados([
            $datos['almacen_origen_id'] ?? null,
            $datos['centro_costo_id'] ?? null,
        ]);
        $almacen = $almacenes->firstWhere('id', $datos['almacen_origen_id'] ?? null);

        if (! $almacen) {
            throw new DomainException('El almacén no existe o está inactivo.');
        }

        $centroCosto = $this->centroCostoConsumo(
            $folio,
            $almacen,
            $almacenes->firstWhere('id', $datos['centro_costo_id'] ?? null),
            $datos,
        );
        $excepcionFifo = trim((string) ($datos['motivo_excepcion_fifo'] ?? ''));
        $this->validarFifo($folio, $almacen, $excepcionFifo);
        $saldo = $this->saldoBloqueado($folio, $almacen);
        $this->validarDisponible($folio, $saldo, $almacen);

        if ($cantidad > $saldo->cantidadDisponible() + 0.0001) {
            throw new DomainException('El consumo supera el saldo disponible del almacén.');
        }

        $anterior = (float) $saldo->cantidad_actual;
        $resultante = round($anterior - $cantidad, 3);
        $totalAnterior = (float) $folio->cantidad_actual;
        $camaraAnterior = $saldo->camara_id;
        $version = (int) $saldo->version + 1;
        $saldo->update([
            'cantidad_actual' => $resultante,
            'camara_id' => $resultante > 0 ? $saldo->camara_id : null,
            'posicion_id' => $resultante > 0 ? $saldo->posicion_id : null,
            'version' => $version,
        ]);
        $proyeccion = $this->almacenes->sincronizarProyeccion($folio);
        $this->incrementarPlanos($resultante <= 0 ? [$camaraAnterior] : []);

        return $this->crearMovimiento([
            'operacion_id' => $datos['operacion_id'],
            'payload_hash' => $hash,
            'tipo' => TipoMovimientoAlmacenMaterial::Consumo,
            'folio_id' => $folio->folio_id,
            'item_material_id' => $folio->item_material_id,
            'almacen_origen_id' => $almacen->id,
            'cantidad' => $cantidad,
            'saldo_origen_anterior' => $anterior,
            'saldo_origen_resultante' => $resultante,
            'centro_costo' => $centroCosto?->centro_costo ?? $almacen->centro_costo,
            'motivo' => trim((string) $datos['motivo']),
            'documento_relacionado' => $this->texto($datos['documento_relacionado'] ?? null),
            'user_id' => $usuario->id,
            'dispositivo_id' => $dispositivo?->id,
            'metadatos' => [
                'total_empresa_anterior' => $totalAnterior,
                'total_empresa_resultante' => (float) $proyeccion->cantidad_actual,
                'motivo_excepcion_fifo' => $this->texto($excepcionFifo),
                'saldo_version_resultante' => $version,
                ...($centroCosto ? [
                    'centro_costo_id' => $centroCosto->id,
                    'centro_costo_codigo' => $centroCosto->centro_costo,
                    'centro_costo_nombre' => $centroCosto->nombre,
                    'modalidad_consumo' => 'directo_bodega',
                ] : []),
            ],
        ]);
    }

    /** @param array<string, mixed> $datos */
    private function centroCostoConsumo(
        FolioMaterial $folio,
        AlmacenMaterial $almacen,
        ?AlmacenMaterial $centroCosto,
        array $datos,
    ): ?AlmacenMaterial {
        $centroCostoId = $datos['centro_costo_id'] ?? null;

        if ($almacen->codigo !== AlmacenMaterial::CODIGO_BODEGA_CENTRAL) {
            if ($centroCostoId) {
                throw new DomainException(
                    'La imputación directa a centro de costo solo aplica desde Bodega Central.',
                );
            }

            return null;
        }

        if ($folio->categoria_operacional !== CategoriaOperacionalMaterial::Insumo) {
            throw new DomainException(
                'El consumo directo desde Bodega Central solo está disponible para insumos.',
            );
        }

        if (! $centroCostoId) {
            throw new DomainException(
                'Debe indicar el centro de costo que consumió el insumo.',
            );
        }

        if (! $centroCosto
            || $centroCosto->tipo !== TipoAlmacenMaterial::Virtual
            || ! $this->texto($centroCosto->centro_costo)) {
            throw new DomainException(
                'El centro de costo debe corresponder a un almacén virtual activo.',
            );
        }

        return $centroCosto;
    }

    /** @param array<string, mixed> $datos */
    private function transferir(
        FolioMaterial $folio,
        array $datos,
        TipoMovimientoAlmacenMaterial $tipo,
        User $usuario,
        ?Dispositivo $dispositivo,
        string $hash,
    ): MovimientoAlmacenMaterial {
        $cantidad = $this->cantidadPositiva($datos['cantidad']);
        $lista = $this->almacenesBloqueados([
            $datos['almacen_origen_id'] ?? null,
            $datos['almacen_destino_id'] ?? null,
        ]);
        $origen = $lista->firstWhere('id', $datos['almacen_origen_id'] ?? null);
        $destino = $lista->firstWhere('id', $datos['almacen_destino_id'] ?? null);

        if (! $origen || ! $destino || $origen->id === $destino->id) {
            throw new DomainException('Debe indicar almacenes de origen y destino diferentes.');
        }

        if ($tipo === TipoMovimientoAlmacenMaterial::Devolucion
            && ($origen->tipo !== TipoAlmacenMaterial::Virtual
                || $destino->tipo !== TipoAlmacenMaterial::Fisica)) {
            throw new DomainException(
                'Una devolución debe regresar desde un almacén virtual a uno físico.',
            );
        }

        $this->almacenes->asegurarSaldo($folio, $destino);
        $saldos = $this->almacenes->saldosBloqueados(
            $folio,
            [$origen->id, $destino->id],
        )->keyBy('almacen_material_id');
        $saldoOrigen = $saldos->get($origen->id);
        $saldoDestino = $saldos->get($destino->id);

        if (! $saldoOrigen || ! $saldoDestino) {
            throw new DomainException('No fue posible bloquear ambos saldos de la transferencia.');
        }

        $saldoOrigen->loadMissing(['camara', 'posicion']);
        $this->validarDisponible($folio, $saldoOrigen, $origen, $tipo === TipoMovimientoAlmacenMaterial::Devolucion);

        if ($cantidad > $saldoOrigen->cantidadDisponible() + 0.0001) {
            throw new DomainException('La transferencia supera el saldo disponible de origen.');
        }

        [$camara, $posicion] = $this->ubicacionDestino($destino, $saldoDestino, $datos);
        $origenAnterior = (float) $saldoOrigen->cantidad_actual;
        $origenResultante = round($origenAnterior - $cantidad, 3);
        $destinoAnterior = (float) $saldoDestino->cantidad_actual;
        $destinoResultante = round($destinoAnterior + $cantidad, 3);
        $totalAnterior = (float) $folio->cantidad_actual;
        $camaraOrigen = $saldoOrigen->camara_id;
        $camaraDestinoAnterior = $saldoDestino->camara_id;
        $versionOrigen = (int) $saldoOrigen->version + 1;
        $versionDestino = (int) $saldoDestino->version + 1;
        $saldoOrigen->update([
            'cantidad_actual' => $origenResultante,
            'camara_id' => $origenResultante > 0 ? $saldoOrigen->camara_id : null,
            'posicion_id' => $origenResultante > 0 ? $saldoOrigen->posicion_id : null,
            'version' => $versionOrigen,
        ]);
        $saldoDestino->update([
            'cantidad_actual' => $destinoResultante,
            'camara_id' => $destino->tipo === TipoAlmacenMaterial::Fisica ? $camara?->id : null,
            'posicion_id' => $destino->tipo === TipoAlmacenMaterial::Fisica ? $posicion?->id : null,
            'version' => $versionDestino,
        ]);
        $proyeccion = $this->almacenes->sincronizarProyeccion($folio);
        $this->incrementarPlanos([
            $origenResultante <= 0 ? $camaraOrigen : null,
            $camaraDestinoAnterior !== $camara?->id ? $camara?->id : null,
        ]);

        return $this->crearMovimiento([
            'operacion_id' => $datos['operacion_id'],
            'payload_hash' => $hash,
            'tipo' => $tipo,
            'folio_id' => $folio->folio_id,
            'item_material_id' => $folio->item_material_id,
            'almacen_origen_id' => $origen->id,
            'almacen_destino_id' => $destino->id,
            'cantidad' => $cantidad,
            'saldo_origen_anterior' => $origenAnterior,
            'saldo_origen_resultante' => $origenResultante,
            'saldo_destino_anterior' => $destinoAnterior,
            'saldo_destino_resultante' => $destinoResultante,
            'centro_costo' => $destino->centro_costo,
            'motivo' => trim((string) ($datos['motivo'] ?? 'Transferencia interna.')),
            'documento_relacionado' => $this->texto($datos['documento_relacionado'] ?? null),
            'user_id' => $usuario->id,
            'dispositivo_id' => $dispositivo?->id,
            'metadatos' => [
                'total_empresa_anterior' => $totalAnterior,
                'total_empresa_resultante' => (float) $proyeccion->cantidad_actual,
                'camara_destino_id' => $camara?->id,
                'posicion_destino_id' => $posicion?->id,
                'version_origen_resultante' => $versionOrigen,
                'version_destino_resultante' => $versionDestino,
            ],
        ]);
    }

    /** @param array<string, mixed> $datos */
    private function ajustar(
        FolioMaterial $folio,
        array $datos,
        User $usuario,
        ?Dispositivo $dispositivo,
        string $hash,
    ): MovimientoAlmacenMaterial {
        $cantidad = round((float) $datos['cantidad'], 3);

        if (abs($cantidad) <= 0.0001) {
            throw new DomainException('El ajuste debe ser distinto de cero.');
        }

        $almacen = $this->almacenBloqueado(
            $datos['almacen_origen_id'] ?? $datos['almacen_destino_id'] ?? null,
        );
        $this->almacenes->asegurarSaldo($folio, $almacen);
        $saldo = $this->saldoBloqueado($folio, $almacen);
        $anterior = (float) $saldo->cantidad_actual;
        $resultante = round($anterior + $cantidad, 3);

        if ($resultante < -0.0001
            || (float) $saldo->cantidad_reservada > $resultante + 0.0001) {
            throw new DomainException('El ajuste dejaría un saldo negativo o reservado en exceso.');
        }

        [$camara, $posicion] = $cantidad > 0
            ? $this->ubicacionDestino($almacen, $saldo, $datos)
            : [null, null];
        $totalAnterior = (float) $folio->cantidad_actual;
        $camaraAnterior = $saldo->camara_id;
        $version = (int) $saldo->version + 1;
        $saldo->update([
            'cantidad_actual' => max(0, $resultante),
            'camara_id' => $resultante > 0 && $almacen->tipo === TipoAlmacenMaterial::Fisica
                ? ($camara?->id ?? $saldo->camara_id)
                : null,
            'posicion_id' => $resultante > 0 && $almacen->tipo === TipoAlmacenMaterial::Fisica
                ? ($posicion?->id ?? $saldo->posicion_id)
                : null,
            'version' => $version,
        ]);
        $proyeccion = $this->almacenes->sincronizarProyeccion($folio);
        $this->incrementarPlanos([
            $resultante <= 0 ? $camaraAnterior : null,
            $cantidad > 0 && $camaraAnterior !== $camara?->id ? $camara?->id : null,
        ]);

        return $this->crearMovimiento([
            'operacion_id' => $datos['operacion_id'],
            'payload_hash' => $hash,
            'tipo' => TipoMovimientoAlmacenMaterial::Ajuste,
            'folio_id' => $folio->folio_id,
            'item_material_id' => $folio->item_material_id,
            'almacen_origen_id' => $cantidad < 0 ? $almacen->id : null,
            'almacen_destino_id' => $cantidad > 0 ? $almacen->id : null,
            'cantidad' => $cantidad,
            'saldo_origen_anterior' => $cantidad < 0 ? $anterior : null,
            'saldo_origen_resultante' => $cantidad < 0 ? max(0, $resultante) : null,
            'saldo_destino_anterior' => $cantidad > 0 ? $anterior : null,
            'saldo_destino_resultante' => $cantidad > 0 ? $resultante : null,
            'centro_costo' => $almacen->centro_costo,
            'motivo' => trim((string) $datos['motivo']),
            'documento_relacionado' => $this->texto($datos['documento_relacionado'] ?? null),
            'user_id' => $usuario->id,
            'dispositivo_id' => $dispositivo?->id,
            'metadatos' => [
                'total_empresa_anterior' => $totalAnterior,
                'total_empresa_resultante' => (float) $proyeccion->cantidad_actual,
                'requiere_autorizacion_supervision' => true,
                'saldo_version_resultante' => $version,
            ],
        ]);
    }

    /** @param array<string, mixed> $atributos */
    private function crearMovimiento(array $atributos): MovimientoAlmacenMaterial
    {
        return $this->cargar(MovimientoAlmacenMaterial::create([
            ...$atributos,
            'secuencia' => 1,
            'ocurrido_at' => now(),
        ]));
    }

    private function autorizar(TipoMovimientoAlmacenMaterial $tipo, User $usuario): void
    {
        $autorizado = $tipo === TipoMovimientoAlmacenMaterial::Ajuste
            ? $this->alcance->puedeGestionarBloqueosMateriales($usuario)
            : $this->alcance->puedeGestionarDespachosMateriales($usuario);

        if (! $autorizado) {
            throw new OperacionNoAutorizada(
                $tipo === TipoMovimientoAlmacenMaterial::Ajuste
                    ? 'Solo supervisión puede registrar ajustes de inventario.'
                    : 'El usuario no está autorizado para mover existencias entre almacenes.',
            );
        }
    }

    private function validarReintento(
        MovimientoAlmacenMaterial $movimiento,
        User $usuario,
        string $hash,
    ): MovimientoAlmacenMaterial {
        if ($movimiento->user_id !== $usuario->id
            || ! hash_equals($movimiento->payload_hash, $hash)) {
            throw new ConflictoOperacion('El UUID ya fue utilizado con datos diferentes.');
        }

        return $this->cargar($movimiento);
    }

    private function almacenBloqueado(?string $id): AlmacenMaterial
    {
        $almacen = AlmacenMaterial::query()
            ->whereKey($id)
            ->where('activo', true)
            ->lockForUpdate()
            ->first();

        if (! $almacen) {
            throw new DomainException('El almacén no existe o está inactivo.');
        }

        return $almacen;
    }

    /**
     * @param  array<int, ?string>  $ids
     * @return Collection<int, AlmacenMaterial>
     */
    private function almacenesBloqueados(array $ids): Collection
    {
        return AlmacenMaterial::query()
            ->whereIn('id', collect($ids)->filter()->unique()->sort()->values())
            ->where('activo', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function saldoBloqueado(
        FolioMaterial $folio,
        AlmacenMaterial $almacen,
    ): SaldoMaterialAlmacen {
        $saldo = SaldoMaterialAlmacen::query()
            ->with(['camara', 'posicion'])
            ->where('folio_id', $folio->folio_id)
            ->where('almacen_material_id', $almacen->id)
            ->lockForUpdate()
            ->first();

        if (! $saldo) {
            throw new DomainException('El folio no posee saldo en el almacén indicado.');
        }

        return $saldo;
    }

    private function validarDisponible(
        FolioMaterial $folio,
        SaldoMaterialAlmacen $saldo,
        AlmacenMaterial $almacen,
        bool $devolucion = false,
    ): void {
        if (! $devolucion) {
            $folio->asegurarVigente();
        }
        if (! $folio->folio?->activo
            || $folio->folio->estado_operacional === EstadoOperacionalFolio::Agotado
            || ($folio->motivo_bloqueo !== null
                && ! ($devolucion && $folio->bloqueado_por_vencimiento
                    && $folio->motivo_bloqueo_previo_vencimiento === null))) {
            throw new DomainException('El folio se encuentra agotado o bloqueado globalmente.');
        }

        if ($almacen->tipo === TipoAlmacenMaterial::Virtual) {
            if ($saldo->camara_id || $saldo->posicion_id) {
                throw new DomainException('Un almacén virtual no admite ubicación física.');
            }

            return;
        }

        if ($almacen->requiere_ubicacion_fisica
            && (! $saldo->camara
                || $saldo->camara->contenido !== ContenidoCamara::Materiales
                || $saldo->camara->estado !== EstadoCamara::Activa
                || ($saldo->posicion
                    && $saldo->posicion->estado !== EstadoPosicion::Activa))) {
            throw new DomainException('El saldo físico no posee una ubicación válida.');
        }
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array{0: ?Camara, 1: ?Posicion}
     */
    private function ubicacionDestino(
        AlmacenMaterial $almacen,
        SaldoMaterialAlmacen $saldo,
        array $datos,
    ): array {
        if ($almacen->tipo === TipoAlmacenMaterial::Virtual) {
            if (($datos['camara_destino_id'] ?? null)
                || ($datos['posicion_destino_id'] ?? null)) {
                throw new DomainException('Un almacén virtual no admite ubicación física.');
            }

            return [null, null];
        }

        $camaraId = $datos['camara_destino_id'] ?? $saldo->camara_id;
        $posicionId = $datos['posicion_destino_id'] ?? $saldo->posicion_id;

        if (! $camaraId) {
            if ($almacen->requiere_ubicacion_fisica) {
                throw new DomainException('La bodega física requiere una cámara de Materiales.');
            }

            return [null, null];
        }

        $camara = Camara::query()
            ->whereKey($camaraId)
            ->where('contenido', ContenidoCamara::Materiales->value)
            ->where('estado', EstadoCamara::Activa->value)
            ->lockForUpdate()
            ->first();

        if (! $camara) {
            throw new DomainException('La cámara indicada no es válida para Materiales.');
        }

        $posicion = $posicionId
            ? Posicion::query()
                ->whereKey($posicionId)
                ->where('camara_id', $camara->id)
                ->where('estado', EstadoPosicion::Activa->value)
                ->lockForUpdate()
                ->first()
            : null;

        if ($posicionId && ! $posicion) {
            throw new DomainException('La posición no pertenece a la cámara o está inactiva.');
        }

        return [$camara, $posicion];
    }

    private function validarFifo(
        FolioMaterial $folio,
        AlmacenMaterial $almacen,
        string $motivo,
    ): void {
        $consulta = SaldoMaterialAlmacen::query()
            ->join('folios_materiales as fm', 'fm.folio_id', '=', 'saldos_materiales_almacenes.folio_id')
            ->join('folios as f', 'f.id', '=', 'fm.folio_id')
            ->leftJoin('camaras as ca', 'ca.id', '=', 'saldos_materiales_almacenes.camara_id')
            ->leftJoin('posiciones as p', 'p.id', '=', 'saldos_materiales_almacenes.posicion_id')
            ->select('saldos_materiales_almacenes.folio_id')
            ->where('saldos_materiales_almacenes.almacen_material_id', $almacen->id)
            ->where('fm.item_material_id', $folio->item_material_id)
            ->whereColumn(
                'saldos_materiales_almacenes.cantidad_actual',
                '>',
                'saldos_materiales_almacenes.cantidad_reservada',
            )
            ->tap(fn ($consulta) => ServicioVencimientoMaterial::filtrarVigentes($consulta, 'fm.fecha_vencimiento'))
            ->whereNull('fm.motivo_bloqueo')
            ->where('f.activo', true);
        $this->filtrarUbicacionFifo($consulta, $almacen);
        $primero = $consulta
            ->orderByRaw('fm.fecha_vencimiento IS NULL')
            ->orderBy('fm.fecha_vencimiento')
            ->orderByRaw('fm.fecha_fabricacion IS NULL')
            ->orderBy('fm.fecha_fabricacion')
            ->orderBy('f.fecha_ingreso')
            ->orderBy('f.numero_folio')
            ->orderBy('f.id')
            ->first();

        if ($primero && $primero->folio_id !== $folio->folio_id && mb_strlen($motivo) < 5) {
            throw new DomainException(
                'Debe consumir el folio FIFO del almacén o justificar la excepción.',
            );
        }
    }

    private function filtrarUbicacionFifo(Builder $consulta, AlmacenMaterial $almacen): void
    {
        if ($almacen->tipo === TipoAlmacenMaterial::Virtual) {
            $consulta
                ->whereNull('saldos_materiales_almacenes.camara_id')
                ->whereNull('saldos_materiales_almacenes.posicion_id');

            return;
        }

        if ($almacen->requiere_ubicacion_fisica) {
            $consulta
                ->where('ca.contenido', ContenidoCamara::Materiales->value)
                ->where('ca.estado', EstadoCamara::Activa->value)
                ->where(fn (Builder $q) => $q
                    ->whereNull('saldos_materiales_almacenes.posicion_id')
                    ->orWhere('p.estado', EstadoPosicion::Activa->value));
        }
    }

    /** @param array<int, ?string> $ids */
    private function incrementarPlanos(array $ids): void
    {
        foreach (array_filter(array_unique($ids)) as $id) {
            Camara::query()->whereKey($id)->increment('version_plano');
        }
    }

    private function cantidadPositiva(mixed $valor): float
    {
        $cantidad = round((float) $valor, 3);

        if ($cantidad <= 0) {
            throw new DomainException('La cantidad debe ser mayor que cero.');
        }

        return $cantidad;
    }

    private function texto(mixed $valor): ?string
    {
        $texto = trim((string) ($valor ?? ''));

        return $texto !== '' ? $texto : null;
    }

    /** @param array<string, mixed> $payload */
    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->normalizar($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function normalizar(mixed $valor): mixed
    {
        if (! is_array($valor)) {
            return $valor;
        }

        if (array_is_list($valor)) {
            $valor = array_map(fn (mixed $item) => $this->normalizar($item), $valor);
            usort($valor, fn ($a, $b) => strcmp(
                json_encode($a, JSON_THROW_ON_ERROR),
                json_encode($b, JSON_THROW_ON_ERROR),
            ));

            return $valor;
        }

        ksort($valor, SORT_STRING);

        return array_map(fn (mixed $item) => $this->normalizar($item), $valor);
    }
}

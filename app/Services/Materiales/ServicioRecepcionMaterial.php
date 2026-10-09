<?php

namespace App\Services\Materiales;

use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoRecepcionMaterial;
use App\Enums\EstadoReservaMaterial;
use App\Enums\TipoBulto;
use App\Enums\TipoEventoRecepcionMaterial;
use App\Enums\TipoMovimientoInventarioMaterial;
use App\Exceptions\ConflictoOperacion;
use App\Exceptions\FotoDocumentoRecepcionRequerida;
use App\Models\BultoRecepcionMaterial;
use App\Models\Cliente;
use App\Models\ClienteProveedorMaterial;
use App\Models\DetalleRecepcionMaterial;
use App\Models\EliminacionRecepcionMaterial;
use App\Models\EventoRecepcionMaterial;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\ItemMaterial;
use App\Models\MovimientoInventarioMaterial;
use App\Models\ProveedorMaterial;
use App\Models\RecepcionMaterial;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use BackedEnum;
use DateTimeInterface;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;

class ServicioRecepcionMaterial
{
    public function __construct(
        private readonly ServicioTemporadaActiva $temporadaActiva,
        private readonly ServicioCorrelativoFolioMaterial $correlativoFolio,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos, User $usuario): RecepcionMaterial
    {
        $payloadHash = $this->payloadHash($datos);

        try {
            return DB::transaction(function () use ($datos, $usuario, $payloadHash): RecepcionMaterial {
                $existente = RecepcionMaterial::query()
                    ->where('operacion_id', $datos['operacion_id'])
                    ->lockForUpdate()
                    ->first();

                if ($existente) {
                    if ($existente->creado_por_user_id !== $usuario->id
                        || ! $this->coincidePayloadCreacion(
                            $existente->payload_hash,
                            $datos,
                            $payloadHash,
                        )) {
                        throw new ConflictoOperacion(
                            'El UUID de creación ya fue utilizado con datos diferentes.',
                        );
                    }

                    return $this->cargar($existente);
                }

                $temporada = $this->temporadaActiva->obtener(bloquear: true);
                [$cliente, $proveedor, $vinculoProveedor] = $this->validarCabecera(
                    $datos['cliente_id'],
                    $datos['proveedor_material_id'],
                );
                $recepcion = RecepcionMaterial::create([
                    'operacion_id' => $datos['operacion_id'],
                    'payload_hash' => $payloadHash,
                    'temporada_id' => $temporada->id,
                    'cliente_id' => $cliente->id,
                    'proveedor_material_id' => $proveedor->id,
                    'numero_guia_despacho' => trim($datos['numero_guia_despacho']),
                    'fecha_documento' => $datos['fecha_documento'] ?? null,
                    'orden_compra' => $this->textoOpcional($datos['orden_compra'] ?? null),
                    'patente' => $this->textoOpcional($datos['patente'] ?? null),
                    'transportista' => $this->textoOpcional($datos['transportista'] ?? null),
                    'estado' => EstadoRecepcionMaterial::Borrador,
                    'version' => 1,
                    'observacion' => $this->textoOpcional($datos['observacion'] ?? null),
                    'creado_por_user_id' => $usuario->id,
                ]);

                foreach ($datos['detalles'] as $linea) {
                    $this->crearDetalle($recepcion, $linea, $cliente, $proveedor, $vinculoProveedor, $temporada->id);
                }

                $this->registrarEvento(
                    $recepcion,
                    TipoEventoRecepcionMaterial::Creada,
                    $usuario,
                    $datos['operacion_id'],
                    ['numero_guia_despacho' => $recepcion->numero_guia_despacho],
                );

                return $this->cargar($recepcion);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existente = RecepcionMaterial::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->first();

            if ($existente
                && $existente->creado_por_user_id === $usuario->id
                && $this->coincidePayloadCreacion(
                    $existente->payload_hash,
                    $datos,
                    $payloadHash,
                )) {
                return $this->cargar($existente);
            }

            throw new ConflictoOperacion(
                'La recepción entró en conflicto con otra operación concurrente.',
                previous: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(
        RecepcionMaterial $recepcion,
        array $datos,
        User $usuario,
    ): RecepcionMaterial {
        $payloadHash = $this->payloadHash($datos);

        try {
            return DB::transaction(function () use (
                $recepcion,
                $datos,
                $usuario,
                $payloadHash,
            ): RecepcionMaterial {
                $eventoExistente = EventoRecepcionMaterial::query()
                    ->where('operacion_id', $datos['operacion_id'])
                    ->lockForUpdate()
                    ->first();

                if ($eventoExistente) {
                    $hashExistente = (string) data_get($eventoExistente->datos, 'payload_hash', '');
                    $mismaOperacion = $eventoExistente->recepcion_material_id === $recepcion->id
                        && $eventoExistente->tipo === TipoEventoRecepcionMaterial::Actualizada
                        && $eventoExistente->user_id === $usuario->id
                        && $hashExistente !== ''
                        && hash_equals($hashExistente, $payloadHash);

                    if (! $mismaOperacion) {
                        throw new ConflictoOperacion(
                            'El UUID de actualización ya fue utilizado con datos diferentes.',
                        );
                    }

                    return $this->cargar(RecepcionMaterial::query()->findOrFail($recepcion->id));
                }

                $recepcion = RecepcionMaterial::query()
                    ->with(['detalles.bultos.folioMaterial'])
                    ->lockForUpdate()
                    ->findOrFail($recepcion->id);

                if ($recepcion->estado !== EstadoRecepcionMaterial::Borrador) {
                    throw new DomainException('Solo una recepción en borrador puede editarse.');
                }

                if ($recepcion->version !== (int) $datos['version_conocida']) {
                    throw new ConflictoOperacion('La recepción cambió desde la última lectura.');
                }

                $temporada = $this->temporadaActiva->obtener(bloquear: true);

                if ($temporada->id !== $recepcion->temporada_id) {
                    throw new DomainException(
                        'La temporada de la recepción ya no es la temporada global activa.',
                    );
                }

                [$cliente, $proveedor, $vinculoProveedor] = $this->validarCabecera(
                    $datos['cliente_id'],
                    $datos['proveedor_material_id'],
                );
                $bultosActuales = $recepcion->detalles
                    ->flatMap(fn (DetalleRecepcionMaterial $detalle) => $detalle->bultos);

                if ($bultosActuales->contains(
                    fn (BultoRecepcionMaterial $bulto): bool => $bulto->folioMaterial !== null,
                )) {
                    throw new DomainException(
                        'La recepción ya posee inventario asociado y no puede volver a editarse.',
                    );
                }

                $versionAnterior = $recepcion->version;
                $ahora = now();
                $bultoIds = $bultosActuales->pluck('id');
                $detalleIds = $recepcion->detalles->pluck('id');

                if ($bultoIds->isNotEmpty()) {
                    BultoRecepcionMaterial::query()
                        ->whereIn('id', $bultoIds)
                        ->update([
                            'deleted_at' => $ahora,
                            'updated_at' => $ahora,
                        ]);
                }

                if ($detalleIds->isNotEmpty()) {
                    DetalleRecepcionMaterial::query()
                        ->whereIn('id', $detalleIds)
                        ->update([
                            'deleted_at' => $ahora,
                            'updated_at' => $ahora,
                        ]);
                }

                $recepcion->update([
                    'cliente_id' => $cliente->id,
                    'proveedor_material_id' => $proveedor->id,
                    'numero_guia_despacho' => trim($datos['numero_guia_despacho']),
                    'fecha_documento' => $datos['fecha_documento'] ?? null,
                    'orden_compra' => $this->textoOpcional($datos['orden_compra'] ?? null),
                    'patente' => $this->textoOpcional($datos['patente'] ?? null),
                    'transportista' => $this->textoOpcional($datos['transportista'] ?? null),
                    'observacion' => $this->textoOpcional($datos['observacion'] ?? null),
                    'version' => $versionAnterior + 1,
                ]);

                foreach ($datos['detalles'] as $linea) {
                    $this->crearDetalle(
                        $recepcion,
                        $linea,
                        $cliente,
                        $proveedor,
                        $vinculoProveedor,
                        $temporada->id,
                    );
                }

                $this->registrarEvento(
                    $recepcion,
                    TipoEventoRecepcionMaterial::Actualizada,
                    $usuario,
                    $datos['operacion_id'],
                    [
                        'payload_hash' => $payloadHash,
                        'version_anterior' => $versionAnterior,
                        'version_resultante' => $recepcion->version,
                        'cantidad_detalles' => count($datos['detalles']),
                        'cantidad_bultos' => collect($datos['detalles'])
                            ->sum(fn (array $linea): int => count($linea['bultos'] ?? [])),
                    ],
                );

                return $this->cargar($recepcion->refresh());
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            $evento = EventoRecepcionMaterial::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->first();
            $hashExistente = (string) data_get($evento?->datos, 'payload_hash', '');

            if ($evento
                && $evento->recepcion_material_id === $recepcion->id
                && $evento->tipo === TipoEventoRecepcionMaterial::Actualizada
                && $evento->user_id === $usuario->id
                && $hashExistente !== ''
                && hash_equals($hashExistente, $payloadHash)) {
                return $this->cargar(RecepcionMaterial::query()->findOrFail($recepcion->id));
            }

            throw new ConflictoOperacion(
                'La actualización entró en conflicto con otra operación concurrente.',
                previous: $exception,
            );
        }
    }

    public function confirmar(
        RecepcionMaterial $recepcion,
        string $operacionId,
        int $versionConocida,
        User $usuario,
        bool $exigirFotoDocumento = true,
    ): RecepcionMaterial {
        $payload = [
            'recepcion_material_id' => $recepcion->id,
            'version_conocida' => $versionConocida,
        ];
        $payloadHash = $this->payloadHash($payload);

        return DB::transaction(function () use (
            $recepcion,
            $operacionId,
            $versionConocida,
            $usuario,
            $payloadHash,
            $exigirFotoDocumento,
        ): RecepcionMaterial {
            $recepcion = RecepcionMaterial::query()
                ->with(['detalles.bultos', 'cliente', 'proveedor'])
                ->lockForUpdate()
                ->findOrFail($recepcion->id);

            if ($recepcion->confirmacion_operacion_id !== null) {
                $mismaOperacion = $recepcion->confirmacion_operacion_id === $operacionId
                    && $recepcion->confirmado_por_user_id === $usuario->id
                    && hash_equals((string) $recepcion->confirmacion_payload_hash, $payloadHash);

                if (! $mismaOperacion) {
                    throw new ConflictoOperacion(
                        'La recepción ya fue confirmada con una operación diferente.',
                    );
                }

                return $this->cargar($recepcion);
            }

            if ($recepcion->estado !== EstadoRecepcionMaterial::Borrador) {
                throw new DomainException('La recepción ya no se encuentra en borrador.');
            }

            if ($recepcion->version !== $versionConocida) {
                throw new ConflictoOperacion('La recepción cambió desde la última lectura.');
            }

            $fotos = $recepcion->fotos()->orderBy('tipo')->orderBy('orden')->lockForUpdate()->get();
            if ($exigirFotoDocumento && ! $fotos->contains('tipo', 'documento')) {
                throw new FotoDocumentoRecepcionRequerida;
            }

            $temporada = $this->temporadaActiva->obtener(bloquear: true);

            if ($temporada->id !== $recepcion->temporada_id) {
                throw new DomainException(
                    'La temporada de la recepción ya no es la temporada global activa.',
                );
            }

            $requiereFolio = $recepcion->detalles->contains(
                fn (DetalleRecepcionMaterial $detalle): bool => $detalle->bultos->isNotEmpty(),
            );
            [$cliente, $proveedor, $vinculoProveedor] = $this->validarCabecera(
                $recepcion->cliente_id,
                $recepcion->proveedor_material_id,
                exigirCodigoFolio: $requiereFolio,
            );

            if ($recepcion->detalles->isEmpty()) {
                throw new DomainException('La recepción no contiene detalles para confirmar.');
            }

            $foliosGenerados = [];
            $ahora = now();

            foreach ($recepcion->detalles as $detalle) {
                $item = $this->validarItem(
                    $detalle->item_material_id,
                    $cliente,
                    $proveedor,
                    $vinculoProveedor,
                    $recepcion->temporada_id,
                    bloquear: true,
                );
                $sumaBultos = round($detalle->bultos->sum(
                    fn (BultoRecepcionMaterial $bulto): float => (float) $bulto->cantidad,
                ), 3);

                if (abs($sumaBultos - (float) $detalle->cantidad_recibida) > 0.0001) {
                    throw new DomainException(sprintf(
                        'La distribución física del ítem %s no coincide con la cantidad aceptada.',
                        $item->codigo,
                    ));
                }

                if ((float) $detalle->cantidad_recibida > 0 && $detalle->bultos->isEmpty()) {
                    throw new DomainException(sprintf(
                        'El ítem %s posee cantidad aceptada, pero no tiene bultos físicos definidos.',
                        $item->codigo,
                    ));
                }

                foreach ($detalle->bultos as $bulto) {
                    $codigoFolio = $this->correlativoFolio->siguiente($cliente);
                    $folio = Folio::create([
                        'temporada_id' => $recepcion->temporada_id,
                        'numero_folio' => $codigoFolio,
                        'tipo_bulto' => TipoBulto::Material,
                        'estado_operacional' => $bulto->bloqueado
                            ? EstadoOperacionalFolio::Bloqueado
                            : EstadoOperacionalFolio::PendienteUbicacion,
                        'fecha_ingreso' => $ahora,
                        'activo' => true,
                        'origen_sistema' => 'recepcion_materiales',
                        'identificador_externo' => $bulto->id,
                        'datos_externos' => [
                            'recepcion_material_id' => $recepcion->id,
                            'guia_despacho' => $recepcion->numero_guia_despacho,
                            'cliente' => [
                                'id' => $cliente->id,
                                'codigo' => $cliente->codigo,
                                'nombre' => $cliente->nombre,
                            ],
                            'proveedor' => [
                                'id' => $proveedor->id,
                                'codigo' => $proveedor->codigo,
                                'nombre' => $proveedor->nombre,
                            ],
                        ],
                    ]);
                    $material = FolioMaterial::create([
                        'folio_id' => $folio->id,
                        'item_material_id' => $item->id,
                        'bulto_recepcion_material_id' => $bulto->id,
                        'proveedor_material_id' => $proveedor->id,
                        'categoria_operacional' => $detalle->categoria_operacional,
                        'cantidad_inicial' => $bulto->cantidad,
                        'cantidad_actual' => $bulto->cantidad,
                        'cantidad_reservada' => 0,
                        'unidad_medida' => $detalle->unidad_medida,
                        'lote' => $bulto->lote_proveedor,
                        'fecha_fabricacion' => $bulto->fecha_fabricacion,
                        'fecha_vencimiento' => $bulto->fecha_vencimiento,
                        'proveedor' => $proveedor->nombre,
                        'observacion' => $detalle->observacion,
                        'motivo_bloqueo' => $bulto->motivo_bloqueo,
                    ]);
                    MovimientoInventarioMaterial::create([
                        'folio_id' => $folio->id,
                        'item_material_id' => $item->id,
                        'tipo' => TipoMovimientoInventarioMaterial::IngresoRecepcion,
                        'cantidad' => $bulto->cantidad,
                        'cantidad_anterior' => 0,
                        'cantidad_resultante' => $bulto->cantidad,
                        'user_id' => $usuario->id,
                        'motivo' => 'Ingreso confirmado desde recepción de materiales.',
                        'metadatos' => [
                            'recepcion_material_id' => $recepcion->id,
                            'detalle_recepcion_material_id' => $detalle->id,
                            'bulto_recepcion_material_id' => $bulto->id,
                            'numero_guia_despacho' => $recepcion->numero_guia_despacho,
                            'estado_ubicacion' => 'pendiente_ubicacion',
                        ],
                        'ocurrido_at' => $ahora,
                    ]);
                    $foliosGenerados[] = [
                        'id' => $folio->id,
                        'numero_folio' => $codigoFolio,
                        'item_material_id' => $item->id,
                        'cantidad' => $material->cantidad_inicial,
                        'bloqueado' => $bulto->bloqueado,
                    ];
                }
            }

            $snapshot = [
                'temporada' => [
                    'id' => $temporada->id,
                    'codigo' => $temporada->codigo,
                    'nombre' => $temporada->nombre,
                ],
                'cliente' => [
                    'id' => $cliente->id,
                    'codigo' => $cliente->codigo,
                    'codigo_folio_materiales' => $cliente->codigo_folio_materiales,
                    'nombre' => $cliente->nombre,
                ],
                'proveedor' => [
                    'id' => $proveedor->id,
                    'codigo' => $proveedor->codigo,
                    'nombre' => $proveedor->nombre,
                ],
                'numero_guia_despacho' => $recepcion->numero_guia_despacho,
                'folios' => $foliosGenerados,
                'fotos' => $fotos->map(fn ($foto) => $foto->only(['id', 'tipo', 'orden', 'sha256', 'bytes']))->values()->all(),
            ];
            if (! $exigirFotoDocumento && ! $fotos->contains('tipo', 'documento')) {
                $snapshot['sin_foto_documento'] = true;
            }
            $recepcion->update([
                'estado' => EstadoRecepcionMaterial::Confirmada,
                'version' => $recepcion->version + 1,
                'snapshot_confirmacion' => $snapshot,
                'confirmacion_operacion_id' => $operacionId,
                'confirmacion_payload_hash' => $payloadHash,
                'confirmado_por_user_id' => $usuario->id,
                'confirmado_at' => $ahora,
            ]);
            $this->registrarEvento(
                $recepcion,
                TipoEventoRecepcionMaterial::Confirmada,
                $usuario,
                $operacionId,
                ['folios' => $foliosGenerados],
            );

            return $this->cargar($recepcion->refresh());
        }, attempts: 3);
    }

    public function anular(
        RecepcionMaterial $recepcion,
        string $operacionId,
        string $motivo,
        User $usuario,
    ): RecepcionMaterial {
        $motivo = trim($motivo);
        $payloadHash = $this->payloadHash([
            'recepcion_material_id' => $recepcion->id,
            'motivo' => $motivo,
        ]);

        return DB::transaction(function () use (
            $recepcion,
            $operacionId,
            $motivo,
            $usuario,
            $payloadHash,
        ): RecepcionMaterial {
            $recepcion = RecepcionMaterial::query()
                ->with([
                    'detalles.bultos.folioMaterial.folio.ubicacionActual',
                    'detalles.bultos.folioMaterial.reservas',
                    'detalles.bultos.folioMaterial.retiros',
                    'detalles.bultos.folioMaterial.correccionesItem',
                ])
                ->lockForUpdate()
                ->findOrFail($recepcion->id);

            if ($recepcion->anulacion_operacion_id !== null) {
                $mismaOperacion = $recepcion->anulacion_operacion_id === $operacionId
                    && $recepcion->anulado_por_user_id === $usuario->id
                    && hash_equals((string) $recepcion->anulacion_payload_hash, $payloadHash);

                if (! $mismaOperacion) {
                    throw new ConflictoOperacion(
                        'La recepción ya fue anulada con una operación diferente.',
                    );
                }

                return $this->cargar($recepcion);
            }

            if ($recepcion->estado === EstadoRecepcionMaterial::Anulada) {
                throw new DomainException('La recepción ya se encontraba anulada.');
            }

            $ahora = now();

            if ($recepcion->estado === EstadoRecepcionMaterial::Confirmada) {
                foreach ($recepcion->detalles->flatMap->bultos as $bulto) {
                    $material = $bulto->folioMaterial;

                    if (! $material || ! $material->folio) {
                        throw new DomainException(
                            'La recepción confirmada posee un bulto sin folio de inventario.',
                        );
                    }

                    if ($material->folio->ubicacionActual) {
                        throw new DomainException(
                            'No se puede anular una recepción con folios ya ubicados.',
                        );
                    }

                    if ($material->reservas
                        ->contains(fn ($reserva): bool => $reserva->estado === EstadoReservaMaterial::Activa)) {
                        throw new DomainException(
                            'No se puede anular una recepción con reservas activas.',
                        );
                    }

                    if ($material->retiros->isNotEmpty() || $material->correccionesItem->isNotEmpty()) {
                        throw new DomainException(
                            'No se puede anular una recepción con retiros o correcciones posteriores.',
                        );
                    }

                    if (abs((float) $material->cantidad_actual - (float) $material->cantidad_inicial) > 0.0001
                        || (float) $material->cantidad_reservada > 0.0001) {
                        throw new DomainException(
                            'La recepción posee folios cuyo saldo ya fue modificado.',
                        );
                    }

                    MovimientoInventarioMaterial::create([
                        'folio_id' => $material->folio_id,
                        'item_material_id' => $material->item_material_id,
                        'tipo' => TipoMovimientoInventarioMaterial::AnulacionRecepcion,
                        'cantidad' => -(float) $material->cantidad_actual,
                        'cantidad_anterior' => $material->cantidad_actual,
                        'cantidad_resultante' => 0,
                        'user_id' => $usuario->id,
                        'motivo' => $motivo,
                        'metadatos' => [
                            'recepcion_material_id' => $recepcion->id,
                            'bulto_recepcion_material_id' => $bulto->id,
                            'numero_guia_despacho' => $recepcion->numero_guia_despacho,
                        ],
                        'ocurrido_at' => $ahora,
                    ]);
                    $material->update([
                        'cantidad_actual' => 0,
                        'cantidad_reservada' => 0,
                    ]);
                    $material->folio->update([
                        'estado_operacional' => EstadoOperacionalFolio::Anulado,
                        'activo' => false,
                    ]);
                }
            }

            $recepcion->update([
                'estado' => EstadoRecepcionMaterial::Anulada,
                'version' => $recepcion->version + 1,
                'anulacion_operacion_id' => $operacionId,
                'anulacion_payload_hash' => $payloadHash,
                'anulado_por_user_id' => $usuario->id,
                'anulado_at' => $ahora,
                'motivo_anulacion' => $motivo,
            ]);
            $this->registrarEvento(
                $recepcion,
                TipoEventoRecepcionMaterial::Anulada,
                $usuario,
                $operacionId,
                observacion: $motivo,
            );

            return $this->cargar($recepcion->refresh());
        }, attempts: 3);
    }

    /**
     * Corrige una recepción desde la oficina de administración.
     *
     * Las recepciones confirmadas se desmontan y reconstruyen dentro de la misma
     * transacción. Los folios originales se liberan antes de confirmar nuevamente,
     * por lo que conservan su numeración siempre que el nuevo detalle los requiera.
     *
     * @param  array<string, mixed>  $datos
     */
    public function corregirAdministrativamente(
        RecepcionMaterial $recepcion,
        array $datos,
        User $usuario,
    ): RecepcionMaterial {
        $motivo = trim((string) $datos['motivo_correccion']);
        $operacionId = (string) $datos['operacion_id'];
        $payloadHash = $this->payloadHash($datos);

        return DB::transaction(function () use (
            $recepcion,
            $datos,
            $usuario,
            $motivo,
            $operacionId,
            $payloadHash,
        ): RecepcionMaterial {
            $eventoExistente = EventoRecepcionMaterial::query()
                ->where('operacion_id', $operacionId)
                ->lockForUpdate()
                ->first();

            if ($eventoExistente) {
                $mismaOperacion = $eventoExistente->recepcion_material_id === $recepcion->id
                    && $eventoExistente->tipo === TipoEventoRecepcionMaterial::CorregidaAdministrativamente
                    && $eventoExistente->user_id === $usuario->id
                    && hash_equals(
                        (string) data_get($eventoExistente->datos, 'payload_hash', ''),
                        $payloadHash,
                    );

                if (! $mismaOperacion) {
                    throw new ConflictoOperacion(
                        'El UUID de corrección ya fue utilizado con datos diferentes.',
                    );
                }

                return $this->cargar(RecepcionMaterial::query()->findOrFail($recepcion->id));
            }

            $recepcion = RecepcionMaterial::query()
                ->with(['cliente', 'detalles.bultos.folioMaterial.folio'])
                ->lockForUpdate()
                ->findOrFail($recepcion->id);

            if ($recepcion->estado === EstadoRecepcionMaterial::Anulada) {
                throw new DomainException(
                    'Una recepción anulada no puede editarse; el administrador puede eliminarla y volver a ingresarla.',
                );
            }

            if ($recepcion->version !== (int) $datos['version_conocida']) {
                throw new ConflictoOperacion('La recepción cambió desde la última lectura.');
            }

            $estadoAnterior = $recepcion->estado;
            $foliosAnteriores = collect();
            $impresionesAnteriores = collect();

            if ($estadoAnterior === EstadoRecepcionMaterial::Confirmada) {
                if (empty($datos['confirmacion_operacion_id'])) {
                    throw new DomainException(
                        'La corrección de una recepción confirmada requiere un UUID de reconfirmación.',
                    );
                }

                $foliosAnteriores = $this->foliosDeRecepcion($recepcion);
                $impresionesAnteriores = DB::table('trabajos_impresion_materiales')
                    ->where('recepcion_material_id', $recepcion->id)
                    ->orderBy('solicitado_at')
                    ->get(['id', 'operacion_id', 'formato', 'simbologia', 'estado', 'copias']);
                $this->validarFoliosAdministrables($foliosAnteriores, anulada: false);
                $this->desmontarInventarioRecepcion(
                    $recepcion,
                    $foliosAnteriores,
                    $usuario,
                    $motivo,
                );
                $recepcion->forceFill([
                    'estado' => EstadoRecepcionMaterial::Borrador,
                    'snapshot_confirmacion' => null,
                    'confirmacion_operacion_id' => null,
                    'confirmacion_payload_hash' => null,
                    'confirmado_por_user_id' => null,
                    'confirmado_at' => null,
                ])->save();
            }

            $datosActualizacion = $datos;
            $datosActualizacion['operacion_id'] = $this->uuidDeterminista(
                'correccion-recepcion-material',
                $operacionId,
            );
            $actualizada = $this->actualizar($recepcion, $datosActualizacion, $usuario);

            if ($estadoAnterior === EstadoRecepcionMaterial::Confirmada) {
                $actualizada = $this->confirmar(
                    $actualizada,
                    (string) $datos['confirmacion_operacion_id'],
                    $actualizada->version,
                    $usuario,
                    exigirFotoDocumento: false,
                );
            }

            $this->registrarEvento(
                $actualizada,
                TipoEventoRecepcionMaterial::CorregidaAdministrativamente,
                $usuario,
                $operacionId,
                [
                    'payload_hash' => $payloadHash,
                    'estado_anterior' => $estadoAnterior->value,
                    'estado_resultante' => $actualizada->estado->value,
                    'folios_anteriores' => $foliosAnteriores->pluck('numero_folio')->values()->all(),
                    'folios_resultantes' => collect(data_get(
                        $actualizada->snapshot_confirmacion,
                        'folios',
                        [],
                    ))->pluck('numero_folio')->values()->all(),
                    'impresiones_invalidadas' => $impresionesAnteriores
                        ->map(fn (object $trabajo): array => (array) $trabajo)
                        ->values()
                        ->all(),
                ],
                $motivo,
            );

            return $this->cargar($actualizada->refresh());
        }, attempts: 3);
    }

    /**
     * Elimina físicamente una recepción administrable y conserva una auditoría
     * separada. Los números de sus folios pasan a la bolsa reutilizable del cliente.
     *
     * @return array{recepcion_id: string, folios_liberados: array<int, string>}
     */
    public function eliminarAdministrativamente(
        RecepcionMaterial $recepcion,
        string $operacionId,
        int $versionConocida,
        string $motivo,
        User $usuario,
    ): array {
        $motivo = trim($motivo);

        return DB::transaction(function () use (
            $recepcion,
            $operacionId,
            $versionConocida,
            $motivo,
            $usuario,
        ): array {
            if (EliminacionRecepcionMaterial::query()
                ->where('operacion_id', $operacionId)
                ->exists()) {
                throw new ConflictoOperacion('La eliminación ya fue procesada.');
            }

            $recepcion = RecepcionMaterial::query()
                ->with([
                    'temporada',
                    'cliente',
                    'proveedor',
                    'creadoPor',
                    'confirmadoPor',
                    'anuladoPor',
                    'detalles' => fn ($consulta) => $consulta->withTrashed(),
                    'detalles.item',
                    'detalles.bultos' => fn ($consulta) => $consulta->withTrashed(),
                    'detalles.bultos.folioMaterial.folio',
                    'eventos.usuario',
                ])
                ->lockForUpdate()
                ->findOrFail($recepcion->id);

            if ($recepcion->version !== $versionConocida) {
                throw new ConflictoOperacion('La recepción cambió desde la última lectura.');
            }

            $folios = $this->foliosDeRecepcion($recepcion);
            $this->validarFoliosAdministrables(
                $folios,
                anulada: $recepcion->estado === EstadoRecepcionMaterial::Anulada,
            );
            $snapshot = $this->snapshotEliminacion($recepcion, $folios);
            $numeroFolios = $folios->pluck('numero_folio')->values()->all();

            if ($folios->isNotEmpty()) {
                $this->desmontarInventarioRecepcion(
                    $recepcion,
                    $folios,
                    $usuario,
                    $motivo,
                );
            }

            $eliminacion = EliminacionRecepcionMaterial::create([
                'operacion_id' => $operacionId,
                'recepcion_material_id_original' => $recepcion->id,
                'temporada_id' => $recepcion->temporada_id,
                'cliente_id' => $recepcion->cliente_id,
                'proveedor_material_id' => $recepcion->proveedor_material_id,
                'numero_guia_despacho' => $recepcion->numero_guia_despacho,
                'motivo' => $motivo,
                'folios' => $numeroFolios,
                'snapshot' => $snapshot,
                'eliminado_por_user_id' => $usuario->id,
                'eliminado_at' => now(),
            ]);

            app(ServicioFotosRecepcionMaterial::class)->archivar($recepcion, $eliminacion);

            $detalleIds = DB::table('detalles_recepciones_materiales')
                ->where('recepcion_material_id', $recepcion->id)
                ->pluck('id');

            if ($detalleIds->isNotEmpty()) {
                DB::table('bultos_recepciones_materiales')
                    ->whereIn('detalle_recepcion_material_id', $detalleIds)
                    ->delete();
                DB::table('detalles_recepciones_materiales')
                    ->whereIn('id', $detalleIds)
                    ->delete();
            }

            DB::table('eventos_recepciones_materiales')
                ->where('recepcion_material_id', $recepcion->id)
                ->delete();
            DB::table('recepciones_materiales')
                ->where('id', $recepcion->id)
                ->delete();

            return [
                'recepcion_id' => $recepcion->id,
                'folios_liberados' => $numeroFolios,
            ];
        }, attempts: 3);
    }

    public function cargar(RecepcionMaterial $recepcion): RecepcionMaterial
    {
        return $recepcion->load([
            'fotos' => fn ($consulta) => $consulta->orderBy('tipo')->orderBy('orden'),
            'temporada:id,codigo,nombre,activa',
            'cliente:id,codigo,nombre,codigo_folio_materiales,activo',
            'proveedor:id,codigo,nombre,activo',
            'creadoPor:id,name',
            'confirmadoPor:id,name',
            'anuladoPor:id,name',
            'detalles.item.cliente.cliente:id,codigo,nombre',
            'detalles.bultos.folioMaterial.folio.ubicacionActual.posicion.camara',
            'eventos.usuario:id,name',
        ]);
    }

    /**
     * @return Collection<int, object>
     */
    private function foliosDeRecepcion(RecepcionMaterial $recepcion): Collection
    {
        return DB::table('folios as f')
            ->join('folios_materiales as fm', 'fm.folio_id', '=', 'f.id')
            ->join(
                'bultos_recepciones_materiales as brm',
                'brm.id',
                '=',
                'fm.bulto_recepcion_material_id',
            )
            ->join(
                'detalles_recepciones_materiales as drm',
                'drm.id',
                '=',
                'brm.detalle_recepcion_material_id',
            )
            ->where('drm.recepcion_material_id', $recepcion->id)
            ->orderBy('f.numero_folio')
            ->lockForUpdate()
            ->get([
                'f.id',
                'f.numero_folio',
                'f.estado_operacional',
                'f.activo',
                'fm.cantidad_inicial',
                'fm.cantidad_actual',
                'fm.cantidad_reservada',
            ]);
    }

    /**
     * @param  Collection<int, object>  $folios
     */
    private function validarFoliosAdministrables(Collection $folios, bool $anulada): void
    {
        if ($folios->isEmpty()) {
            return;
        }

        $folioIds = $folios->pluck('id');

        foreach ($folios as $folio) {
            $cantidadEsperada = $anulada ? 0.0 : (float) $folio->cantidad_inicial;

            if (abs((float) $folio->cantidad_actual - $cantidadEsperada) > 0.0001
                || (float) $folio->cantidad_reservada > 0.0001) {
                throw new DomainException(
                    'No se puede editar o eliminar una recepción cuyo inventario ya cambió.',
                );
            }
        }

        $tiposPermitidos = [
            TipoMovimientoInventarioMaterial::IngresoRecepcion->value,
            ...($anulada ? [TipoMovimientoInventarioMaterial::AnulacionRecepcion->value] : []),
        ];
        $movimientoPosterior = DB::table('movimientos_inventario_materiales')
            ->whereIn('folio_id', $folioIds)
            ->whereNotIn('tipo', $tiposPermitidos)
            ->exists();

        if ($movimientoPosterior) {
            throw new DomainException(
                'No se puede editar o eliminar una recepción con movimientos de inventario posteriores.',
            );
        }

        $dependencias = [
            ['reservas_materiales', 'folio_id'],
            ['retiros_materiales', 'folio_id'],
            ['correcciones_items_folios_materiales', 'folio_id'],
            ['eventos_bloqueos_materiales', 'folio_id'],
            ['reservas_transformacion_materiales', 'folio_id'],
            ['consumos_transformacion_materiales', 'folio_id'],
            ['salidas_transformacion_materiales', 'folio_id'],
            ['ubicaciones_actuales', 'folio_id'],
            ['movimientos', 'folio_id'],
            ['carga_folios', 'folio_id'],
            ['eventos_carga', 'folio_id'],
            ['reservas_carga_folio', 'folio_id'],
            ['validaciones_pallet', 'folio_id'],
            ['procesos_prefrio_folios', 'folio_id'],
            ['historial_habilitaciones_almacenamiento', 'folio_id'],
            ['migraciones_temporadas_folios', 'folio_id'],
            ['notificaciones_operacionales', 'folio_id'],
        ];

        foreach ($dependencias as [$tabla, $columna]) {
            if (Schema::hasTable($tabla)
                && Schema::hasColumn($tabla, $columna)
                && DB::table($tabla)->whereIn($columna, $folioIds)->exists()) {
                throw new DomainException(
                    'No se puede editar o eliminar la recepción porque uno de sus folios ya participa en otro proceso.',
                );
            }
        }
    }

    /**
     * @param  Collection<int, object>  $folios
     */
    private function desmontarInventarioRecepcion(
        RecepcionMaterial $recepcion,
        Collection $folios,
        User $usuario,
        string $motivo,
    ): void {
        $folioIds = $folios->pluck('id');
        $trabajoIds = DB::table('trabajos_impresion_materiales')
            ->where('recepcion_material_id', $recepcion->id)
            ->pluck('id');

        if ($trabajoIds->isNotEmpty()) {
            DB::table('folios_trabajos_impresion_materiales')
                ->whereIn('trabajo_impresion_material_id', $trabajoIds)
                ->delete();
            DB::table('trabajos_impresion_materiales')
                ->whereIn('id', $trabajoIds)
                ->delete();
        }

        DB::table('movimientos_inventario_materiales')
            ->whereIn('folio_id', $folioIds)
            ->delete();
        DB::table('folios_materiales')
            ->whereIn('folio_id', $folioIds)
            ->delete();
        DB::table('folios')
            ->whereIn('id', $folioIds)
            ->delete();

        $cliente = $recepcion->cliente
            ?? Cliente::query()->lockForUpdate()->findOrFail($recepcion->cliente_id);

        foreach ($folios as $folio) {
            $this->correlativoFolio->liberar(
                $cliente,
                $folio->numero_folio,
                $recepcion,
                $usuario,
                $motivo,
            );
        }
    }

    /**
     * @param  Collection<int, object>  $folios
     * @return array<string, mixed>
     */
    private function snapshotEliminacion(
        RecepcionMaterial $recepcion,
        Collection $folios,
    ): array {
        return [
            'cabecera' => [
                'id' => $recepcion->id,
                'operacion_id' => $recepcion->operacion_id,
                'temporada_id' => $recepcion->temporada_id,
                'cliente_id' => $recepcion->cliente_id,
                'proveedor_material_id' => $recepcion->proveedor_material_id,
                'numero_guia_despacho' => $recepcion->numero_guia_despacho,
                'fecha_documento' => $recepcion->fecha_documento?->toDateString(),
                'orden_compra' => $recepcion->orden_compra,
                'patente' => $recepcion->patente,
                'transportista' => $recepcion->transportista,
                'estado' => $recepcion->estado->value,
                'version' => $recepcion->version,
                'observacion' => $recepcion->observacion,
                'snapshot_confirmacion' => $recepcion->snapshot_confirmacion,
                'creado_por_user_id' => $recepcion->creado_por_user_id,
                'confirmado_por_user_id' => $recepcion->confirmado_por_user_id,
                'confirmado_at' => $recepcion->confirmado_at?->toAtomString(),
                'anulado_por_user_id' => $recepcion->anulado_por_user_id,
                'anulado_at' => $recepcion->anulado_at?->toAtomString(),
                'motivo_anulacion' => $recepcion->motivo_anulacion,
                'created_at' => $recepcion->created_at?->toAtomString(),
                'updated_at' => $recepcion->updated_at?->toAtomString(),
            ],
            'detalles' => $recepcion->detalles->map(
                fn (DetalleRecepcionMaterial $detalle): array => [
                    'id' => $detalle->id,
                    'item_material_id' => $detalle->item_material_id,
                    'categoria_operacional' => $detalle->categoria_operacional->value,
                    'unidad_medida' => $detalle->unidad_medida,
                    'cantidad_documental' => $detalle->cantidad_documental,
                    'cantidad_contada' => $detalle->cantidad_contada,
                    'cantidad_aceptada' => $detalle->cantidad_recibida,
                    'cantidad_rechazada' => $detalle->cantidad_rechazada,
                    'observacion' => $detalle->observacion,
                    'deleted_at' => $detalle->deleted_at?->toAtomString(),
                    'bultos' => $detalle->bultos->map(
                        fn (BultoRecepcionMaterial $bulto): array => [
                            'id' => $bulto->id,
                            'cantidad' => $bulto->cantidad,
                            'lote_proveedor' => $bulto->lote_proveedor,
                            'fecha_fabricacion' => $bulto->fecha_fabricacion?->toDateString(),
                            'fecha_vencimiento' => $bulto->fecha_vencimiento?->toDateString(),
                            'bloqueado' => $bulto->bloqueado,
                            'motivo_bloqueo' => $bulto->motivo_bloqueo,
                            'deleted_at' => $bulto->deleted_at?->toAtomString(),
                        ],
                    )->values()->all(),
                ],
            )->values()->all(),
            'eventos' => $recepcion->eventos->map(
                fn (EventoRecepcionMaterial $evento): array => [
                    'id' => $evento->id,
                    'operacion_id' => $evento->operacion_id,
                    'tipo' => $evento->tipo->value,
                    'datos' => $evento->datos,
                    'observacion' => $evento->observacion,
                    'user_id' => $evento->user_id,
                    'ocurrido_at' => $evento->ocurrido_at?->toAtomString(),
                ],
            )->values()->all(),
            'impresiones' => DB::table('trabajos_impresion_materiales')
                ->where('recepcion_material_id', $recepcion->id)
                ->orderBy('solicitado_at')
                ->get([
                    'id',
                    'operacion_id',
                    'formato',
                    'simbologia',
                    'estado',
                    'copias',
                    'solicitado_por_user_id',
                    'solicitado_at',
                    'enviado_at',
                    'ultimo_error',
                ])
                ->map(fn (object $trabajo): array => (array) $trabajo)
                ->values()
                ->all(),
            'folios' => $folios->map(fn (object $folio): array => (array) $folio)
                ->values()
                ->all(),
        ];
    }

    private function uuidDeterminista(string $espacio, string $valor): string
    {
        $hexadecimal = substr(hash('sha256', $espacio.':'.$valor), 0, 32);
        $hexadecimal[12] = '5';
        $hexadecimal[16] = dechex((hexdec($hexadecimal[16]) & 0x3) | 0x8);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hexadecimal, 0, 8),
            substr($hexadecimal, 8, 4),
            substr($hexadecimal, 12, 4),
            substr($hexadecimal, 16, 4),
            substr($hexadecimal, 20, 12),
        );
    }

    /**
     * @param  array<string, mixed>  $linea
     */
    private function crearDetalle(
        RecepcionMaterial $recepcion,
        array $linea,
        Cliente $cliente,
        ProveedorMaterial $proveedor,
        ClienteProveedorMaterial $vinculoProveedor,
        string $temporadaId,
    ): void {
        $item = $this->validarItem(
            $linea['item_material_id'],
            $cliente,
            $proveedor,
            $vinculoProveedor,
            $temporadaId,
        );
        $cantidadDocumental = $this->cantidad($linea['cantidad_documental']);
        $cantidadContada = $this->cantidad($linea['cantidad_contada']);
        $cantidadAceptada = $this->cantidad($linea['cantidad_aceptada'], permitirCero: true);
        $cantidadRechazada = $this->cantidad($linea['cantidad_rechazada'] ?? 0, permitirCero: true);

        if (abs($cantidadContada - $cantidadAceptada - $cantidadRechazada) > 0.0001) {
            throw new DomainException(sprintf(
                'La cantidad contada (%.3f) debe coincidir con la suma de cantidad aceptada (%.3f) y rechazada (%.3f).',
                $cantidadContada,
                $cantidadAceptada,
                $cantidadRechazada,
            ));
        }

        $detalle = DetalleRecepcionMaterial::create([
            'recepcion_material_id' => $recepcion->id,
            'item_material_id' => $item->id,
            'categoria_operacional' => $item->categoria_operacional,
            'unidad_medida' => $item->unidad_medida,
            'cantidad_documental' => $cantidadDocumental,
            'cantidad_contada' => $cantidadContada,
            'cantidad_recibida' => $cantidadAceptada,
            'cantidad_rechazada' => $cantidadRechazada,
            'observacion' => $this->textoOpcional($linea['observacion'] ?? null),
        ]);
        $sumaBultos = 0.0;

        foreach ($linea['bultos'] as $datosBulto) {
            $cantidad = $this->cantidad($datosBulto['cantidad']);
            $bloqueado = (bool) ($datosBulto['bloqueado'] ?? false);
            $motivoBloqueo = $this->textoOpcional($datosBulto['motivo_bloqueo'] ?? null);

            if ($bloqueado && ! $motivoBloqueo) {
                throw new DomainException(
                    'Un bulto bloqueado debe registrar el motivo del bloqueo.',
                );
            }

            BultoRecepcionMaterial::create([
                'detalle_recepcion_material_id' => $detalle->id,
                'cantidad' => $cantidad,
                'lote_proveedor' => $this->textoOpcional($datosBulto['lote_proveedor'] ?? null),
                'fecha_fabricacion' => $datosBulto['fecha_fabricacion'] ?? null,
                'fecha_vencimiento' => $datosBulto['fecha_vencimiento'] ?? null,
                'bloqueado' => $bloqueado,
                'motivo_bloqueo' => $motivoBloqueo,
            ]);
            $sumaBultos = round($sumaBultos + $cantidad, 3);
        }

        if (abs($sumaBultos - $cantidadAceptada) > 0.0001) {
            throw new DomainException(sprintf(
                'La suma de bultos del ítem %s debe coincidir con la cantidad aceptada.',
                $item->codigo,
            ));
        }
    }

    /**
     * @return array{Cliente, ProveedorMaterial, ClienteProveedorMaterial}
     */
    private function validarCabecera(
        string $clienteId,
        string $proveedorId,
        bool $exigirCodigoFolio = false,
    ): array {
        $cliente = Cliente::query()
            ->whereKey($clienteId)
            ->where('activo', true)
            ->lockForUpdate()
            ->first();
        $proveedor = ProveedorMaterial::query()
            ->whereKey($proveedorId)
            ->where('activo', true)
            ->lockForUpdate()
            ->first();

        if (! $cliente) {
            throw new DomainException('El cliente no existe o se encuentra inactivo.');
        }

        if (! $proveedor) {
            throw new DomainException('El proveedor no existe o se encuentra inactivo.');
        }

        $vinculo = ClienteProveedorMaterial::query()
            ->where('cliente_id', $cliente->id)
            ->where('proveedor_material_id', $proveedor->id)
            ->where('activo', true)
            ->lockForUpdate()
            ->first();

        if (! $vinculo) {
            throw new DomainException(
                'El proveedor no se encuentra autorizado para el cliente seleccionado.',
            );
        }

        if ($exigirCodigoFolio) {
            $codigo = mb_strtoupper(trim((string) $cliente->codigo_folio_materiales));

            if (! preg_match('/^[A-Z]{2}$/', $codigo)) {
                throw new DomainException(
                    'El cliente debe tener un código de folio de materiales de exactamente dos letras.',
                );
            }
        }

        return [$cliente, $proveedor, $vinculo];
    }

    private function validarItem(
        string $itemId,
        Cliente $cliente,
        ProveedorMaterial $proveedor,
        ClienteProveedorMaterial $vinculoProveedor,
        string $temporadaId,
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
            throw new DomainException(
                'Uno de los ítems no pertenece al cliente y temporada de la recepción.',
            );
        }

        if (! $item->categoria_operacional) {
            throw new DomainException(sprintf(
                'El ítem %s no posee categoría operacional configurada.',
                $item->codigo,
            ));
        }

        $categoria = trim((string) $item->categoria);
        $categoriasAutorizadas = collect($vinculoProveedor->categorias ?? [])
            ->map(fn ($valor): string => mb_strtolower(trim((string) $valor)))
            ->filter();

        if ($categoria === '' || ! $categoriasAutorizadas->contains(mb_strtolower($categoria))) {
            throw new DomainException(sprintf(
                'El proveedor %s no está habilitado para la categoría %s del ítem %s.',
                $proveedor->codigo,
                $categoria !== '' ? $categoria : 'sin categoría',
                $item->codigo,
            ));
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>|null  $datos
     */
    private function registrarEvento(
        RecepcionMaterial $recepcion,
        TipoEventoRecepcionMaterial $tipo,
        User $usuario,
        ?string $operacionId,
        ?array $datos = null,
        ?string $observacion = null,
    ): void {
        EventoRecepcionMaterial::create([
            'recepcion_material_id' => $recepcion->id,
            'operacion_id' => $operacionId,
            'tipo' => $tipo,
            'datos' => $datos,
            'observacion' => $observacion,
            'user_id' => $usuario->id,
            'ocurrido_at' => now(),
        ]);
    }

    private function cantidad(mixed $valor, bool $permitirCero = false): float
    {
        $cantidad = round((float) $valor, 3);

        if ($cantidad < 0 || (! $permitirCero && $cantidad <= 0)) {
            throw new DomainException('Las cantidades deben ser positivas.');
        }

        return $cantidad;
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

    /**
     * Acepta reintentos creados antes de separar cantidad contada y aceptada.
     *
     * @param  array<string, mixed>  $datos
     */
    private function coincidePayloadCreacion(
        string $hashGuardado,
        array $datos,
        string $hashActual,
    ): bool {
        if (hash_equals($hashGuardado, $hashActual)) {
            return true;
        }

        $payloadAnterior = $datos;

        foreach ($payloadAnterior['detalles'] ?? [] as $indice => $detalle) {
            if (! is_array($detalle)) {
                continue;
            }

            unset(
                $payloadAnterior['detalles'][$indice]['cantidad_contada'],
                $payloadAnterior['detalles'][$indice]['cantidad_aceptada'],
            );
        }

        return hash_equals($hashGuardado, $this->payloadHash($payloadAnterior));
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

<?php

namespace App\Services\Materiales;

use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoReservaMaterial;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimientoInventarioMaterial;
use App\Exceptions\ConflictoOperacion;
use App\Models\Camara;
use App\Models\Cliente;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\ItemMaterial;
use App\Models\MovimientoInventarioMaterial;
use App\Models\Posicion;
use App\Models\SaldoMaterialAlmacen;
use App\Models\TransferenciaClienteMaterial;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioTransferenciaClienteMaterial
{
    public function __construct(
        private readonly ServicioAlmacenMaterial $almacenes,
        private readonly ServicioCorrelativoFolioMaterial $correlativo,
        private readonly ServicioTemporadaActiva $temporadas,
        private readonly GuardiaRenumeracionMaterial $conteos,
    ) {}

    public function transferir(FolioMaterial $origen, array $datos, User $usuario, ?string $dispositivoId = null): TransferenciaClienteMaterial
    {
        $cantidad = round((float) $datos['cantidad'], 3);
        $motivo = trim((string) $datos['motivo']);
        if (! is_finite($cantidad) || $cantidad <= 0 || mb_strlen($motivo) < 10) {
            throw new DomainException('Indica una cantidad positiva y un motivo de al menos 10 caracteres.');
        }
        $payload = ['folio_origen_id' => $origen->folio_id, 'cliente_destino_id' => $datos['cliente_destino_id'],
            'item_destino_id' => $datos['item_destino_id'], 'cantidad' => number_format($cantidad, 3, '.', ''),
            'motivo' => $motivo, 'documento_respaldo' => trim((string) ($datos['documento_respaldo'] ?? '')) ?: null];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $previa = TransferenciaClienteMaterial::where('operacion_id', $datos['operacion_id'])->first();
        if ($previa) {
            return DB::transaction(fn () => $this->reintento(TransferenciaClienteMaterial::lockForUpdate()->findOrFail($previa->id), $hash, $usuario), attempts: 3);
        }

        try {
            return DB::transaction(function () use ($origen, $datos, $payload, $hash, $cantidad, $motivo, $usuario, $dispositivoId) {
                // Serializa nuevas escrituras sin sostener gap locks de UUID ausentes mientras espera la temporada.
                $temporada = $this->temporadas->obtener(bloquear: true);
                $existente = TransferenciaClienteMaterial::where('operacion_id', $datos['operacion_id'])->lockForUpdate()->first();
                if ($existente) {
                    return $this->reintento($existente, $hash, $usuario);
                }
                $origen = FolioMaterial::with(['item.cliente.temporada', 'folio'])->lockForUpdate()->findOrFail($origen->folio_id);
                $folio = Folio::lockForUpdate()->findOrFail($origen->folio_id);
                $bodega = $this->almacenes->bodegaCentral($usuario);
                $saldo = $this->almacenes->saldo($origen, $bodega);
                $ubicacion = UbicacionActual::where('folio_id', $origen->folio_id)->lockForUpdate()->first();
                if ($saldo->posicion_id) {
                    Posicion::whereKey($saldo->posicion_id)->lockForUpdate()->firstOrFail();
                }
                $origen->asegurarVigente();
                if (! $folio->activo || ! in_array($folio->estado_operacional, [EstadoOperacionalFolio::Disponible, EstadoOperacionalFolio::PendienteUbicacion], true)
                    || $origen->motivo_bloqueo !== null || $origen->bloqueado_por_vencimiento) {
                    throw new DomainException('El folio de origen está inactivo, anulado o bloqueado y no puede transferirse.');
                }
                if ($origen->item->cliente->temporada->temporada_id !== $temporada->id) {
                    throw new DomainException('El ítem de origen no pertenece a la temporada activa.');
                }
                $clienteOrigenId = $origen->item->cliente->cliente_id;
                $cliente = Cliente::lockForUpdate()->findOrFail($datos['cliente_destino_id']);
                if ($cliente->id === $clienteOrigenId) {
                    throw new DomainException('El cliente destino debe ser distinto del cliente de origen.');
                }
                if (! $cliente->activo || ! $cliente->catalogosMateriales()->where('activo', true)->whereHas('temporada', fn ($q) => $q->where('temporada_id', $temporada->id)->where('activa', true))->exists()) {
                    throw new DomainException('El cliente destino debe estar activo en la temporada activa.');
                }
                if (! preg_match('/^[A-Z]{2}$/', (string) $cliente->codigo_folio_materiales)) {
                    throw new DomainException('El cliente destino debe tener un código de folio de dos letras.');
                }
                $item = ItemMaterial::with('cliente.temporada')->lockForUpdate()->findOrFail($datos['item_destino_id']);
                if (! $item->activo || ! $item->cliente->activo || $item->cliente->cliente_id !== $cliente->id || $item->cliente->temporada->temporada_id !== $temporada->id) {
                    throw new DomainException('El ítem destino debe estar activo y pertenecer al cliente destino en la temporada activa.');
                }
                if (mb_strtolower(trim($item->unidad_medida)) !== mb_strtolower(trim($origen->unidad_medida))) {
                    throw new DomainException('El ítem destino debe tener la misma unidad de medida que el folio.');
                }
                if ($cantidad > $saldo->cantidadDisponible() + 0.0001) {
                    throw new DomainException('Solo se transfiere stock en Bodega Central. La cantidad supera su disponible o toca stock reservado; los saldos en centros de costo no son transferibles.');
                }
                $this->conteos->asegurar($origen, $saldo->posicion_id, $temporada->id);
                $reservasActivas = $origen->reservas()->where('estado', EstadoReservaMaterial::Activa)->exists()
                    || $origen->reservasTransformacion()->where('estado', EstadoReservaMaterial::Activa)->exists();
                $totalAnterior = (float) $origen->cantidad_actual;
                $total = abs($cantidad - $totalAnterior) < 0.0001 && abs((float) $saldo->cantidad_actual - $totalAnterior) < 0.0001
                    && (float) $origen->cantidad_reservada <= 0 && ! $reservasActivas;
                $id = (string) Str::uuid();
                $fotoAnterior = $this->foto($origen);
                $posicionAnterior = $saldo->posicion_id;
                $camaraAnterior = $saldo->camara_id;
                // El observer legado debita exclusivamente Bodega Central y valida la proyección global.
                $origen->update(['cantidad_actual' => round($totalAnterior - $cantidad, 3)]);
                if ($total) {
                    $folio->update(['estado_operacional' => EstadoOperacionalFolio::RetiradoDefinitivo, 'activo' => false]);
                }
                $nuevo = Folio::create([
                    'temporada_id' => $temporada->id, 'numero_folio' => $this->correlativo->siguiente($cliente), 'tipo_bulto' => TipoBulto::Material,
                    'estado_operacional' => EstadoOperacionalFolio::PendienteUbicacion, 'fecha_ingreso' => $folio->fecha_ingreso, 'activo' => true,
                    'origen_sistema' => 'transferencia_cliente_materiales', 'identificador_externo' => $id,
                    'datos_externos' => ['transferencia_cliente_material_id' => $id, 'folio_origen' => $folio->numero_folio,
                        'folio_origen_id' => $folio->id, 'cliente_origen_id' => $clienteOrigenId, 'item_origen_id' => $origen->item_material_id],
                ]);
                $destino = FolioMaterial::create([
                    'folio_id' => $nuevo->id, 'item_material_id' => $item->id, 'categoria_operacional' => $item->categoria_operacional,
                    'cantidad_inicial' => $cantidad, 'cantidad_actual' => $cantidad, 'cantidad_reservada' => 0, 'unidad_medida' => $origen->unidad_medida,
                    ...$origen->only(['lote', 'proveedor', 'proveedor_material_id', 'fecha_fabricacion', 'fecha_vencimiento']),
                    'observacion' => 'Transferencia entre clientes: '.$motivo,
                ]);
                if ($total && $camaraAnterior) {
                    UbicacionActual::create(['folio_id' => $nuevo->id, 'camara_id' => $camaraAnterior, 'posicion_id' => $posicionAnterior, 'ubicado_at' => now()]);
                }
                if (($total || ((float) $saldo->cantidad_actual - $cantidad <= 0.0001)) && $camaraAnterior) {
                    Camara::whereKey($camaraAnterior)->increment('version_plano');
                }
                $transferencia = TransferenciaClienteMaterial::forceCreate([
                    'id' => $id, 'operacion_id' => $datos['operacion_id'], 'payload_hash' => $hash, 'temporada_id' => $temporada->id,
                    'folio_origen_id' => $origen->folio_id, 'folio_destino_id' => $nuevo->id,
                    'cliente_origen_id' => $clienteOrigenId, 'cliente_destino_id' => $cliente->id,
                    'item_origen_id' => $origen->item_material_id, 'item_destino_id' => $item->id, 'cantidad' => $cantidad,
                    'unidad_medida' => $origen->unidad_medida, 'modalidad' => $total ? 'total' : 'parcial', 'motivo' => $motivo,
                    'documento_respaldo' => $payload['documento_respaldo'], 'user_id' => $usuario->id, 'dispositivo_id' => $dispositivoId, 'ocurrido_at' => now(),
                    'snapshot' => ['origen_antes' => $fotoAnterior, 'origen_despues' => $this->foto($origen->fresh()), 'destino' => $this->foto($destino->fresh()),
                        'ubicacion_origen' => $ubicacion?->only(['camara_id', 'posicion_id', 'ubicado_at'])],
                ]);
                foreach ([[$origen, TipoMovimientoInventarioMaterial::TransferenciaClienteSalida, -$cantidad, $totalAnterior, $totalAnterior - $cantidad, $nuevo],
                    [$destino, TipoMovimientoInventarioMaterial::TransferenciaClienteEntrada, $cantidad, 0, $cantidad, $folio]] as [$material, $tipo, $delta, $antes, $despues, $otro]) {
                    MovimientoInventarioMaterial::create([
                        'folio_id' => $material->folio_id, 'item_material_id' => $material->item_material_id, 'tipo' => $tipo,
                        'cantidad' => $delta, 'cantidad_anterior' => $antes, 'cantidad_resultante' => $despues,
                        'transferencia_cliente_material_id' => $id, 'user_id' => $usuario->id, 'dispositivo_id' => $dispositivoId,
                        'motivo' => $motivo, 'ocurrido_at' => $transferencia->ocurrido_at,
                        'metadatos' => ['otro_folio_id' => $otro->id, 'otro_folio' => $otro->numero_folio,
                            'cliente_origen_id' => $clienteOrigenId, 'cliente_destino_id' => $cliente->id, 'modalidad' => $transferencia->modalidad,
                            'almacen_material_id' => $bodega->id],
                    ]);
                }

                return $this->cargar($transferencia);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existente = TransferenciaClienteMaterial::where('operacion_id', $datos['operacion_id'])->first();
            if ($existente) {
                return $this->reintento($existente, $hash, $usuario);
            }
            throw new ConflictoOperacion('La transferencia entró en conflicto con otra operación.', previous: $exception);
        }
    }

    public function cargar(TransferenciaClienteMaterial $transferencia): TransferenciaClienteMaterial
    {
        return $transferencia->load(['folioOrigen.folio', 'folioDestino.folio', 'clienteOrigen', 'clienteDestino', 'itemOrigen', 'itemDestino', 'usuario:id,name']);
    }

    private function reintento(TransferenciaClienteMaterial $transferencia, string $hash, User $usuario): TransferenciaClienteMaterial
    {
        if (! hash_equals($transferencia->payload_hash, $hash) || $transferencia->user_id !== $usuario->id) {
            throw new ConflictoOperacion('El operacion_id de transferencia ya fue utilizado con otros datos.');
        }

        return $this->cargar($transferencia);
    }

    private function foto(FolioMaterial $material): array
    {
        $material->load('folio.ubicacionActual');

        return ['folio_id' => $material->folio_id, 'numero_folio' => $material->folio->numero_folio, 'item_id' => $material->item_material_id,
            'cantidad_actual' => $material->cantidad_actual, 'cantidad_reservada' => $material->cantidad_reservada,
            'estado_operacional' => $material->folio->estado_operacional->value, 'activo' => $material->folio->activo,
            'saldos' => SaldoMaterialAlmacen::where('folio_id', $material->folio_id)->get()->map(fn ($s) => $s->only(['almacen_material_id', 'cantidad_actual', 'cantidad_reservada', 'camara_id', 'posicion_id', 'version']))->all()];
    }

    public static function mensajeEtiquetaAntigua(string $folioId): ?string
    {
        if ($folioId === '') {
            return null;
        }
        $transferencia = TransferenciaClienteMaterial::with(['folioOrigen.folio', 'folioDestino.folio', 'clienteDestino'])->where('folio_origen_id', $folioId)->where('modalidad', 'total')->first();
        if (! $transferencia) {
            return null;
        }

        return sprintf('Folio %s transferido a %s (cliente %s) el %s. Reemplaza la etiqueta.',
            $transferencia->folioOrigen->folio->numero_folio, $transferencia->folioDestino->folio->numero_folio,
            $transferencia->clienteDestino->codigo, $transferencia->ocurrido_at->timezone('America/Santiago')->format('d-m-Y'));
    }
}

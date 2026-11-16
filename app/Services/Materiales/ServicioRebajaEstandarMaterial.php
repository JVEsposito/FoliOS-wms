<?php

namespace App\Services\Materiales;

use App\Enums\TipoAlmacenMaterial;
use App\Enums\TipoMovimientoAlmacenMaterial;
use App\Exceptions\ConflictoOperacion;
use App\Models\AlmacenMaterial;
use App\Models\ConsumoRebajaEstandar;
use App\Models\ItemMaterial;
use App\Models\ProduccionRebajaEstandar;
use App\Models\RebajaEstandarMaterial;
use App\Models\SaldoMaterialAlmacen;
use App\Models\SaldoRebajaEstandar;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Secuencias\ServicioSecuenciaDocumento;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Rebaja por estándar: descuenta del almacén de Packing (un centro de costo al que bodega
 * entregó materiales) lo que lleva la producción validada en PT.
 *
 * - Entero descuenta unidades completas y arrastra la fracción; Decimal descuenta hasta
 *   milésimas (la precisión del inventario) y arrastra el resto. La fracción vive por almacén
 *   e ítem en saldos_rebaja_estandar.
 * - Si falta saldo se descuenta lo que hay y el resto queda pendiente; cada rebaja siguiente
 *   del mismo almacén reintenta los pendientes. FoliOS no deja saldos negativos por folio.
 * - Cada descuento es un consumo normal del almacén (FIFO), con el código REB como documento.
 */
class ServicioRebajaEstandarMaterial
{
    public const CLAVE_SECUENCIA = 'rebajas_estandar_materiales';

    private const ESPACIO_UUID = '5b4f3a0e-8f0c-4f7e-9a8b-2a1d6c0e7f31';

    private const EPSILON = 0.0000005;

    public function __construct(
        private readonly ServicioTemporadaActiva $temporadas,
        private readonly ProduccionValidadaEstandar $produccion,
        private readonly DiferenciasRebajaEstandar $diferencias,
        private readonly ServicioMovimientoAlmacenMaterial $movimientos,
        private readonly ServicioSecuenciaDocumento $secuencias,
    ) {}

    /**
     * @param  array<string, mixed>  $filtros  desde, hasta, almacen_id, cliente_id?, marca?, proceso?, grupos? (claves)
     * @return array<string, mixed>
     */
    public function previsualizar(array $filtros): array
    {
        $temporada = $this->temporadas->obtener();
        $almacen = $this->almacen($filtros['almacen_id']);
        $grupos = $this->todos($temporada, $filtros);
        $seleccion = collect($grupos)->whereNull('problema')
            ->when(isset($filtros['grupos']), fn (Collection $listos) => $listos->whereIn('clave', $filtros['grupos']))
            ->values();
        $materiales = $this->materiales($seleccion, $almacen);

        return [
            'almacen' => ['id' => $almacen->id, 'codigo' => $almacen->codigo, 'nombre' => $almacen->nombre],
            'grupos' => collect($grupos)->map(fn (array $grupo): array => [...collect($grupo)->except('lineas')->all(), 'seleccionado' => $seleccion->contains('clave', $grupo['clave'])])->all(),
            'materiales' => array_values($materiales),
            'totales' => [
                'grupos' => count($grupos),
                'cajas' => collect($grupos)->sum('cajas'),
                'cajas_seleccionadas' => $seleccion->sum('cajas'),
                'ajustes' => collect($grupos)->where('tipo', 'ajuste')->count(),
                'cajas_sin_estandar' => collect($grupos)->whereNotNull('problema')->sum('cajas'),
                'faltantes' => collect($materiales)->where('faltante', '>', 0)->count(),
            ],
        ];
    }

    /** @param array<string, mixed> $datos */
    public function confirmar(array $datos, User $usuario): RebajaEstandarMaterial
    {
        $hash = hash('sha256', json_encode($this->normalizar($datos), JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($datos, $usuario, $hash): RebajaEstandarMaterial {
                $existente = RebajaEstandarMaterial::query()->where('operacion_id', $datos['operacion_id'])->lockForUpdate()->first();
                if ($existente) {
                    return $this->repetida($existente, $usuario, $hash);
                }
                $temporada = $this->temporadas->obtener(bloquear: true);
                $almacen = $this->almacen($datos['almacen_id'], bloquear: true);
                $grupos = collect($this->todos($temporada, $datos))->keyBy('clave');
                $seleccion = collect($datos['grupos'] ?? [])->map(function (array $elegido) use ($grupos): array {
                    $grupo = $grupos->get($elegido['clave']);
                    if (! $grupo || $grupo['cajas'] !== (int) $elegido['cajas']) {
                        throw new ConflictoOperacion('La producción validada cambió desde la vista previa. Actualízala antes de confirmar.');
                    }
                    if ($grupo['problema'] !== null) {
                        throw new DomainException('Hay grupos sin estándar aplicable o con insumos sin homologar. Quítalos de la rebaja.');
                    }

                    return $grupo;
                });
                $materiales = $this->materiales($seleccion, $almacen, bloquear: true);
                if ($seleccion->isEmpty() && collect($materiales)->every(fn (array $material): bool => $material['a_descontar_ahora'] <= 0)) {
                    throw new DomainException('No hay producción ni pendientes que se puedan descontar ahora.');
                }

                $codigo = sprintf('REB-%06d', $this->secuencias->reservarSiguiente(self::CLAVE_SECUENCIA));
                $rebaja = RebajaEstandarMaterial::create([
                    'operacion_id' => $datos['operacion_id'],
                    'payload_hash' => $hash,
                    'codigo' => $codigo,
                    'temporada_id' => $temporada->id,
                    'almacen_material_id' => $almacen->id,
                    'desde' => $datos['desde'],
                    'hasta' => $datos['hasta'],
                    'filtros' => collect($datos)->only(['cliente_id', 'marca', 'proceso'])->filter()->all(),
                    'estado' => 'confirmada',
                    'cajas' => $seleccion->sum('cajas'),
                    'pallets' => $seleccion->flatMap(fn (array $grupo): array => array_column($grupo['lineas'], 'validacion_pallet_id'))->unique()->count(),
                    'resumen' => [],
                    'observacion' => filled($datos['observacion'] ?? null) ? mb_substr(trim($datos['observacion']), 0, 500) : null,
                    'user_id' => $usuario->id,
                    'confirmada_at' => now(),
                ]);
                $this->registrarProduccion($rebaja, $seleccion);
                $resumen = [];
                foreach ($materiales as $material) {
                    $resumen[] = $this->descontar($rebaja, $almacen, $material, $usuario);
                }
                $rebaja->update(['resumen' => [
                    'grupos' => $seleccion->count(),
                    'items' => count($resumen),
                    'con_faltante' => collect($resumen)->where('pendiente', '>', 0)->count(),
                ]]);

                return $rebaja->refresh();
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existente = RebajaEstandarMaterial::query()->where('operacion_id', $datos['operacion_id'])->first();
            if ($existente) {
                return $this->repetida($existente, $usuario, $hash);
            }

            throw new ConflictoOperacion('La rebaja entró en conflicto con otra operación. Vuelve a intentarlo.', previous: $exception);
        }
    }

    /**
     * Total por ítem de los grupos elegidos, más los pendientes del almacén, con lo que se puede
     * descontar ahora según el saldo disponible.
     *
     * @param  Collection<int, array<string, mixed>>  $grupos
     * @return array<string, array<string, mixed>>
     */
    public function materiales(Collection $grupos, AlmacenMaterial $almacen, bool $bloquear = false): array
    {
        $porItem = [];
        foreach ($grupos as $grupo) {
            foreach ($grupo['consumo'] as $consumo) {
                $porItem[$consumo['item_material_id']] ??= [...collect($consumo)->except(['teorico', 'cantidad_por_caja'])->all(), 'teorico' => 0.0];
                $porItem[$consumo['item_material_id']]['teorico'] = round($porItem[$consumo['item_material_id']]['teorico'] + $consumo['teorico'], 6);
            }
        }
        $saldos = SaldoRebajaEstandar::query()
            ->where('almacen_material_id', $almacen->id)
            ->where(fn (Builder $consulta) => $consulta->whereIn('item_material_id', array_keys($porItem))->orWhere('pendiente', '>', 0))
            ->when($bloquear, fn (Builder $consulta) => $consulta->lockForUpdate())
            ->get()->keyBy('item_material_id');
        $soloPendientes = $saldos->keys()->diff(array_keys($porItem));
        foreach (ItemMaterial::query()->whereIn('id', $soloPendientes)->get() as $item) {
            $porItem[$item->id] = ['item_material_id' => $item->id, 'codigo' => $item->codigo, 'nombre' => $item->nombre,
                'unidad_medida' => $item->unidad_medida, 'redondeo' => 'decimal', 'teorico' => 0.0];
        }
        $disponibles = $this->disponibles($almacen, array_keys($porItem));
        $duenos = ItemMaterial::query()->with('cliente.cliente:id,nombre')->whereIn('id', array_keys($porItem))->get()->keyBy('id');

        foreach ($porItem as $itemId => $material) {
            $saldo = $saldos->get($itemId);
            $fraccion = (float) ($saldo?->fraccion ?? 0);
            $pendiente = (float) ($saldo?->pendiente ?? 0);
            $total = $material['teorico'] + $fraccion;
            $nuevo = $material['redondeo'] === 'entero'
                ? floor($total + self::EPSILON)
                : floor($total * 1000 + self::EPSILON * 1000) / 1000;
            $requerido = round($nuevo + $pendiente, 3);
            $disponible = floor(($disponibles[$itemId] ?? 0) * 1000 + 0.0005) / 1000;
            // Un requerido negativo (ajustes a favor) no descuenta nada y queda como crédito.
            $ahora = round(max(0, min($requerido, $disponible)), 3);
            $porItem[$itemId] = [
                ...$material,
                'dueno' => $duenos->get($itemId)?->cliente?->cliente?->nombre,
                'fraccion_anterior' => round($fraccion, 6),
                'pendiente_anterior' => round($pendiente, 6),
                'a_descontar' => $requerido,
                'disponible' => round($disponibles[$itemId] ?? 0, 3),
                'a_descontar_ahora' => $ahora,
                'faltante' => round($requerido - $ahora, 3),
                // floor deja el resto en [0, 1) también con ajustes negativos.
                'fraccion_resultante' => round(max(0, $total - $nuevo), 6),
            ];
        }
        uasort($porItem, fn (array $a, array $b): int => strnatcasecmp((string) $a['codigo'], (string) $b['codigo']));

        return $porItem;
    }

    /**
     * Producción validada sin rebajar y ajustes por correcciones posteriores, con una sola clave.
     *
     * @param  array<string, mixed>  $filtros
     * @return list<array<string, mixed>>
     */
    private function todos(Temporada $temporada, array $filtros): array
    {
        return [
            ...$this->produccion->grupos($temporada, $filtros['desde'], $filtros['hasta'], $filtros),
            ...$this->diferencias->grupos($temporada, $filtros['desde'], $filtros['hasta'], $filtros),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $grupos */
    private function registrarProduccion(RebajaEstandarMaterial $rebaja, Collection $grupos): void
    {
        $ahora = now();
        $filas = $grupos->flatMap(fn (array $grupo): array => array_map(fn (array $linea): array => [
            'id' => (string) Str::uuid(),
            'rebaja_estandar_material_id' => $rebaja->id,
            'validacion_pallet_id' => $linea['validacion_pallet_id'],
            'numero_folio' => $linea['numero_folio'],
            'linea' => $linea['linea'],
            'fecha_proceso' => $grupo['fecha'],
            'cliente_id' => $grupo['cliente_id'],
            'marca' => $grupo['marca'],
            'proceso' => $grupo['proceso'],
            'envase_validacion_id' => $grupo['envase_validacion_id'],
            'variedad' => $grupo['variedad'],
            'calibre' => $grupo['calibre'],
            'categoria' => $grupo['categoria'],
            'cajas' => $linea['cajas'],
            'estandar_material_id' => $grupo['estandar']['id'],
            'version_estandar_material_id' => $grupo['estandar']['version_id'],
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ], $grupo['lineas']));
        foreach ($filas->chunk(300) as $bloque) {
            ProduccionRebajaEstandar::query()->insert($bloque->values()->all());
        }
    }

    /** @param array<string, mixed> $material */
    private function descontar(RebajaEstandarMaterial $rebaja, AlmacenMaterial $almacen, array $material, User $usuario): array
    {
        $restante = $material['a_descontar_ahora'];
        $folios = 0;
        if ($restante > 0) {
            foreach ($this->saldosFifo($almacen, $material['item_material_id']) as $saldo) {
                $disponible = floor(((float) $saldo->cantidad_actual - (float) $saldo->cantidad_reservada) * 1000 + 0.0005) / 1000;
                $cantidad = round(min($restante, $disponible), 3);
                if ($cantidad <= 0) {
                    continue;
                }
                $this->movimientos->registrar([
                    'operacion_id' => Uuid::uuid5(self::ESPACIO_UUID, $rebaja->operacion_id.'|'.$material['item_material_id'].'|'.$saldo->folio_id)->toString(),
                    'tipo' => TipoMovimientoAlmacenMaterial::Consumo->value,
                    'folio_id' => $saldo->folio_id,
                    'almacen_origen_id' => $almacen->id,
                    'cantidad' => $cantidad,
                    'motivo' => "Rebaja por estándar {$rebaja->codigo}.",
                    'documento_relacionado' => $rebaja->codigo,
                ], $usuario, null);
                $folios++;
                $restante = round($restante - $cantidad, 3);
                if ($restante <= 0) {
                    break;
                }
            }
        }
        $descontado = round($material['a_descontar_ahora'] - $restante, 3);
        $pendiente = round($material['a_descontar'] - $descontado, 3);
        ConsumoRebajaEstandar::create([
            'rebaja_estandar_material_id' => $rebaja->id,
            'item_material_id' => $material['item_material_id'],
            'unidad_medida' => $material['unidad_medida'],
            'redondeo' => $material['redondeo'],
            'teorico' => $material['teorico'],
            'fraccion_anterior' => $material['fraccion_anterior'],
            'pendiente_anterior' => $material['pendiente_anterior'],
            'a_descontar' => $material['a_descontar'],
            'descontado' => $descontado,
            'pendiente' => $pendiente,
            'fraccion_resultante' => $material['fraccion_resultante'],
            'folios' => $folios,
        ]);
        SaldoRebajaEstandar::query()->updateOrCreate(
            ['almacen_material_id' => $almacen->id, 'item_material_id' => $material['item_material_id']],
            ['fraccion' => $material['fraccion_resultante'], 'pendiente' => $pendiente],
        );

        return ['item_material_id' => $material['item_material_id'], 'descontado' => $descontado, 'pendiente' => $pendiente];
    }

    /**
     * Saldo disponible por ítem en el almacén: mismos folios que acepta un consumo
     * (activos, vigentes, sin bloqueo, sin reservas tomadas).
     *
     * @param  list<string>  $itemIds
     * @return array<string, float>
     */
    private function disponibles(AlmacenMaterial $almacen, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return $this->consultaSaldos($almacen)
            ->whereIn('fm.item_material_id', $itemIds)
            ->groupBy('fm.item_material_id')
            ->selectRaw('fm.item_material_id, SUM(saldos_materiales_almacenes.cantidad_actual - saldos_materiales_almacenes.cantidad_reservada) as disponible')
            ->pluck('disponible', 'item_material_id')
            ->map(fn ($valor): float => (float) $valor)
            ->all();
    }

    /** @return Collection<int, SaldoMaterialAlmacen> */
    private function saldosFifo(AlmacenMaterial $almacen, string $itemId): Collection
    {
        // Mismo orden que exige el consumo de almacén para no pedir excepción FIFO.
        return $this->consultaSaldos($almacen)
            ->where('fm.item_material_id', $itemId)
            ->select('saldos_materiales_almacenes.*')
            ->orderByRaw('fm.fecha_vencimiento IS NULL')
            ->orderBy('fm.fecha_vencimiento')
            ->orderByRaw('fm.fecha_fabricacion IS NULL')
            ->orderBy('fm.fecha_fabricacion')
            ->orderBy('f.fecha_ingreso')
            ->orderBy('f.numero_folio')
            ->orderBy('f.id')
            ->lockForUpdate()
            ->get();
    }

    private function consultaSaldos(AlmacenMaterial $almacen): Builder
    {
        return SaldoMaterialAlmacen::query()
            ->join('folios_materiales as fm', 'fm.folio_id', '=', 'saldos_materiales_almacenes.folio_id')
            ->join('folios as f', 'f.id', '=', 'fm.folio_id')
            ->where('saldos_materiales_almacenes.almacen_material_id', $almacen->id)
            ->whereColumn('saldos_materiales_almacenes.cantidad_actual', '>', 'saldos_materiales_almacenes.cantidad_reservada')
            ->whereNull('saldos_materiales_almacenes.camara_id')
            ->whereNull('saldos_materiales_almacenes.posicion_id')
            ->whereNull('fm.motivo_bloqueo')
            ->where('f.activo', true)
            ->tap(fn (Builder $consulta) => ServicioVencimientoMaterial::filtrarVigentes($consulta, 'fm.fecha_vencimiento'));
    }

    private function almacen(string $id, bool $bloquear = false): AlmacenMaterial
    {
        $almacen = AlmacenMaterial::query()->whereKey($id)->where('activo', true)
            ->when($bloquear, fn (Builder $consulta) => $consulta->lockForUpdate())->first();
        if (! $almacen || $almacen->tipo !== TipoAlmacenMaterial::Virtual) {
            throw new DomainException('Elige el almacén de Packing (un centro de costo activo al que bodega entrega materiales).');
        }

        return $almacen;
    }

    private function repetida(RebajaEstandarMaterial $rebaja, User $usuario, string $hash): RebajaEstandarMaterial
    {
        if ($rebaja->user_id !== $usuario->id || ! hash_equals($rebaja->payload_hash, $hash)) {
            throw new ConflictoOperacion('El identificador de la operación ya se usó con otros datos.');
        }

        return $rebaja;
    }

    /** @param array<string, mixed> $datos */
    private function normalizar(array $datos): array
    {
        return [
            'desde' => $datos['desde'], 'hasta' => $datos['hasta'], 'almacen_id' => mb_strtolower((string) $datos['almacen_id']),
            'cliente_id' => $datos['cliente_id'] ?? null, 'marca' => $datos['marca'] ?? null, 'proceso' => $datos['proceso'] ?? null,
            'grupos' => collect($datos['grupos'] ?? [])->map(fn (array $grupo): array => [(string) $grupo['clave'], (int) $grupo['cajas']])->sortBy(0)->values()->all(),
            'observacion' => $datos['observacion'] ?? null,
        ];
    }

    /** Fechas de proceso válidas: hasta 31 días y no futuras. */
    public static function validarPeriodo(string $desde, string $hasta): void
    {
        $inicio = CarbonImmutable::parse($desde);
        $fin = CarbonImmutable::parse($hasta);
        if ($fin->lt($inicio) || $inicio->diffInDays($fin) >= ProduccionValidadaEstandar::MAX_DIAS) {
            throw new DomainException('Elige un período de hasta '.ProduccionValidadaEstandar::MAX_DIAS.' días, con el inicio antes del término.');
        }
    }
}

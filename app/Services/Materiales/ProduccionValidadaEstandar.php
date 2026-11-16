<?php

namespace App\Services\Materiales;

use App\Enums\EstadoValidacionPallet;
use App\Enums\ResultadoValidacionPallet;
use App\Models\Cliente;
use App\Models\EnvaseValidacion;
use App\Models\OrigenValidacion;
use App\Models\ProduccionRebajaEstandar;
use App\Models\Temporada;
use App\Models\ValidacionPallet;
use App\Services\Validacion\ProyeccionTrazabilidadFolio;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Lo producido según Validación PT, listo para rebajar por estándar: cada línea de
 * composición de un pallet aprobado, con su día de proceso (fecha de embalaje), cliente,
 * marca, proceso de packing, embalaje, variedad, calibre y categoría.
 *
 * Solo cuentan validaciones aceptadas y aprobadas que crearon folio. El repaletizaje no crea
 * validaciones, así que mover cajas entre folios no las vuelve a contar; las correcciones
 * modifican la misma validación. Un pallet ya incluido en una rebaja confirmada no se repite.
 */
class ProduccionValidadaEstandar
{
    public const MAX_DIAS = 31;

    /** @var array<string, ?array<string, mixed>> */
    private array $aplicables = [];

    public function __construct(private readonly ServicioEstandarMaterial $estandares) {}

    /**
     * @param  array{cliente_id?: ?string, marca?: ?string, proceso?: ?string}  $filtros
     * @return list<array<string, mixed>>
     */
    public function grupos(Temporada $temporada, string $desde, string $hasta, array $filtros = []): array
    {
        $validaciones = ValidacionPallet::query()
            ->where('temporada_id', $temporada->id)
            ->where('estado', EstadoValidacionPallet::Aceptada->value)
            ->where('resultado', ResultadoValidacionPallet::Aprobado->value)
            ->whereNotNull('folio_id')
            ->whereBetween('snapshot->fecha_embalaje', [$desde, $hasta])
            ->orderBy('numero_folio')
            ->get(['id', 'numero_folio', 'snapshot']);
        $rebajadas = ProduccionRebajaEstandar::query()
            ->whereIn('validacion_pallet_id', $validaciones->pluck('id'))
            ->whereHas('rebaja', fn ($consulta) => $consulta->where('estado', 'confirmada'))
            ->pluck('validacion_pallet_id')
            ->flip();
        $lineas = $validaciones->reject(fn (ValidacionPallet $validacion): bool => $rebajadas->has($validacion->id))
            ->flatMap(fn (ValidacionPallet $validacion): array => collect($validacion->snapshot['composicion'] ?? [])
                ->filter(fn (mixed $linea): bool => is_array($linea) && (int) ($linea['cantidad_cajas'] ?? 0) > 0)
                ->map(fn (array $linea, int $indice): array => ['validacion' => $validacion, 'linea' => $linea, 'indice' => $indice])
                ->values()->all());
        $origenes = OrigenValidacion::query()
            ->with(['clienteCatalogo:id,cliente_id,nombre', 'marcaCatalogo:id,nombre'])
            ->whereIn('id', $lineas->pluck('linea.origen_validacion_id')->filter()->unique())
            ->get()->keyBy('id');
        $clientes = Cliente::query()->whereIn('id', $origenes->pluck('clienteCatalogo.cliente_id')->filter()->unique())->pluck('nombre', 'id');
        $envases = EnvaseValidacion::query()->whereIn('id', $lineas->pluck('linea.envase_validacion_id')->filter()->unique())
            ->get(['id', 'nombre', 'codigo_externo'])->keyBy('id');
        $marcaFiltro = ServicioEstandarMaterial::normalizarVariacion($filtros['marca'] ?? null);
        $procesoFiltro = ProyeccionTrazabilidadFolio::normalizarCodigo($filtros['proceso'] ?? null);

        $grupos = [];
        foreach ($lineas as ['validacion' => $validacion, 'linea' => $linea, 'indice' => $indice]) {
            $snapshot = $validacion->snapshot;
            $origen = $origenes->get($linea['origen_validacion_id'] ?? '');
            $clienteId = $origen?->clienteCatalogo?->cliente_id;
            $marca = ServicioEstandarMaterial::normalizarVariacion($origen?->marcaCatalogo?->nombre ?? $origen?->marca ?? ($snapshot['origen']['marca'] ?? null));
            $proceso = ProyeccionTrazabilidadFolio::normalizarCodigo($linea['proceso_packing'] ?? null);
            if (($filtros['cliente_id'] ?? null) && $clienteId !== $filtros['cliente_id']) {
                continue;
            }
            if (($marcaFiltro && $marca !== $marcaFiltro) || ($procesoFiltro && $proceso !== $procesoFiltro)) {
                continue;
            }
            $atributos = [
                'fecha' => (string) ($linea['fecha_embalaje'] ?? $snapshot['fecha_embalaje']),
                'cliente_id' => $clienteId,
                'marca' => $marca,
                'proceso' => $proceso,
                'envase_validacion_id' => $linea['envase_validacion_id'] ?? null,
                'variedad' => ServicioEstandarMaterial::normalizarVariacion($linea['variedad'] ?? ($snapshot['articulo']['variedad'] ?? null)),
                'calibre' => ServicioEstandarMaterial::normalizarVariacion($snapshot['articulo']['calibre'] ?? null),
                'categoria' => ServicioEstandarMaterial::normalizarVariacion($snapshot['categoria']['nombre'] ?? null),
            ];
            $clave = substr(hash('sha256', json_encode(array_values($atributos), JSON_THROW_ON_ERROR)), 0, 24);
            $grupos[$clave] ??= [
                'clave' => $clave,
                ...$atributos,
                'cliente' => $clienteId ? ['id' => $clienteId, 'nombre' => $clientes->get($clienteId)] : null,
                'envase' => ($envase = $envases->get($atributos['envase_validacion_id'] ?? '')) ? ['id' => $envase->id, 'nombre' => $envase->nombre, 'codigo' => $envase->codigo_externo] : null,
                'cajas' => 0,
                'pallets' => [],
                'lineas' => [],
            ];
            $grupos[$clave]['cajas'] += (int) $linea['cantidad_cajas'];
            $grupos[$clave]['pallets'][$validacion->id] = true;
            $grupos[$clave]['lineas'][] = ['validacion_pallet_id' => $validacion->id, 'numero_folio' => $validacion->numero_folio, 'linea' => $indice, 'cajas' => (int) $linea['cantidad_cajas']];
        }

        return collect($grupos)
            ->map(fn (array $grupo): array => [...$this->conEstandar($temporada, $grupo), 'pallets' => count($grupo['pallets'])])
            ->sortBy(fn (array $grupo): string => implode('|', [$grupo['fecha'], $grupo['cliente']['nombre'] ?? '~', $grupo['marca'] ?? '~', $grupo['proceso'] ?? '~', $grupo['envase']['nombre'] ?? '~']))
            ->values()->all();
    }

    /** @param array<string, mixed> $grupo */
    private function conEstandar(Temporada $temporada, array $grupo): array
    {
        if (! $grupo['cliente_id'] || ! $grupo['envase_validacion_id']) {
            return [...$grupo, 'estandar' => null, 'consumo' => [], 'problema' => $grupo['cliente_id'] ? 'sin_embalaje' : 'sin_cliente'];
        }
        $llave = $grupo['cliente_id'].'|'.$grupo['envase_validacion_id'].'|'.$grupo['fecha'];
        $aplicable = array_key_exists($llave, $this->aplicables)
            ? $this->aplicables[$llave]
            : $this->aplicables[$llave] = $this->estandares->aplicable($temporada->id, $grupo['cliente_id'], $grupo['envase_validacion_id'], $grupo['fecha']);
        if (! $aplicable) {
            return [...$grupo, 'estandar' => null, 'consumo' => [], 'problema' => 'sin_estandar'];
        }
        $lineas = ServicioEstandarMaterial::lineasParaCaja($aplicable['version']->lineas, Arr::only($grupo, ['marca', 'variedad', 'calibre', 'categoria']));
        $consumo = $lineas->map(fn ($linea): array => [
            'item_material_id' => $linea->item_material_id,
            'codigo' => $linea->codigo_insumo,
            'nombre' => $linea->descripcion_insumo,
            'unidad_medida' => $linea->item?->unidad_medida ?? $linea->unidad_medida,
            'redondeo' => $linea->redondeo->value,
            'cantidad_por_caja' => (float) $linea->cantidad_por_caja,
            'teorico' => round((float) $linea->cantidad_por_caja * $grupo['cajas'], 6),
        ])->values()->all();

        return [
            ...$grupo,
            'estandar' => [
                'id' => $aplicable['estandar']->id,
                'codigo' => $aplicable['estandar']->codigo,
                'origen' => $aplicable['estandar']->origen->value,
                'version_id' => $aplicable['version']->id,
                'version' => $aplicable['version']->numero,
                'vigente_desde' => $aplicable['version']->vigente_desde->toDateString(),
            ],
            'consumo' => $consumo,
            'problema' => $lineas->contains(fn ($linea): bool => $linea->item_material_id === null) ? 'insumos_sin_item' : null,
        ];
    }

    /** @param list<array<string, mixed>> $grupos */
    public static function soloListos(array $grupos): Collection
    {
        return collect($grupos)->whereNull('problema')->values();
    }
}

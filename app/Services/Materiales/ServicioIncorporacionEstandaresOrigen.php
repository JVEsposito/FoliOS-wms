<?php

namespace App\Services\Materiales;

use App\Enums\OrigenEstandarMaterial;
use App\Models\ClienteValidacion;
use App\Models\EnvaseValidacion;
use App\Models\EstandarMaterial;
use App\Models\EstandarOrigen;
use App\Models\HomologacionStockOrigen;
use App\Models\ItemMaterial;
use App\Models\LecturaEstandarOrigen;
use App\Models\Temporada;
use App\Models\User;
use App\Models\VersionEstandarMaterial;
use App\Services\Integraciones\ServicioCatalogoStockOrigen;
use App\Services\Temporadas\ServicioTemporadaActiva;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Convierte la copia de la vista de planta en estándares de planta de FoliOS.
 *
 * Cliente, especie y embalaje se resuelven igual que en Pendientes por validar (y comparten
 * sus vínculos). Cada insumo se reconoce por un vínculo guardado o por el código o código
 * externo de un ítem de Materiales de la temporada, prefiriendo el catálogo del cliente.
 * Un estándar cambia de versión solo si cambió en planta o se homologó un insumo.
 */
class ServicioIncorporacionEstandaresOrigen
{
    public const DIMENSION_INSUMO = 'insumo_material';

    private ?Temporada $temporada = null;

    /** @var Collection<string, Collection<int, ItemMaterial>> */
    private Collection $itemsPorCodigo;

    /** @var Collection<string, HomologacionStockOrigen> */
    private Collection $vinculosInsumo;

    public function __construct(
        private readonly ServicioTemporadaActiva $temporadas,
        private readonly ServicioCatalogoStockOrigen $catalogo,
        private readonly ServicioEstandarMaterial $estandares,
    ) {}

    public function ultimaLectura(): ?LecturaEstandarOrigen
    {
        return LecturaEstandarOrigen::query()->with('usuario:id,name')->orderByDesc('leida_at')->orderByDesc('id')->first();
    }

    public function ultimaCorrecta(): ?LecturaEstandarOrigen
    {
        // La copia vigente es la de la última lectura correcta: las anteriores ya no tienen filas.
        return LecturaEstandarOrigen::query()->where('estado', 'correcta')->whereHas('estandaresOrigen')
            ->orderByDesc('leida_at')->orderByDesc('id')->first();
    }

    /**
     * Estado de cada estándar de la última copia: qué falta para incorporarlo y si ya está al día.
     *
     * @return list<array<string, mixed>>
     */
    public function revisar(): array
    {
        $lectura = $this->ultimaCorrecta();
        if (! $lectura || ! $this->preparar(forzar: true)) {
            return [];
        }
        $existentes = EstandarMaterial::query()
            ->where('temporada_id', $this->temporada->id)
            ->where('origen', OrigenEstandarMaterial::Planta->value)
            ->get()
            ->keyBy(fn (EstandarMaterial $estandar): string => $estandar->cliente_id.'|'.$estandar->envase_validacion_id);
        $huellas = VersionEstandarMaterial::query()
            ->whereIn('estandar_material_id', $existentes->pluck('id'))
            ->orderBy('numero')
            ->get(['estandar_material_id', 'huella_origen'])
            ->keyBy('estandar_material_id');

        return $lectura->estandaresOrigen()->orderBy('cod_empresa')->orderBy('cod_articulo')->get()
            ->map(function (EstandarOrigen $origen) use ($existentes, $huellas): array {
                $resuelto = $this->resolver($origen);
                $existente = $resuelto['cliente_id'] && $resuelto['envase']
                    ? $existentes->get($resuelto['cliente_id'].'|'.$resuelto['envase']->id) : null;
                $estado = match (true) {
                    ! $resuelto['cliente_id'] => 'falta_cliente',
                    ! $resuelto['envase'] => 'falta_embalaje',
                    ! $existente => 'nuevo',
                    $huellas->get($existente->id)?->huella_origen !== $resuelto['huella'] => 'cambio',
                    default => 'al_dia',
                };

                return [
                    'id' => $origen->id,
                    'cod_empresa' => $origen->cod_empresa,
                    'cod_articulo' => $origen->cod_articulo,
                    'codigo_estandar' => $origen->codigo_estandar,
                    'descripcion' => $origen->descripcion_estandar ?? $origen->descripcion_articulo,
                    'descripcion_articulo' => $origen->descripcion_articulo,
                    'especie' => $origen->desc_especie ?? $origen->cod_especie,
                    'fecha_creacion' => $origen->fecha_creacion?->format('Y-m-d H:i'),
                    'fecha_edicion' => $origen->fecha_edicion?->format('Y-m-d H:i'),
                    'cabeceras' => $origen->cabeceras,
                    'cliente' => $resuelto['cliente'] ? ['id' => $resuelto['cliente_id'], 'nombre' => $resuelto['cliente']->nombre, 'cliente_validacion_id' => $resuelto['cliente']->id] : null,
                    'envase' => $resuelto['envase'] ? ['id' => $resuelto['envase']->id, 'nombre' => $resuelto['envase']->nombre] : null,
                    'estandar_id' => $existente?->id,
                    'estado' => $estado,
                    'huella' => $resuelto['huella'],
                    'insumos' => count($resuelto['lineas']),
                    'insumos_sin_item' => collect($resuelto['lineas'])->whereNull('item_material_id')->count(),
                    'insumos_repetidos' => collect($origen->lineas)->where('repetido', '>', 1)->pluck('cod_insumo')->values()->all(),
                    'lineas' => $resuelto['lineas'],
                ];
            })->all();
    }

    /**
     * Insumos de planta que todavía no se reconocen como un ítem de Materiales, con candidatos.
     *
     * @return list<array<string, mixed>>
     */
    public function insumosPendientes(?array $revision = null): array
    {
        // Sin cliente reconocido no se sabe en qué catálogo buscar: primero se vincula el cliente.
        return collect($revision ?? $this->revisar())
            ->reject(fn (array $estandar): bool => $estandar['estado'] === 'falta_cliente')
            ->flatMap(fn (array $estandar): array => collect($estandar['lineas'])->whereNull('item_material_id')
                ->map(fn (array $linea): array => [
                    'cod_empresa' => $estandar['cod_empresa'],
                    'cliente' => $estandar['cliente']['nombre'] ?? null,
                    'cod_insumo' => $linea['codigo_insumo'],
                    'descripcion' => $linea['descripcion_insumo'],
                    'candidatos' => $linea['candidatos'],
                    'estandar' => $estandar['codigo_estandar'] ?? $estandar['cod_articulo'],
                ])->all())
            ->groupBy(fn (array $fila): string => $fila['cod_empresa'].'|'.mb_strtoupper($fila['cod_insumo']))
            ->map(fn (Collection $filas): array => [...$filas->first(), 'estandares' => $filas->pluck('estandar')->unique()->values()->all()])
            ->values()->all();
    }

    /** Reconoce un código de insumo de planta (por empresa) como un ítem de Materiales de la temporada. */
    public function vincularInsumo(string $codEmpresa, string $codInsumo, string $itemId, User $usuario): void
    {
        $temporada = $this->preparar() ?? throw new DomainException('No hay temporada activa.');
        $item = ItemMaterial::query()->with('cliente.temporada')->whereKey($itemId)->where('activo', true)->first();
        if (! $item || ! $item->cliente?->activo || $item->cliente->temporada?->temporada_id !== $temporada->id) {
            throw new DomainException("Elige un ítem activo de Materiales de la temporada {$temporada->codigo}.");
        }
        HomologacionStockOrigen::query()->updateOrCreate(
            ['temporada_id' => $temporada->id, 'dimension' => self::DIMENSION_INSUMO, 'contexto' => self::clave($codEmpresa), 'valor' => mb_substr(self::clave($codInsumo), 0, 150)],
            ['entidad_id' => $item->id, 'accion' => 'vinculado', 'creado_por_user_id' => $usuario->id],
        );
        $this->temporada = null;
    }

    /** Reconoce el código de empresa de planta como un cliente del maestro (crea el alias, como en Pendientes por validar). */
    public function vincularCliente(string $codEmpresa, string $clienteId, User $usuario): array
    {
        $resultado = $this->catalogo->vincularValor('cliente', $codEmpresa, $clienteId, $usuario);
        $this->temporada = null;

        return $resultado;
    }

    /** Reconoce el artículo de planta de una empresa como un embalaje PT del cliente (vale también para el stock). */
    public function vincularEmbalaje(string $estandarOrigenId, string $envaseId, User $usuario): array
    {
        $origen = EstandarOrigen::query()->findOrFail($estandarOrigenId);
        $this->preparar(forzar: true) ?? throw new DomainException('No hay temporada activa.');
        $cliente = $this->catalogo->cliente($origen->cod_empresa)
            ?? throw new DomainException("Primero vincula el cliente {$origen->cod_empresa} en Pendientes por validar o con un alias en Accesos.");
        $envase = EnvaseValidacion::query()->with('especie')->whereKey($envaseId)
            ->where(fn ($consulta) => $consulta->where('cliente_validacion_id', $cliente->id))->first()
            ?? throw new DomainException('Elige un embalaje PT de ese cliente.');
        $valorEspecie = $origen->cod_especie ?? $origen->desc_especie;
        if ($valorEspecie !== null && $this->catalogo->especie($origen->cod_especie, $origen->desc_especie)?->id !== $envase->especie_validacion_id) {
            $this->catalogo->vincularValor('especie', $valorEspecie, $envase->especie_validacion_id, $usuario);
        }
        $resultado = $this->catalogo->vincularValor('envase', $origen->cod_articulo, $envase->id, $usuario, $envase->especie_validacion_id, $cliente->id);
        $this->temporada = null;

        return $resultado;
    }

    /**
     * Crea o actualiza los estándares de planta listos. La primera versión rige desde la creación
     * en planta y una actualización desde la edición en planta, nunca antes que la versión anterior.
     *
     * @param  list<string>|null  $ids  estándares de la copia; null incorpora todos los listos
     * @return array{creados: int, actualizados: int, al_dia: int, omitidos: int}
     */
    public function incorporar(?array $ids, User $usuario): array
    {
        $revision = collect($this->revisar());
        if ($revision->isEmpty()) {
            throw new DomainException('No hay una lectura correcta de estándares de planta para incorporar.');
        }
        $resumen = ['creados' => 0, 'actualizados' => 0, 'al_dia' => 0, 'omitidos' => 0];
        $seleccion = $ids === null ? $revision : $revision->whereIn('id', $ids);

        DB::transaction(function () use ($seleccion, $usuario, &$resumen): void {
            $temporada = $this->temporadas->obtener(bloquear: true);
            foreach ($seleccion as $fila) {
                if (in_array($fila['estado'], ['falta_cliente', 'falta_embalaje'], true)) {
                    $resumen['omitidos']++;

                    continue;
                }
                if ($fila['estado'] === 'al_dia') {
                    $resumen['al_dia']++;

                    continue;
                }
                $origen = EstandarOrigen::query()->findOrFail($fila['id']);
                $estandar = EstandarMaterial::query()
                    ->where('temporada_id', $temporada->id)
                    ->where('cliente_id', $fila['cliente']['id'])
                    ->where('envase_validacion_id', $fila['envase']['id'])
                    ->where('origen', OrigenEstandarMaterial::Planta->value)
                    ->lockForUpdate()
                    ->first();
                $datos = [
                    'codigo' => mb_substr($origen->codigo_estandar ?? $origen->cod_articulo, 0, 60),
                    'descripcion' => mb_substr($origen->descripcion_estandar ?? $origen->descripcion_articulo ?? $origen->cod_articulo, 0, 200),
                    'codigo_articulo' => $origen->cod_articulo,
                    'descripcion_articulo' => $origen->descripcion_articulo,
                    'especie' => $origen->desc_especie,
                    'actualizado_por_user_id' => $usuario->id,
                ];
                $anterior = $estandar
                    ? VersionEstandarMaterial::query()->where('estandar_material_id', $estandar->id)->orderByDesc('numero')->first()
                    : null;
                if ($estandar) {
                    $estandar->update([...$datos, 'version' => $estandar->version + 1]);
                    $resumen['actualizados']++;
                } else {
                    $estandar = EstandarMaterial::create([
                        ...$datos,
                        'temporada_id' => $temporada->id,
                        'cliente_id' => $fila['cliente']['id'],
                        'envase_validacion_id' => $fila['envase']['id'],
                        'origen' => OrigenEstandarMaterial::Planta,
                        'activo' => true,
                        'version' => 1,
                        'creado_por_user_id' => $usuario->id,
                    ]);
                    $resumen['creados']++;
                }
                $fecha = ($anterior ? ($origen->fecha_edicion ?? $origen->fecha_creacion) : $origen->fecha_creacion)?->toDateString()
                    ?? now(config('app.operational_timezone'))->toDateString();
                if ($anterior && $fecha < $anterior->vigente_desde->toDateString()) {
                    $fecha = $anterior->vigente_desde->toDateString();
                }
                $this->estandares->crearVersion(
                    $estandar,
                    OrigenEstandarMaterial::Planta,
                    $fecha,
                    $anterior ? 'Cambio en planta o insumo homologado.' : 'Incorporado desde planta.',
                    $fila['huella'],
                    collect($fila['lineas'])->map(fn (array $linea): array => collect($linea)->except(['candidatos', 'repetido'])->all())->all(),
                    $usuario,
                );
            }
        }, 3);

        return $resumen;
    }

    /** @return array{cliente: ?ClienteValidacion, cliente_id: ?string, envase: ?EnvaseValidacion, lineas: list<array<string, mixed>>, huella: string} */
    private function resolver(EstandarOrigen $origen): array
    {
        $cliente = $this->catalogo->cliente($origen->cod_empresa);
        $especie = $this->catalogo->especie($origen->cod_especie, $origen->desc_especie);
        $envase = $this->catalogo->envase($especie, $cliente, $origen->cod_articulo, $origen->descripcion_articulo);
        $lineas = collect($origen->lineas)->map(function (array $linea) use ($origen, $cliente): array {
            [$item, $candidatos] = $this->insumo($origen->cod_empresa, $linea['cod_insumo'], $cliente?->cliente_id);

            return [
                'item_material_id' => $item?->id,
                'codigo_insumo' => $linea['cod_insumo'],
                'descripcion_insumo' => $linea['descripcion'],
                'unidad_medida' => $item?->unidad_medida,
                'cantidad_por_caja' => $linea['cantidad'],
                'redondeo' => $linea['redondeo'],
                'variacion_tipo' => null,
                'variacion_valor' => null,
                'repetido' => $linea['repetido'] ?? 1,
                'candidatos' => $item ? [] : $candidatos,
            ];
        })->all();

        return [
            'cliente' => $cliente,
            'cliente_id' => $cliente?->cliente_id,
            'envase' => $envase,
            'lineas' => $lineas,
            // La huella cambia si cambia el estándar en planta o si se homologa un insumo.
            'huella' => hash('sha256', $origen->huella.'|'.collect($lineas)->pluck('item_material_id')->implode(',')),
        ];
    }

    /** @return array{0: ?ItemMaterial, 1: list<array<string, string>>} */
    private function insumo(string $codEmpresa, string $codInsumo, ?string $clienteId): array
    {
        $vinculo = $this->vinculosInsumo->get(self::clave($codEmpresa).'|'.self::clave($codInsumo));
        $candidatos = $this->itemsPorCodigo->get(self::clave($codInsumo), collect());
        if ($vinculo) {
            $item = ItemMaterial::query()->find($vinculo->entidad_id);
            if ($item?->activo) {
                return [$item, []];
            }
        }
        $delCliente = $candidatos->filter(fn (ItemMaterial $item): bool => $item->cliente?->cliente_id === $clienteId);
        $elegido = match (true) {
            $delCliente->count() === 1 => $delCliente->first(),
            $delCliente->isEmpty() && $candidatos->count() === 1 => $candidatos->first(),
            default => null,
        };

        return [$elegido, $candidatos->map(fn (ItemMaterial $item): array => [
            'id' => $item->id, 'codigo' => $item->codigo, 'nombre' => $item->nombre, 'dueno' => $item->cliente?->cliente?->nombre ?? $item->cliente?->nombre,
        ])->values()->all()];
    }

    /** El catálogo y los vínculos se recargan en cada revisión: otro proceso pudo vincular o crear ítems. */
    private function preparar(bool $forzar = false): ?Temporada
    {
        $temporada = $this->catalogo->preparar($forzar);
        if (! $temporada) {
            return null;
        }
        if ($forzar || $this->temporada?->id !== $temporada->id) {
            $this->temporada = $temporada;
            $this->itemsPorCodigo = collect();
            ItemMaterial::query()
                ->with('cliente.cliente:id,codigo,nombre')
                ->where('activo', true)
                ->whereHas('cliente', fn ($consulta) => $consulta->where('activo', true)
                    ->whereHas('temporada', fn ($temporadas) => $temporadas->where('temporada_id', $temporada->id)))
                ->get()
                ->each(function (ItemMaterial $item): void {
                    foreach (array_unique(array_filter([self::clave($item->codigo), self::clave($item->codigo_externo)])) as $clave) {
                        $this->itemsPorCodigo->put($clave, $this->itemsPorCodigo->get($clave, collect())->push($item));
                    }
                });
            $this->vinculosInsumo = HomologacionStockOrigen::query()
                ->where('temporada_id', $temporada->id)
                ->where('dimension', self::DIMENSION_INSUMO)
                ->get()
                ->keyBy(fn (HomologacionStockOrigen $vinculo): string => $vinculo->contexto.'|'.$vinculo->valor);
        }

        return $this->temporada;
    }

    public static function clave(mixed $valor): string
    {
        return mb_strtoupper(Str::squish(Str::ascii((string) $valor)));
    }
}

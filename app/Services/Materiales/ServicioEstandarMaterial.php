<?php

namespace App\Services\Materiales;

use App\Enums\OrigenEstandarMaterial;
use App\Enums\RedondeoEstandarMaterial;
use App\Enums\VariacionEstandarMaterial;
use App\Exceptions\ConflictoOperacion;
use App\Models\Cliente;
use App\Models\EnvaseValidacion;
use App\Models\EstandarMaterial;
use App\Models\ItemMaterial;
use App\Models\LineaEstandarMaterial;
use App\Models\Temporada;
use App\Models\User;
use App\Models\VersionEstandarMaterial;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Estándares de consumo: cuánto material lleva cada caja de un embalaje PT de un cliente.
 *
 * Una versión no se modifica: un cambio crea otra versión que rige desde su fecha. Para un
 * mismo embalaje puede existir un estándar de planta y uno propio; en la rebaja manda el
 * propio activo y el de planta queda como referencia.
 */
class ServicioEstandarMaterial
{
    public const MAX_LINEAS = 200;

    /** Orden en que una variación desplaza a otra cuando varias calzan con la misma caja. */
    private const PRECEDENCIA_VARIACIONES = ['marca', 'variedad', 'calibre', 'categoria'];

    public function __construct(private readonly ServicioTemporadaActiva $temporadaActiva) {}

    /** @param array<string, mixed> $datos */
    public function crear(array $datos, User $usuario): EstandarMaterial
    {
        try {
            return DB::transaction(function () use ($datos, $usuario): EstandarMaterial {
                $temporada = $this->temporadaActiva->obtener(bloquear: true);
                $cliente = $this->cliente((string) $datos['cliente_id']);
                $envase = $this->envase((string) $datos['envase_validacion_id'], $temporada, $cliente);
                $this->asegurarLibre($temporada->id, $cliente->id, $envase->id, OrigenEstandarMaterial::Propio);
                $lineas = $this->lineasPropias($datos['lineas'] ?? [], $temporada);

                $estandar = EstandarMaterial::create([
                    'temporada_id' => $temporada->id,
                    'cliente_id' => $cliente->id,
                    'envase_validacion_id' => $envase->id,
                    'origen' => OrigenEstandarMaterial::Propio,
                    'codigo' => mb_substr($this->texto($datos['codigo'] ?? null) ?? ($this->texto($envase->codigo_externo) ?? $envase->nombre), 0, 60),
                    'descripcion' => $this->texto($datos['descripcion'] ?? null) ?? $envase->nombre,
                    'codigo_articulo' => $this->texto($envase->codigo_externo),
                    'descripcion_articulo' => $envase->nombre,
                    'especie' => $envase->especie?->nombre,
                    'activo' => true,
                    'version' => 1,
                    'creado_por_user_id' => $usuario->id,
                    'actualizado_por_user_id' => $usuario->id,
                ]);
                $this->crearVersion(
                    $estandar,
                    OrigenEstandarMaterial::Propio,
                    $this->fecha($datos['vigente_desde'] ?? null),
                    $this->texto($datos['motivo'] ?? null),
                    null,
                    $lineas,
                    $usuario,
                );

                return $this->cargar($estandar);
            });
        } catch (QueryException $exception) {
            throw $this->duplicado($exception);
        }
    }

    /**
     * Copia como propio un estándar (por ejemplo, el de planta) para ajustarlo en FoliOS.
     *
     * @param  array<string, mixed>  $datos
     */
    public function copiarComoPropio(EstandarMaterial $origen, array $datos, User $usuario): EstandarMaterial
    {
        try {
            return DB::transaction(function () use ($origen, $datos, $usuario): EstandarMaterial {
                $temporada = $this->temporadaActiva->obtener(bloquear: true);
                $origen = EstandarMaterial::query()->with('envase.especie')->lockForUpdate()->findOrFail($origen->id);
                if ($origen->temporada_id !== $temporada->id) {
                    throw new DomainException('Solo se pueden copiar estándares de la temporada activa.');
                }
                if ($origen->origen !== OrigenEstandarMaterial::Planta) {
                    throw new DomainException('Este estándar ya es propio: crea una nueva versión en vez de copiarlo.');
                }
                $this->asegurarLibre($temporada->id, $origen->cliente_id, $origen->envase_validacion_id, OrigenEstandarMaterial::Propio);
                $version = $this->ultimaVersion($origen);
                $sinItem = $version?->lineas->whereNull('item_material_id') ?? collect();
                if (! $version || $sinItem->isNotEmpty()) {
                    throw new DomainException(sprintf(
                        'Homologa primero los insumos de planta sin ítem de Materiales: %s.',
                        $sinItem->pluck('codigo_insumo')->unique()->implode(', ') ?: 'el estándar no tiene versión',
                    ));
                }
                $lineas = $this->lineasPropias($version->lineas->map(fn (LineaEstandarMaterial $linea): array => [
                    'item_material_id' => $linea->item_material_id,
                    'cantidad_por_caja' => $linea->cantidad_por_caja,
                    'redondeo' => $linea->redondeo->value,
                    'variacion_tipo' => $linea->variacion_tipo?->value,
                    'variacion_valor' => $linea->variacion_valor,
                ])->all(), $temporada);

                $copia = EstandarMaterial::create([
                    'temporada_id' => $temporada->id,
                    'cliente_id' => $origen->cliente_id,
                    'envase_validacion_id' => $origen->envase_validacion_id,
                    'origen' => OrigenEstandarMaterial::Propio,
                    'codigo' => $origen->codigo,
                    'descripcion' => $origen->descripcion,
                    'codigo_articulo' => $origen->codigo_articulo,
                    'descripcion_articulo' => $origen->descripcion_articulo,
                    'especie' => $origen->especie,
                    'activo' => true,
                    'version' => 1,
                    'estandar_base_id' => $origen->id,
                    'creado_por_user_id' => $usuario->id,
                    'actualizado_por_user_id' => $usuario->id,
                ]);
                $this->crearVersion(
                    $copia,
                    OrigenEstandarMaterial::Propio,
                    $this->fecha($datos['vigente_desde'] ?? null),
                    $this->texto($datos['motivo'] ?? null) ?? "Copia del estándar de planta (versión {$version->numero}).",
                    null,
                    $lineas,
                    $usuario,
                );

                return $this->cargar($copia);
            });
        } catch (QueryException $exception) {
            throw $this->duplicado($exception);
        }
    }

    /** @param array<string, mixed> $datos */
    public function versionar(EstandarMaterial $estandar, array $datos, User $usuario): EstandarMaterial
    {
        return DB::transaction(function () use ($estandar, $datos, $usuario): EstandarMaterial {
            $temporada = $this->temporadaActiva->obtener(bloquear: true);
            $estandar = EstandarMaterial::query()->lockForUpdate()->findOrFail($estandar->id);
            $this->asegurarVersion($estandar, $datos['version'] ?? null);
            if ($estandar->temporada_id !== $temporada->id) {
                throw new DomainException('Solo se pueden versionar estándares de la temporada activa.');
            }
            if ($estandar->origen !== OrigenEstandarMaterial::Propio) {
                throw new DomainException('Un estándar de planta se actualiza desde planta. Cópialo como propio para ajustarlo en FoliOS.');
            }
            if (! $estandar->activo) {
                throw new DomainException('Activa el estándar antes de crear una nueva versión.');
            }
            $lineas = $this->lineasPropias($datos['lineas'] ?? [], $temporada);
            $vigenteDesde = $this->fecha($datos['vigente_desde'] ?? null);
            $anterior = $this->ultimaVersion($estandar);
            if ($anterior && $vigenteDesde < $anterior->vigente_desde->toDateString()) {
                throw new DomainException(sprintf(
                    'La nueva versión debe regir desde el %s o después, cuando empieza la versión %d.',
                    $anterior->vigente_desde->format('d-m-Y'),
                    $anterior->numero,
                ));
            }
            $this->crearVersion(
                $estandar,
                OrigenEstandarMaterial::Propio,
                $vigenteDesde,
                $this->texto($datos['motivo'] ?? null),
                null,
                $lineas,
                $usuario,
            );
            $estandar->update([
                'codigo' => mb_substr($this->texto($datos['codigo'] ?? null) ?? $estandar->codigo, 0, 60),
                'descripcion' => $this->texto($datos['descripcion'] ?? null) ?? $estandar->descripcion,
                'version' => $estandar->version + 1,
                'actualizado_por_user_id' => $usuario->id,
            ]);

            return $this->cargar($estandar);
        });
    }

    public function cambiarEstado(EstandarMaterial $estandar, bool $activo, mixed $version, User $usuario): EstandarMaterial
    {
        return DB::transaction(function () use ($estandar, $activo, $version, $usuario): EstandarMaterial {
            $estandar = EstandarMaterial::query()->lockForUpdate()->findOrFail($estandar->id);
            $this->asegurarVersion($estandar, $version);
            if ($estandar->activo !== $activo) {
                $estandar->update([
                    'activo' => $activo,
                    'version' => $estandar->version + 1,
                    'actualizado_por_user_id' => $usuario->id,
                ]);
            }

            return $this->cargar($estandar);
        });
    }

    /**
     * Estándar y versión que rigen para las cajas de un embalaje embaladas en una fecha:
     * el propio activo manda sobre el de planta.
     *
     * @return array{estandar: EstandarMaterial, version: VersionEstandarMaterial, referencia: ?EstandarMaterial}|null
     */
    public function aplicable(string $temporadaId, string $clienteId, string $envaseId, string $fecha): ?array
    {
        $estandares = EstandarMaterial::query()
            ->where('temporada_id', $temporadaId)
            ->where('cliente_id', $clienteId)
            ->where('envase_validacion_id', $envaseId)
            ->where('activo', true)
            ->get()
            ->keyBy(fn (EstandarMaterial $estandar): string => $estandar->origen->value);
        $propio = $estandares->get(OrigenEstandarMaterial::Propio->value);
        $planta = $estandares->get(OrigenEstandarMaterial::Planta->value);
        foreach ([$propio, $planta] as $candidato) {
            $version = $candidato ? $this->versionVigente($candidato, $fecha) : null;
            if ($version) {
                return ['estandar' => $candidato, 'version' => $version, 'referencia' => $candidato === $propio ? $planta : null];
            }
        }

        return null;
    }

    public function versionVigente(EstandarMaterial $estandar, string $fecha): ?VersionEstandarMaterial
    {
        return VersionEstandarMaterial::query()
            ->with('lineas.item.cliente.cliente')
            ->where('estandar_material_id', $estandar->id)
            ->whereDate('vigente_desde', '<=', $fecha)
            ->orderByDesc('vigente_desde')
            ->orderByDesc('numero')
            ->first();
    }

    /**
     * Insumos que lleva una caja con estos atributos. Una línea con variación reemplaza a
     * la línea general del mismo ítem cuando la caja calza; si calzan varias, manda la marca,
     * luego variedad, calibre y categoría.
     *
     * @param  Collection<int, LineaEstandarMaterial>  $lineas
     * @param  array<string, ?string>  $atributos  marca, variedad, calibre, categoria
     * @return Collection<int, LineaEstandarMaterial>
     */
    public static function lineasParaCaja(Collection $lineas, array $atributos): Collection
    {
        $normalizados = collect($atributos)->map(fn (?string $valor): ?string => self::normalizarVariacion($valor));

        return $lineas
            ->groupBy(fn (LineaEstandarMaterial $linea): string => $linea->item_material_id ?? 'insumo:'.$linea->codigo_insumo)
            ->map(function (Collection $delInsumo) use ($normalizados): ?LineaEstandarMaterial {
                foreach (self::PRECEDENCIA_VARIACIONES as $tipo) {
                    $calza = $delInsumo->first(fn (LineaEstandarMaterial $linea): bool => $linea->variacion_tipo?->value === $tipo
                        && $linea->variacion_valor === $normalizados->get($tipo));
                    if ($calza) {
                        return $calza;
                    }
                }

                return $delInsumo->first(fn (LineaEstandarMaterial $linea): bool => $linea->variacion_tipo === null);
            })
            ->filter()
            ->sortBy('orden')
            ->values();
    }

    public static function normalizarVariacion(?string $valor): ?string
    {
        $texto = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $valor)));

        return $texto === '' ? null : $texto;
    }

    /**
     * Crea una versión con sus líneas ya normalizadas. También la usa la importación de
     * planta, donde una línea puede llegar sin ítem homologado.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     */
    public function crearVersion(
        EstandarMaterial $estandar,
        OrigenEstandarMaterial $origen,
        string $vigenteDesde,
        ?string $motivo,
        ?string $huella,
        array $lineas,
        User $usuario,
    ): VersionEstandarMaterial {
        $numero = (int) VersionEstandarMaterial::query()
            ->where('estandar_material_id', $estandar->id)
            ->lockForUpdate()
            ->max('numero') + 1;
        $version = VersionEstandarMaterial::create([
            'estandar_material_id' => $estandar->id,
            'numero' => $numero,
            'vigente_desde' => $vigenteDesde,
            'origen' => $origen,
            'motivo' => $motivo,
            'huella_origen' => $huella,
            'snapshot' => [
                'estandar' => ['codigo' => $estandar->codigo, 'descripcion' => $estandar->descripcion, 'codigo_articulo' => $estandar->codigo_articulo],
                'lineas' => $lineas,
            ],
            'creado_por_user_id' => $usuario->id,
        ]);
        foreach (array_values($lineas) as $indice => $linea) {
            LineaEstandarMaterial::create([
                'version_estandar_material_id' => $version->id,
                'orden' => $indice + 1,
                'item_material_id' => $linea['item_material_id'],
                'codigo_insumo' => $linea['codigo_insumo'],
                'descripcion_insumo' => $linea['descripcion_insumo'],
                'unidad_medida' => $linea['unidad_medida'],
                'cantidad_por_caja' => $linea['cantidad_por_caja'],
                'redondeo' => $linea['redondeo'],
                'variacion_tipo' => $linea['variacion_tipo'],
                'variacion_valor' => $linea['variacion_valor'],
            ]);
        }

        return $version;
    }

    public function cargar(EstandarMaterial $estandar): EstandarMaterial
    {
        return $estandar->refresh()->load([
            'temporada:id,codigo,nombre,activa',
            'cliente:id,codigo,nombre',
            'envase.especie:id,nombre',
            'base:id,origen,codigo',
            'versiones' => fn ($consulta) => $consulta->orderByDesc('numero'),
            'versiones.lineas.item.cliente.cliente:id,codigo,nombre',
            'versiones.creadoPor:id,name',
            'creadoPor:id,name',
            'actualizadoPor:id,name',
        ]);
    }

    /** @return array<string, mixed> */
    public function serializar(EstandarMaterial $estandar, bool $conVersiones = true): array
    {
        $hoy = $this->hoy();
        $versiones = $estandar->relationLoaded('versiones') ? $estandar->versiones->sortByDesc('numero')->values() : collect();
        $vigente = $versiones->filter(fn (VersionEstandarMaterial $version): bool => $version->vigente_desde->toDateString() <= $hoy)
            ->sortByDesc(fn (VersionEstandarMaterial $version): string => $version->vigente_desde->toDateString().sprintf('%06d', $version->numero))
            ->first();

        return [
            'id' => $estandar->id,
            'temporada' => $estandar->temporada ? ['id' => $estandar->temporada->id, 'codigo' => $estandar->temporada->codigo, 'nombre' => $estandar->temporada->nombre, 'activa' => (bool) $estandar->temporada->activa] : null,
            'cliente' => $estandar->cliente ? ['id' => $estandar->cliente->id, 'codigo' => $estandar->cliente->codigo, 'nombre' => $estandar->cliente->nombre] : null,
            'envase' => $estandar->envase ? [
                'id' => $estandar->envase->id,
                'nombre' => $estandar->envase->nombre,
                'codigo_externo' => $estandar->envase->codigo_externo,
                'especie' => $estandar->envase->especie?->nombre,
            ] : null,
            'origen' => $estandar->origen->value,
            'origen_nombre' => $estandar->origen->etiqueta(),
            'codigo' => $estandar->codigo,
            'descripcion' => $estandar->descripcion,
            'codigo_articulo' => $estandar->codigo_articulo,
            'descripcion_articulo' => $estandar->descripcion_articulo,
            'especie' => $estandar->especie,
            'activo' => $estandar->activo,
            'version' => $estandar->version,
            'estandar_base' => $estandar->base ? ['id' => $estandar->base->id, 'origen' => $estandar->base->origen->value, 'codigo' => $estandar->base->codigo] : null,
            'version_vigente' => $vigente?->numero,
            'version_futura' => $versiones->contains(fn (VersionEstandarMaterial $version): bool => $version->vigente_desde->toDateString() > $hoy),
            'cantidad_versiones' => $versiones->count(),
            'insumos_sin_item' => $vigente ? $vigente->lineas->whereNull('item_material_id')->count() : 0,
            'actualizado_por' => $estandar->actualizadoPor?->name,
            'updated_at' => $estandar->updated_at?->toIso8601String(),
            'versiones' => $conVersiones ? $versiones->map(fn (VersionEstandarMaterial $version): array => $this->serializarVersion($version, $vigente))->all() : [],
        ];
    }

    /** @return array<string, mixed> */
    private function serializarVersion(VersionEstandarMaterial $version, ?VersionEstandarMaterial $vigente): array
    {
        return [
            'id' => $version->id,
            'numero' => $version->numero,
            'vigente_desde' => $version->vigente_desde->toDateString(),
            'vigente' => $vigente?->id === $version->id,
            'origen' => $version->origen->value,
            'motivo' => $version->motivo,
            'creado_por' => $version->creadoPor?->name,
            'created_at' => $version->created_at?->toIso8601String(),
            'lineas' => $version->lineas->map(fn (LineaEstandarMaterial $linea): array => [
                'id' => $linea->id,
                'orden' => $linea->orden,
                'item_material_id' => $linea->item_material_id,
                'codigo_insumo' => $linea->codigo_insumo,
                'descripcion_insumo' => $linea->descripcion_insumo,
                'unidad_medida' => $linea->unidad_medida,
                'dueno' => $linea->item?->cliente?->cliente?->nombre ?? $linea->item?->cliente?->nombre,
                'cantidad_por_caja' => (float) $linea->cantidad_por_caja,
                'redondeo' => $linea->redondeo->value,
                'variacion_tipo' => $linea->variacion_tipo?->value,
                'variacion_nombre' => $linea->variacion_tipo?->etiqueta(),
                'variacion_valor' => $linea->variacion_valor,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<int, mixed>  $lineas
     * @return array<int, array<string, mixed>>
     */
    private function lineasPropias(array $lineas, Temporada $temporada): array
    {
        if ($lineas === [] || count($lineas) > self::MAX_LINEAS) {
            throw new DomainException(sprintf('El estándar debe tener entre 1 y %d insumos.', self::MAX_LINEAS));
        }
        $items = ItemMaterial::query()
            ->with(['cliente.cliente', 'cliente.temporada'])
            ->whereIn('id', collect($lineas)->pluck('item_material_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');
        $vistas = [];
        $normalizadas = [];
        foreach (array_values($lineas) as $indice => $linea) {
            $numero = $indice + 1;
            $item = $items->get((string) ($linea['item_material_id'] ?? ''));
            if (! $item || ! $item->activo || ! $item->cliente?->activo
                || $item->cliente->temporada?->temporada_id !== $temporada->id) {
                throw new DomainException("El insumo {$numero} no es un ítem activo de Materiales de la temporada {$temporada->codigo}.");
            }
            $cantidad = $this->cantidad($linea['cantidad_por_caja'] ?? null, $numero);
            $redondeo = RedondeoEstandarMaterial::tryFrom((string) ($linea['redondeo'] ?? ''));
            if (! $redondeo) {
                throw new DomainException("Indica si el insumo {$numero} se descuenta en unidades enteras o con decimales.");
            }
            $tipo = filled($linea['variacion_tipo'] ?? null) ? VariacionEstandarMaterial::tryFrom((string) $linea['variacion_tipo']) : null;
            $valor = self::normalizarVariacion(isset($linea['variacion_valor']) ? (string) $linea['variacion_valor'] : null);
            if (filled($linea['variacion_tipo'] ?? null) && ! $tipo) {
                throw new DomainException("La variación del insumo {$numero} debe ser marca, variedad, calibre o categoría.");
            }
            if (($tipo === null) !== ($valor === null) || mb_strlen((string) $valor) > 120) {
                throw new DomainException("La variación del insumo {$numero} necesita tipo y valor (hasta 120 caracteres), o ninguno.");
            }
            $clave = $item->id.'|'.$tipo?->value.'|'.$valor;
            if (isset($vistas[$clave])) {
                throw new DomainException(sprintf(
                    'El ítem %s está repetido%s. Deja una sola línea por ítem y variación.',
                    $item->codigo,
                    $tipo ? " para {$tipo->etiqueta()} {$valor}" : '',
                ));
            }
            $vistas[$clave] = true;
            $normalizadas[] = [
                'item_material_id' => $item->id,
                'codigo_insumo' => $item->codigo,
                'descripcion_insumo' => $item->nombre,
                'unidad_medida' => $item->unidad_medida,
                'cantidad_por_caja' => $cantidad,
                'redondeo' => $redondeo->value,
                'variacion_tipo' => $tipo?->value,
                'variacion_valor' => $valor,
            ];
        }

        return $normalizadas;
    }

    private function cliente(string $id): Cliente
    {
        $cliente = Cliente::query()->whereKey($id)->where('activo', true)->first();
        if (! $cliente) {
            throw new DomainException('Elige un cliente activo.');
        }

        return $cliente;
    }

    private function envase(string $id, Temporada $temporada, Cliente $cliente): EnvaseValidacion
    {
        $envase = EnvaseValidacion::query()->with(['especie', 'cliente'])->whereKey($id)->first();
        if (! $envase || $envase->especie?->temporada_id !== $temporada->id) {
            throw new DomainException("El embalaje no pertenece al catálogo PT de la temporada {$temporada->codigo}.");
        }
        if ($envase->cliente && $envase->cliente->cliente_id !== null && $envase->cliente->cliente_id !== $cliente->id) {
            throw new DomainException("El embalaje {$envase->nombre} es de otro cliente.");
        }

        return $envase;
    }

    private function asegurarLibre(string $temporadaId, string $clienteId, string $envaseId, OrigenEstandarMaterial $origen): void
    {
        $existente = EstandarMaterial::query()
            ->where('temporada_id', $temporadaId)
            ->where('cliente_id', $clienteId)
            ->where('envase_validacion_id', $envaseId)
            ->where('origen', $origen->value)
            ->lockForUpdate()
            ->first(['id', 'codigo']);
        if ($existente) {
            throw new ConflictoOperacion("Ya existe un estándar {$origen->etiqueta()} para este cliente y embalaje ({$existente->codigo}). Crea una nueva versión en él.");
        }
    }

    private function asegurarVersion(EstandarMaterial $estandar, mixed $version): void
    {
        if (! is_numeric($version) || (int) $version !== $estandar->version) {
            throw new ConflictoOperacion('El estándar cambió mientras lo editabas. Actualiza la pantalla y vuelve a intentarlo.');
        }
    }

    private function ultimaVersion(EstandarMaterial $estandar): ?VersionEstandarMaterial
    {
        return VersionEstandarMaterial::query()
            ->with('lineas')
            ->where('estandar_material_id', $estandar->id)
            ->orderByDesc('numero')
            ->first();
    }

    private function cantidad(mixed $valor, int $numero): string
    {
        $texto = trim(str_replace(',', '.', (string) $valor));
        if (! preg_match('/^\d{1,10}(?:\.\d{1,6})?$/D', $texto) || (float) $texto <= 0) {
            throw new DomainException("La cantidad por caja del insumo {$numero} debe ser mayor que cero, con hasta 6 decimales.");
        }

        return number_format((float) $texto, 6, '.', '');
    }

    private function fecha(mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return $this->hoy();
        }
        $fecha = CarbonImmutable::createFromFormat('!Y-m-d', (string) $valor, $this->zona());
        if (! $fecha || $fecha->format('Y-m-d') !== (string) $valor) {
            throw new DomainException('La fecha de vigencia debe tener el formato AAAA-MM-DD.');
        }

        return $fecha->toDateString();
    }

    private function hoy(): string
    {
        return CarbonImmutable::now($this->zona())->toDateString();
    }

    private function zona(): string
    {
        return (string) config('app.operational_timezone', 'America/Santiago');
    }

    private function texto(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : mb_substr($texto, 0, 200);
    }

    private function duplicado(QueryException $exception): \Throwable
    {
        if (str_contains($exception->getMessage(), 'estandar_material_embalaje_origen_unique')) {
            return new ConflictoOperacion('Ya existe un estándar con ese origen para este cliente y embalaje.', previous: $exception);
        }

        return $exception;
    }
}

<?php

namespace App\Services\Integraciones;

use App\Models\AliasCliente;
use App\Models\CalibreValidacion;
use App\Models\CategoriaValidacion;
use App\Models\Cliente;
use App\Models\ClienteValidacion;
use App\Models\CsgValidacion;
use App\Models\EnvaseValidacion;
use App\Models\EspecieValidacion;
use App\Models\HomologacionStockOrigen;
use App\Models\MarcaValidacion;
use App\Models\PalletStockOrigen;
use App\Models\Temporada;
use App\Models\User;
use App\Models\VariedadValidacion;
use App\Services\Clientes\ServicioCliente;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Validacion\ServicioCatalogoJerarquicoValidacion;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Relaciona lo que trae el stock del sistema de origen con el catálogo de validación de la
 * temporada activa, igual que las consultas SAG alimentan CSG, especies y variedades.
 *
 * Cada valor del origen se resuelve, en orden, por un vínculo guardado, por el código externo
 * del catálogo (el embalaje por cod_articulo, el cliente por cod_empresa) o por el mismo
 * nombre escrito tal cual. Lo que no se resuelve queda pendiente de catálogo: una persona con
 * permiso de catálogo lo vincula a un elemento existente o lo agrega. Nada se incorpora solo.
 */
class ServicioCatalogoStockOrigen
{
    public const DIMENSIONES = [
        'cliente' => 'Cliente',
        'marca' => 'Marca',
        'especie' => 'Especie',
        'variedad' => 'Variedad',
        'calibre' => 'Calibre',
        'envase' => 'Embalaje',
        'categoria' => 'Categoría',
        'csg' => 'CSG',
        'csg_variedad' => 'Variedad en CSG',
    ];

    /** Solo se vinculan: el cliente vive en el maestro de clientes y la categoría tiene muchas nomenclaturas. */
    private const SOLO_VINCULAR = ['cliente', 'categoria'];

    /** Solo se agregan: el CSG se reconoce por su código y la variedad del CSG es una autorización. */
    private const SOLO_AGREGAR = ['csg', 'csg_variedad'];

    /** Campos del pallet de origen que, si vienen mezclados, impiden resolver la dimensión. */
    private const CAMPOS = [
        'cliente' => ['empresa'],
        'marca' => ['marca'],
        'especie' => ['especie', 'especie_codigo'],
        'variedad' => ['variedad'],
        'calibre' => ['calibre'],
        'envase' => ['envase', 'envase_codigo'],
        'categoria' => ['categoria'],
    ];

    /** Así une la lectura del origen los valores distintos de un mismo pallet. */
    private const SEPARADOR_MEZCLA = ' / ';

    private ?string $temporadaCargada = null;

    /** @var Collection<int, ClienteValidacion> */
    private Collection $clientes;

    /** @var Collection<int, EspecieValidacion> */
    private Collection $especies;

    /** @var Collection<int, CategoriaValidacion> */
    private Collection $categorias;

    /** @var Collection<string, CsgValidacion> */
    private Collection $csgs;

    /** @var Collection<string, HomologacionStockOrigen> */
    private Collection $vinculos;

    /** @var array<string, ?ClienteValidacion> */
    private array $clientesResueltos = [];

    public function __construct(
        private readonly ServicioTemporadaActiva $temporadas,
        private readonly ServicioCliente $servicioClientes,
        private readonly ServicioCatalogoJerarquicoValidacion $catalogo,
        private readonly ServicioLecturaStockOrigen $lecturas,
    ) {}

    /** Carga el catálogo de la temporada activa en memoria. Sin temporada activa no hay con qué comparar. */
    public function preparar(bool $forzar = false): ?Temporada
    {
        $temporada = $this->temporadas->buscar();
        if (! $temporada) {
            $this->temporadaCargada = null;

            return null;
        }
        if ($forzar || $this->temporadaCargada !== $temporada->id) {
            $this->temporadaCargada = $temporada->id;
            $this->clientesResueltos = [];
            $this->clientes = ClienteValidacion::query()->where('temporada_id', $temporada->id)->with('marcas')->get();
            $this->especies = EspecieValidacion::query()->where('temporada_id', $temporada->id)
                ->with(['variedades', 'calibres', 'envases'])->get();
            $this->categorias = CategoriaValidacion::query()->where('temporada_id', $temporada->id)->get();
            $this->csgs = CsgValidacion::query()->where('temporada_id', $temporada->id)->with('variedades:id')->get()
                ->keyBy(fn (CsgValidacion $csg): string => $this->clave($csg->codigo));
            $this->vinculos = HomologacionStockOrigen::query()->where('temporada_id', $temporada->id)->get()
                ->keyBy(fn (HomologacionStockOrigen $vinculo): string => $this->llave($vinculo->dimension, $vinculo->contexto, $vinculo->valor));
        }

        return $temporada;
    }

    /**
     * Elementos del catálogo que corresponden al artículo del pallet. Una dimensión mezclada
     * (por ejemplo, dos calibres en el mismo pallet) queda sin resolver, pero no cuenta como
     * faltante por sí misma: cada valor se revisa por separado.
     *
     * @return array{cliente: ?ClienteValidacion, marca: ?MarcaValidacion, especie: ?EspecieValidacion, variedad: ?VariedadValidacion, calibre: ?CalibreValidacion, envase: ?EnvaseValidacion, categoria: ?CategoriaValidacion, sin_catalogo: list<string>}
     */
    public function resolver(PalletStockOrigen $pallet): array
    {
        if (! $this->preparar()) {
            return ['cliente' => null, 'marca' => null, 'especie' => null, 'variedad' => null, 'calibre' => null, 'envase' => null, 'categoria' => null, 'sin_catalogo' => []];
        }
        $unico = fn (string $dimension): bool => ! $this->mezclado($pallet, $dimension);
        $cliente = $unico('cliente') ? $this->cliente($pallet->empresa) : null;
        $especie = $unico('especie') ? $this->especie($pallet->especie_codigo, $pallet->especie) : null;
        $resultado = [
            'cliente' => $cliente,
            'marca' => $unico('marca') ? $this->marca($cliente, $pallet->marca) : null,
            'especie' => $especie,
            'variedad' => $unico('variedad') ? $this->variedad($especie, $pallet->variedad) : null,
            'calibre' => $unico('calibre') ? $this->calibre($especie, $pallet->calibre) : null,
            'envase' => $unico('envase') ? $this->envase($especie, $cliente, $pallet->envase_codigo, $pallet->envase) : null,
            'categoria' => $unico('categoria') ? $this->categoria($pallet->categoria) : null,
        ];

        $faltan = [];
        foreach (['cliente', 'marca', 'especie', 'variedad', 'calibre', 'envase'] as $dimension) {
            $partes = collect($this->partes($pallet, $dimension));
            $falta = match ($dimension) {
                'marca' => $partes->contains(fn (string $valor): bool => ! $this->marca($cliente, $valor)),
                'variedad' => $partes->contains(fn (string $valor): bool => ! $this->variedad($especie, $valor)),
                'calibre' => $partes->contains(fn (string $valor): bool => ! $this->calibre($especie, $valor)),
                default => $partes->isNotEmpty() && $resultado[$dimension] === null,
            };
            if ($falta) {
                $faltan[] = $dimension;
            }
        }
        if (collect($pallet->composicion ?? [])->contains(fn (mixed $linea): bool => is_array($linea)
            && $this->texto($linea['csg'] ?? null) !== null && ! $this->csg($linea['csg']))) {
            $faltan[] = 'csg';
        }

        return [...$resultado, 'sin_catalogo' => $faltan];
    }

    public function cliente(?string $codigo): ?ClienteValidacion
    {
        $codigo = $this->texto($codigo);
        if ($codigo === null || ! $this->preparar()) {
            return null;
        }
        if (array_key_exists($codigo, $this->clientesResueltos)) {
            return $this->clientesResueltos[$codigo];
        }
        // cod_empresa: código, código externo, alias o nombre del maestro de clientes.
        $global = $this->servicioClientes->buscarPorReferencia($codigo);

        return $this->clientesResueltos[$codigo] = ($global ? $this->clientes->firstWhere('cliente_id', $global->id) : null)
            ?? $this->porCodigoONombre($this->clientes, $codigo, $codigo);
    }

    public function marca(?ClienteValidacion $cliente, ?string $valor): ?MarcaValidacion
    {
        if (! $cliente || ($valor = $this->texto($valor)) === null) {
            return null;
        }

        return $this->vinculado($cliente->marcas, 'marca', $cliente->id, $valor)
            ?? $this->porCodigoONombre($cliente->marcas, $valor, $valor);
    }

    public function especie(?string $codigo, ?string $descripcion): ?EspecieValidacion
    {
        $valor = $this->texto($codigo) ?? $this->texto($descripcion);
        if ($valor === null || ! $this->preparar()) {
            return null;
        }

        return $this->vinculado($this->especies, 'especie', '', $valor)
            ?? $this->porCodigoONombre($this->especies, $this->texto($codigo), $descripcion, singular: true);
    }

    public function variedad(?EspecieValidacion $especie, ?string $nombre): ?VariedadValidacion
    {
        if (! $especie || ($nombre = $this->texto($nombre)) === null) {
            return null;
        }

        return $this->vinculado($especie->variedades, 'variedad', $especie->id, $nombre)
            ?? $this->porCodigoONombre($especie->variedades, null, $nombre);
    }

    public function calibre(?EspecieValidacion $especie, ?string $codigo): ?CalibreValidacion
    {
        if (! $especie || ($codigo = $this->texto($codigo)) === null) {
            return null;
        }

        return $this->vinculado($especie->calibres, 'calibre', $especie->id, $codigo)
            ?? $this->porCodigoONombre($especie->calibres, $codigo, $codigo);
    }

    /** El embalaje se reconoce por cod_articulo (código externo del envase del cliente) o por su descripción. */
    public function envase(?EspecieValidacion $especie, ?ClienteValidacion $cliente, ?string $codigo, ?string $descripcion): ?EnvaseValidacion
    {
        $valor = $this->texto($codigo) ?? $this->texto($descripcion);
        if (! $especie || ! $cliente || $valor === null) {
            return null;
        }

        return $this->vinculado($especie->envases, 'envase', $this->contextoEnvase($especie->id, $cliente->id), $valor)
            ?? $this->porCodigoONombre($especie->envases->where('cliente_validacion_id', $cliente->id), $this->texto($codigo), $descripcion);
    }

    public function categoria(?string $codigo): ?CategoriaValidacion
    {
        if (($codigo = $this->texto($codigo)) === null || ! $this->preparar()) {
            return null;
        }

        return $this->vinculado($this->categorias, 'categoria', '', $codigo)
            ?? $this->porCodigoONombre($this->categorias, $codigo, $codigo);
    }

    public function csg(?string $codigo): ?CsgValidacion
    {
        if (($codigo = $this->texto($codigo)) === null || ! $this->preparar()) {
            return null;
        }

        return $this->csgs->get($this->clave($codigo));
    }

    /**
     * Valores del stock de origen que el catálogo de la temporada todavía no tiene, agrupados
     * por valor, con los pallets y cajas que dependen de cada uno.
     *
     * @return list<array<string, mixed>>
     */
    public function pendientes(): array
    {
        if (! $this->preparar()) {
            return [];
        }
        $items = [];
        $sumar = function (string $dimension, string $contexto, string $valor, array $datos, PalletStockOrigen $pallet) use (&$items): string {
            $clave = $this->llave($dimension, $contexto, $valor);
            $items[$clave] ??= ['clave' => $clave, 'dimension' => $dimension, 'valor' => $valor, 'etiqueta' => $valor, 'requiere' => null, ...$datos, 'pallets' => [], 'cajas' => 0];
            if (! in_array($pallet->numero_pallet, $items[$clave]['pallets'], true)) {
                $items[$clave]['pallets'][] = $pallet->numero_pallet;
                $items[$clave]['cajas'] += $pallet->cajas;
            }

            return $clave;
        };

        foreach ($this->palletsPresentes() as $pallet) {
            $resuelto = $this->resolver($pallet);
            $faltan = $resuelto['sin_catalogo'];
            $cliente = $resuelto['cliente'];
            $especie = $resuelto['especie'];
            $delCliente = $cliente
                ? ['cliente' => $cliente->nombre, 'cliente_validacion_id' => $cliente->id]
                : ['cliente' => $pallet->empresa, 'cliente_validacion_id' => null, 'requiere' => 'cliente'];
            $deEspecie = $especie
                ? ['especie' => $especie->nombre, 'especie_validacion_id' => $especie->id]
                : ['especie' => $pallet->especie, 'especie_validacion_id' => null, 'requiere' => 'especie'];

            if (in_array('cliente', $faltan, true)) {
                $sumar('cliente', '', $pallet->empresa, [], $pallet);
            }
            foreach ($this->partes($pallet, 'marca') as $marca) {
                if (! $this->marca($cliente, $marca)) {
                    $sumar('marca', $cliente?->id ?? '?'.$pallet->empresa, $marca, $delCliente, $pallet);
                }
            }
            if (in_array('especie', $faltan, true)) {
                $sumar('especie', '', $pallet->especie_codigo ?: $pallet->especie, [
                    'etiqueta' => $this->etiqueta($pallet->especie_codigo, $pallet->especie),
                    'codigo' => $pallet->especie_codigo,
                    'descripcion' => $pallet->especie,
                ], $pallet);
            }
            foreach (['variedad', 'calibre'] as $dimension) {
                foreach ($this->partes($pallet, $dimension) as $valor) {
                    $existe = $dimension === 'variedad' ? $this->variedad($especie, $valor) : $this->calibre($especie, $valor);
                    if (! $existe) {
                        $sumar($dimension, $especie?->id ?? '?'.$pallet->especie, $valor, [
                            ...$deEspecie,
                            'codigo' => $dimension === 'variedad' && ! $this->mezclado($pallet, 'variedad') ? $pallet->variedad_codigo : null,
                        ], $pallet);
                    }
                }
            }
            if (in_array('envase', $faltan, true)) {
                $sumar('envase', $especie && $cliente ? $this->contextoEnvase($especie->id, $cliente->id) : '?'.$pallet->especie.'|'.$pallet->empresa,
                    $pallet->envase_codigo ?: $pallet->envase, [
                        ...$delCliente,
                        ...$deEspecie,
                        'requiere' => ! $especie ? 'especie' : (! $cliente ? 'cliente' : null),
                        'etiqueta' => $this->etiqueta($pallet->envase_codigo, $pallet->envase),
                        'codigo' => $pallet->envase_codigo,
                        'descripcion' => $pallet->envase,
                        'kilos' => $pallet->envase_kilos !== null ? (float) $pallet->envase_kilos : null,
                    ], $pallet);
            }
            foreach ($this->partes($pallet, 'categoria') as $categoria) {
                if (! $this->categoria($categoria)) {
                    $sumar('categoria', '', $categoria, [], $pallet);
                }
            }

            $variedadesPallet = $this->partes($pallet, 'variedad');
            foreach ($pallet->composicion ?? [] as $linea) {
                if (! is_array($linea) || ($codigo = $this->texto($linea['csg'] ?? null)) === null) {
                    continue;
                }
                $variedadLinea = $this->texto($linea['variedad'] ?? null);
                $nombresVariedad = $variedadLinea !== null ? [$variedadLinea] : $variedadesPallet;
                $csg = $this->csg($codigo);
                if (! $csg) {
                    $clave = $sumar('csg', '', mb_strtoupper($codigo), ['predio' => null, 'variedades' => []], $pallet);
                    $items[$clave]['predio'] ??= $this->texto($linea['predio'] ?? null);
                    $items[$clave]['variedades'] = array_values(array_unique([...$items[$clave]['variedades'], ...$nombresVariedad]));

                    continue;
                }
                foreach ($nombresVariedad as $nombreVariedad) {
                    $variedad = $this->variedad($especie, $nombreVariedad);
                    if ($variedad && ! $csg->variedades->contains('id', $variedad->id)) {
                        $sumar('csg_variedad', $csg->id, $variedad->id, [
                            'etiqueta' => "{$variedad->nombre} en {$csg->codigo}",
                            'csg' => $csg->codigo,
                            'csg_validacion_id' => $csg->id,
                            'variedad' => $variedad->nombre,
                        ], $pallet);
                    }
                }
            }
        }

        $orden = array_flip(array_keys(self::DIMENSIONES));

        return collect($items)
            ->map(fn (array $item): array => [
                ...$item,
                'dimension_etiqueta' => self::DIMENSIONES[$item['dimension']],
                'acciones' => $this->acciones($item),
                'pallets_total' => count($item['pallets']),
                'pallets' => array_slice($item['pallets'], 0, 12),
            ])
            ->sortBy(fn (array $item): string => sprintf('%02d|%s', $orden[$item['dimension']], $this->clave($item['etiqueta'])))
            ->values()
            ->all();
    }

    /**
     * Elementos activos del catálogo a los que se puede vincular un valor del origen.
     *
     * @return array<string, mixed>
     */
    public function opciones(): array
    {
        if (! $this->preparar()) {
            return [];
        }
        $lista = fn (Collection $modelos): array => $modelos->where('activo', true)->sortBy('nombre')
            ->map(fn (Model $modelo): array => [
                'id' => $modelo->getKey(),
                'nombre' => $modelo->getAttribute('nombre'),
                'codigo_externo' => $modelo->getAttribute('codigo_externo'),
            ])
            ->values()->all();
        $envases = [];
        foreach ($this->especies as $especie) {
            foreach ($especie->envases->groupBy('cliente_validacion_id') as $clienteId => $delCliente) {
                $envases[$this->contextoEnvase($especie->id, (string) $clienteId)] = $lista($delCliente);
            }
        }

        return [
            'clientes' => Cliente::query()->where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre'])
                ->map(fn (Cliente $cliente): array => ['id' => $cliente->id, 'nombre' => $cliente->nombre, 'codigo_externo' => $cliente->codigo])
                ->all(),
            'especies' => $lista($this->especies),
            'categorias' => $lista($this->categorias),
            'marcas' => $this->clientes->mapWithKeys(fn (ClienteValidacion $cliente): array => [$cliente->id => $lista($cliente->marcas)])->all(),
            'variedades' => $this->especies->mapWithKeys(fn (EspecieValidacion $especie): array => [$especie->id => $lista($especie->variedades)])->all(),
            'calibres' => $this->especies->mapWithKeys(fn (EspecieValidacion $especie): array => [$especie->id => $lista($especie->calibres)])->all(),
            'envases' => $envases,
        ];
    }

    /**
     * Vincula un valor pendiente a un elemento existente del catálogo o lo agrega.
     *
     * @return array{dimension: string, accion: string, entidad: string, mensaje: string}
     */
    public function aplicar(string $clave, string $accion, ?string $entidadId, User $usuario): array
    {
        $item = collect($this->pendientes())->firstWhere('clave', $clave);
        if (! $item) {
            throw new DomainException('Ese valor ya no está pendiente. Actualiza la lista.');
        }
        if (! in_array($accion, $item['acciones'], true)) {
            throw new DomainException(match ($item['requiere']) {
                'cliente' => "Primero vincula el cliente {$item['cliente']}.",
                'especie' => "Primero vincula o agrega la especie {$item['especie']}.",
                default => 'Esa acción no está disponible para este valor.',
            });
        }
        $temporada = $this->preparar();

        try {
            return DB::transaction(fn (): array => $accion === 'vincular'
                ? $this->vincular($temporada, $item, (string) $entidadId, $usuario)
                : $this->incorporar($temporada, $item, $usuario), attempts: 3);
        } finally {
            $this->preparar(forzar: true);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{dimension: string, accion: string, entidad: string, mensaje: string}
     */
    private function vincular(Temporada $temporada, array $item, string $entidadId, User $usuario): array
    {
        if ($item['dimension'] === 'cliente') {
            $cliente = Cliente::query()->where('activo', true)->find($entidadId)
                ?? throw new DomainException('Elige un cliente activo del maestro de clientes.');
            // El alias deja reconocido el cod_empresa del origen en esta y en las próximas temporadas.
            AliasCliente::query()->firstOrCreate([
                'cliente_id' => $cliente->id,
                'origen' => 'stock_origen',
                'codigo' => mb_substr($item['valor'], 0, 150),
                'nombre' => mb_substr($item['valor'], 0, 180),
            ]);
            $this->servicioClientes->asegurarProyeccionesActivas($cliente, $usuario->id);
            $proyeccion = ClienteValidacion::query()->where('temporada_id', $temporada->id)->where('cliente_id', $cliente->id)->first();
            $this->registrar($temporada, 'cliente', '', $item['valor'], $proyeccion?->id ?? $cliente->id, 'vinculado', $usuario);

            return $this->resultado($item, 'vincular', $cliente->nombre);
        }
        $modelo = match ($item['dimension']) {
            'marca' => MarcaValidacion::query()->where('cliente_validacion_id', $item['cliente_validacion_id'])->find($entidadId),
            'especie' => EspecieValidacion::query()->where('temporada_id', $temporada->id)->find($entidadId),
            'variedad' => VariedadValidacion::query()->where('especie_validacion_id', $item['especie_validacion_id'])->find($entidadId),
            'calibre' => CalibreValidacion::query()->where('especie_validacion_id', $item['especie_validacion_id'])->find($entidadId),
            'envase' => EnvaseValidacion::query()->where('especie_validacion_id', $item['especie_validacion_id'])
                ->where('cliente_validacion_id', $item['cliente_validacion_id'])->find($entidadId),
            'categoria' => CategoriaValidacion::query()->where('temporada_id', $temporada->id)->find($entidadId),
            default => null,
        };
        if (! $modelo) {
            throw new DomainException('Elige un elemento del catálogo que corresponda a este valor.');
        }
        $this->registrar($temporada, $item['dimension'], $this->contexto($item), $item['valor'], $modelo->getKey(), 'vinculado', $usuario);

        return $this->resultado($item, 'vincular', (string) $modelo->getAttribute('nombre'));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{dimension: string, accion: string, entidad: string, mensaje: string}
     */
    private function incorporar(Temporada $temporada, array $item, User $usuario): array
    {
        // Mismo criterio que las consultas SAG: especies y variedades con mayúscula inicial.
        $nombre = fn (?string $valor): string => mb_convert_case(Str::squish((string) $valor), MB_CASE_TITLE, 'UTF-8');
        $modelo = match ($item['dimension']) {
            'marca' => $this->catalogo->guardarMarca([
                'cliente_validacion_id' => $item['cliente_validacion_id'],
                'nombre' => $item['valor'],
            ]),
            'especie' => $this->catalogo->guardarEspecie([
                'temporada_id' => $temporada->id,
                'nombre' => $nombre($item['descripcion'] ?? $item['valor']),
                'codigo_externo' => $item['codigo'] ?? null,
            ]),
            'variedad' => $this->catalogo->guardarVariedad([
                'especie_validacion_id' => $item['especie_validacion_id'],
                'nombre' => $nombre($item['valor']),
                'codigo_externo' => $item['codigo'] ?? null,
            ]),
            'calibre' => $this->catalogo->guardarCalibre([
                'especie_validacion_id' => $item['especie_validacion_id'],
                'nombre' => $item['valor'],
            ]),
            'envase' => $this->catalogo->guardarEnvase([
                'especie_validacion_id' => $item['especie_validacion_id'],
                'cliente_validacion_id' => $item['cliente_validacion_id'],
                'nombre' => $item['descripcion'] ?? $item['valor'],
                'codigo_externo' => $item['codigo'] ?? null,
                'kilos_netos_por_caja' => $item['kilos'] ?? null,
            ]),
            'csg' => $this->catalogo->guardarCsg([
                'temporada_id' => $temporada->id,
                'codigo' => $item['valor'],
                'predio' => $item['predio'] ?? null,
                'variedad_ids' => $this->idsVariedades($item['variedades'] ?? []),
            ]),
            'csg_variedad' => $this->autorizarVariedad($item['csg_validacion_id'], $item['valor']),
            default => throw new DomainException('Ese valor no se puede agregar al catálogo.'),
        };
        $this->registrar($temporada, $item['dimension'], $this->contexto($item), $item['valor'], $modelo->getKey(), 'incorporado', $usuario);

        return $this->resultado($item, 'incorporar', (string) ($modelo->getAttribute('nombre') ?? $modelo->getAttribute('codigo')));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{dimension: string, accion: string, entidad: string, mensaje: string}
     */
    private function resultado(array $item, string $accion, string $entidad): array
    {
        return [
            'dimension' => $item['dimension'],
            'accion' => $accion,
            'entidad' => $entidad,
            'mensaje' => match (true) {
                $accion === 'vincular' => "«{$item['etiqueta']}» ahora se reconoce como «{$entidad}».",
                $item['dimension'] === 'csg_variedad' => "{$item['variedad']} quedó autorizada en el CSG {$item['csg']}.",
                default => "«{$entidad}» se agregó al catálogo de la temporada.",
            },
        ];
    }

    private function autorizarVariedad(string $csgId, string $variedadId): CsgValidacion
    {
        $csg = CsgValidacion::query()->with('variedades:id')->findOrFail($csgId);

        return $this->catalogo->guardarCsg([
            'temporada_id' => $csg->temporada_id,
            'codigo' => $csg->codigo,
            'predio' => $csg->predio,
            'codigo_externo' => $csg->codigo_externo,
            'activo' => $csg->activo,
            'variedad_ids' => array_values(array_unique([...$csg->variedades->pluck('id')->all(), $variedadId])),
        ], $csg);
    }

    /**
     * Variedades del catálogo con esos nombres. Las que aún no existen se autorizan en el CSG
     * cuando se agregan.
     *
     * @param  list<string>  $nombres
     * @return list<string>
     */
    private function idsVariedades(array $nombres): array
    {
        return collect($nombres)
            ->flatMap(fn (string $nombre): array => $this->especies
                ->map(fn (EspecieValidacion $especie): ?string => $this->variedad($especie, $nombre)?->id)
                ->filter()->all())
            ->unique()->values()->all();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function acciones(array $item): array
    {
        return match (true) {
            $item['requiere'] !== null => [],
            in_array($item['dimension'], self::SOLO_VINCULAR, true) => ['vincular'],
            in_array($item['dimension'], self::SOLO_AGREGAR, true) => ['incorporar'],
            default => ['vincular', 'incorporar'],
        };
    }

    /** @param array<string, mixed> $item */
    private function contexto(array $item): string
    {
        return match ($item['dimension']) {
            'marca' => (string) $item['cliente_validacion_id'],
            'variedad', 'calibre' => (string) $item['especie_validacion_id'],
            'envase' => $this->contextoEnvase($item['especie_validacion_id'], $item['cliente_validacion_id']),
            'csg_variedad' => (string) $item['csg_validacion_id'],
            default => '',
        };
    }

    private function registrar(Temporada $temporada, string $dimension, string $contexto, string $valor, string $entidadId, string $accion, User $usuario): void
    {
        HomologacionStockOrigen::query()->updateOrCreate(
            ['temporada_id' => $temporada->id, 'dimension' => $dimension, 'contexto' => $contexto, 'valor' => mb_substr($this->clave($valor), 0, 150)],
            ['entidad_id' => $entidadId, 'accion' => $accion, 'creado_por_user_id' => $usuario->id],
        );
    }

    /** @return Collection<int, PalletStockOrigen> */
    private function palletsPresentes(): Collection
    {
        return PalletStockOrigen::query()
            ->where('bodega', $this->lecturas->configuracion()['bodega'])
            ->where('presente', true)
            ->orderBy('numero_pallet')
            ->get();
    }

    private function mezclado(PalletStockOrigen $pallet, string $dimension): bool
    {
        return array_intersect(self::CAMPOS[$dimension], $pallet->mezclados ?? []) !== [];
    }

    /**
     * Valores de una dimensión del pallet. Marca, variedad, calibre y categoría mezclados se
     * separan para revisar cada uno; cliente, especie y embalaje mezclados no se pueden separar
     * sin perder el par de código y descripción, así que se omiten.
     *
     * @return list<string>
     */
    private function partes(PalletStockOrigen $pallet, string $dimension): array
    {
        $valor = $this->texto(match ($dimension) {
            'cliente' => $pallet->empresa,
            'especie' => $pallet->especie_codigo ?: $pallet->especie,
            'envase' => $pallet->envase_codigo ?: $pallet->envase,
            default => $pallet->getAttribute($dimension),
        });
        if ($valor === null) {
            return [];
        }
        if (! $this->mezclado($pallet, $dimension)) {
            return [$valor];
        }
        if (in_array($dimension, ['cliente', 'especie', 'envase'], true)) {
            return [];
        }

        return collect(explode(self::SEPARADOR_MEZCLA, $valor))
            ->map(fn (string $parte): ?string => $this->texto($parte))
            ->filter()->unique()->values()->all();
    }

    /** @param Collection<int, Model> $modelos */
    private function vinculado(Collection $modelos, string $dimension, string $contexto, string $valor): ?Model
    {
        $vinculo = $this->vinculos->get($this->llave($dimension, $contexto, $valor));

        return $vinculo ? $modelos->firstWhere('id', $vinculo->entidad_id) : null;
    }

    /**
     * Mismo código externo, o mismo nombre escrito tal cual (sin distinguir mayúsculas, tildes
     * ni espacios). En especies también se acepta singular y plural («CEREZAS» = Cereza).
     *
     * @param  Collection<int, Model>  $modelos
     */
    private function porCodigoONombre(Collection $modelos, ?string $codigo, ?string $nombre, bool $singular = false): ?Model
    {
        if ($codigo !== null) {
            $buscado = $this->clave($codigo);
            $porCodigo = $modelos->first(fn (Model $modelo): bool => $this->texto($modelo->getAttribute('codigo_externo')) !== null
                && $this->clave($modelo->getAttribute('codigo_externo')) === $buscado);
            if ($porCodigo) {
                return $porCodigo;
            }
        }
        if (($nombre = $this->texto($nombre)) === null) {
            return null;
        }
        $buscado = $this->clave($nombre);

        return $modelos->first(function (Model $modelo) use ($buscado, $singular): bool {
            $propio = $this->clave($modelo->getAttribute('nombre'));

            return $propio === $buscado || ($singular && $this->singular($propio) === $this->singular($buscado));
        });
    }

    private function etiqueta(?string $codigo, ?string $descripcion): string
    {
        return collect([$this->texto($codigo), $this->texto($descripcion)])->filter()->unique()->implode(' · ');
    }

    private function contextoEnvase(string $especieId, string $clienteId): string
    {
        return $especieId.'|'.$clienteId;
    }

    private function llave(string $dimension, string $contexto, string $valor): string
    {
        return $dimension.'|'.$contexto.'|'.$this->clave($valor);
    }

    private function clave(mixed $valor): string
    {
        return mb_strtoupper(Str::squish(Str::ascii((string) $valor)));
    }

    private function singular(string $clave): string
    {
        return preg_replace('/(ES|S)$/', '', $clave) ?? $clave;
    }

    private function texto(mixed $valor): ?string
    {
        $texto = Str::squish((string) $valor);

        return $texto === '' ? null : $texto;
    }
}

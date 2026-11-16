<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrigenEstandarMaterial;
use App\Enums\RedondeoEstandarMaterial;
use App\Enums\VariacionEstandarMaterial;
use App\Http\Controllers\Controller;
use App\Models\CalibreValidacion;
use App\Models\CategoriaValidacion;
use App\Models\Cliente;
use App\Models\ClienteValidacion;
use App\Models\EnvaseValidacion;
use App\Models\EstandarMaterial;
use App\Models\ItemMaterial;
use App\Models\MarcaValidacion;
use App\Models\Temporada;
use App\Models\VariedadValidacion;
use App\Services\Existencias\GeneradorLibroXlsx;
use App\Services\Materiales\ServicioEstandarMaterial;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Estándares de consumo de materiales por embalaje PT (Materiales → Estándares de consumo). */
class EstandarMaterialController extends Controller
{
    public function __construct(private readonly ServicioEstandarMaterial $servicio) {}

    public function index(Request $request, ServicioTemporadaActiva $temporadaActiva): JsonResponse
    {
        $filtros = $this->filtros($request, $temporadaActiva);
        $pagina = $this->consulta($filtros)
            ->with($this->relacionesListado())
            ->orderBy('codigo')
            ->orderBy('origen')
            ->paginate(min(100, max(10, (int) $request->query('per_page', 25))));

        return response()->json([
            'data' => collect($pagina->items())->map(fn (EstandarMaterial $estandar): array => $this->servicio->serializar($estandar, false))->all(),
            'meta' => ['page' => $pagina->currentPage(), 'last_page' => $pagina->lastPage(), 'total' => $pagina->total()],
            'temporada_id' => $filtros['temporada_id'],
            'puede_administrar' => $request->user()->can('administrar-estandares-materiales'),
        ]);
    }

    public function show(EstandarMaterial $estandarMaterial): JsonResponse
    {
        return response()->json(['data' => $this->servicio->serializar($this->servicio->cargar($estandarMaterial))]);
    }

    /** Temporadas, clientes y, para un cliente, sus embalajes PT y valores de variación. */
    public function opciones(Request $request, ServicioTemporadaActiva $temporadaActiva): JsonResponse
    {
        $datos = $request->validate([
            'temporada_id' => ['nullable', 'uuid'],
            'cliente_id' => ['nullable', 'uuid'],
        ]);
        $activa = $temporadaActiva->buscar();
        $temporadaId = $datos['temporada_id'] ?? $activa?->id;
        $respuesta = [
            'temporadas' => Temporada::query()->orderByDesc('activa')->orderByDesc('codigo')->get(['id', 'codigo', 'nombre', 'activa'])
                ->map(fn (Temporada $temporada): array => ['id' => $temporada->id, 'codigo' => $temporada->codigo, 'nombre' => $temporada->nombre, 'activa' => (bool) $temporada->activa])->all(),
            'temporada_id' => $temporadaId,
            'clientes' => Cliente::query()->where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre'])
                ->map(fn (Cliente $cliente): array => ['id' => $cliente->id, 'codigo' => $cliente->codigo, 'nombre' => $cliente->nombre])->all(),
            'redondeos' => array_map(fn (RedondeoEstandarMaterial $redondeo): string => $redondeo->value, RedondeoEstandarMaterial::cases()),
            'variaciones' => array_map(fn (VariacionEstandarMaterial $tipo): array => ['clave' => $tipo->value, 'nombre' => $tipo->etiqueta()], VariacionEstandarMaterial::cases()),
            'envases' => [],
            'valores' => ['marca' => [], 'variedad' => [], 'calibre' => [], 'categoria' => []],
        ];
        if (! $temporadaId || empty($datos['cliente_id'])) {
            return response()->json($respuesta);
        }

        $clientesValidacion = ClienteValidacion::query()
            ->where('temporada_id', $temporadaId)
            ->where('cliente_id', $datos['cliente_id'])
            ->pluck('id');
        $envases = EnvaseValidacion::query()
            ->with('especie:id,nombre')
            ->whereHas('especie', fn (Builder $consulta) => $consulta->where('temporada_id', $temporadaId))
            ->where(fn (Builder $consulta) => $consulta->whereNull('cliente_validacion_id')->orWhereIn('cliente_validacion_id', $clientesValidacion))
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();
        $especies = $envases->pluck('especie_validacion_id')->unique()->values();
        $nombres = fn ($consulta): array => $consulta->where('activo', true)->orderBy('nombre')->pluck('nombre')
            ->map(fn (string $nombre): ?string => ServicioEstandarMaterial::normalizarVariacion($nombre))->filter()->unique()->values()->all();

        $respuesta['envases'] = $envases->map(fn (EnvaseValidacion $envase): array => [
            'id' => $envase->id,
            'nombre' => $envase->nombre,
            'codigo_externo' => $envase->codigo_externo,
            'especie' => $envase->especie?->nombre,
            'generico' => $envase->cliente_validacion_id === null,
        ])->all();
        $respuesta['valores'] = [
            'marca' => $nombres(MarcaValidacion::query()->whereIn('cliente_validacion_id', $clientesValidacion)),
            'variedad' => $nombres(VariedadValidacion::query()->whereIn('especie_validacion_id', $especies)),
            'calibre' => $nombres(CalibreValidacion::query()->whereIn('especie_validacion_id', $especies)),
            'categoria' => $nombres(CategoriaValidacion::query()->where('temporada_id', $temporadaId)),
        ];

        return response()->json($respuesta);
    }

    /**
     * Ítems activos de Materiales de la temporada para un insumo. Pueden ser de otro dueño que
     * el cliente del PT (por ejemplo, etiquetas de la planta); los del cliente van primero.
     */
    public function items(Request $request, ServicioTemporadaActiva $temporadaActiva): JsonResponse
    {
        $datos = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'cliente_id' => ['nullable', 'uuid'],
        ]);
        $temporada = $temporadaActiva->obtener();
        $texto = '%'.addcslashes(trim($datos['q']), '%_\\').'%';
        $items = ItemMaterial::query()
            ->with('cliente.cliente:id,codigo,nombre')
            ->where('activo', true)
            ->whereHas('cliente', fn (Builder $consulta) => $consulta->where('activo', true)
                ->whereHas('temporada', fn (Builder $temporadas) => $temporadas->where('temporada_id', $temporada->id)))
            ->where(fn (Builder $consulta) => $consulta->where('codigo', 'like', $texto)
                ->orWhere('nombre', 'like', $texto)
                ->orWhere('codigo_externo', 'like', $texto))
            ->orderBy('codigo')
            ->limit(60)
            ->get()
            ->sortBy(fn (ItemMaterial $item): string => ($item->cliente?->cliente_id === ($datos['cliente_id'] ?? null) ? '0' : '1').$item->codigo)
            ->take(30)
            ->values();

        return response()->json(['data' => $items->map(fn (ItemMaterial $item): array => [
            'id' => $item->id,
            'codigo' => $item->codigo,
            'nombre' => $item->nombre,
            'codigo_externo' => $item->codigo_externo,
            'unidad_medida' => $item->unidad_medida,
            'categoria_operacional' => $item->categoria_operacional?->value,
            'dueno' => $item->cliente?->cliente?->nombre ?? $item->cliente?->nombre,
            'del_cliente' => $item->cliente?->cliente_id === ($datos['cliente_id'] ?? null),
        ])->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'cliente_id' => ['required', 'uuid'],
            'envase_validacion_id' => ['required', 'uuid'],
            'codigo' => ['nullable', 'string', 'max:60'],
            'descripcion' => ['nullable', 'string', 'max:200'],
            ...$this->reglasVersion(),
        ]);

        return response()->json(['data' => $this->servicio->serializar($this->servicio->crear($datos, $request->user()))], Response::HTTP_CREATED);
    }

    public function versionar(Request $request, EstandarMaterial $estandarMaterial): JsonResponse
    {
        $datos = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'codigo' => ['nullable', 'string', 'max:60'],
            'descripcion' => ['nullable', 'string', 'max:200'],
            ...$this->reglasVersion(),
        ]);

        return response()->json(['data' => $this->servicio->serializar($this->servicio->versionar($estandarMaterial, $datos, $request->user()))], Response::HTTP_CREATED);
    }

    public function copiar(Request $request, EstandarMaterial $estandarMaterial): JsonResponse
    {
        $datos = $request->validate([
            'vigente_desde' => ['nullable', 'date_format:Y-m-d'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(['data' => $this->servicio->serializar($this->servicio->copiarComoPropio($estandarMaterial, $datos, $request->user()))], Response::HTTP_CREATED);
    }

    public function estado(Request $request, EstandarMaterial $estandarMaterial): JsonResponse
    {
        $datos = $request->validate([
            'activo' => ['required', 'boolean'],
            'version' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json(['data' => $this->servicio->serializar(
            $this->servicio->cambiarEstado($estandarMaterial, (bool) $datos['activo'], $datos['version'], $request->user()),
        )]);
    }

    /** Una fila por insumo de la versión vigente de cada estándar filtrado. */
    public function excel(Request $request, ServicioTemporadaActiva $temporadaActiva, GeneradorLibroXlsx $excel): BinaryFileResponse
    {
        $filtros = $this->filtros($request, $temporadaActiva);
        $temporada = Temporada::query()->findOrFail($filtros['temporada_id']);
        $filas = (function () use ($filtros) {
            foreach ($this->consulta($filtros)->with($this->relacionesListado())->orderBy('codigo')->orderBy('origen')->lazy(100) as $estandar) {
                $datos = $this->servicio->serializar($estandar);
                $vigente = collect($datos['versiones'])->firstWhere('vigente', true) ?? ($datos['versiones'][0] ?? null);
                foreach ($vigente['lineas'] ?? [] as $linea) {
                    yield [
                        'cliente' => $datos['cliente']['nombre'] ?? '',
                        'codigo' => $datos['codigo'],
                        'descripcion' => $datos['descripcion'],
                        'embalaje' => $datos['envase']['nombre'] ?? '',
                        'codigo_articulo' => $datos['codigo_articulo'],
                        'especie' => $datos['envase']['especie'] ?? $datos['especie'],
                        'origen' => $datos['origen_nombre'],
                        'estado' => $datos['activo'] ? 'Activo' : 'Inactivo',
                        'version' => $vigente['numero'],
                        'vigente_desde' => $vigente['vigente_desde'],
                        'codigo_insumo' => $linea['codigo_insumo'],
                        'insumo' => $linea['descripcion_insumo'],
                        'dueno' => $linea['dueno'] ?? ($linea['item_material_id'] ? '' : 'Sin homologar'),
                        'unidad' => $linea['unidad_medida'],
                        'cantidad_por_caja' => $linea['cantidad_por_caja'],
                        'redondeo' => $linea['redondeo'] === 'entero' ? 'Entero' : 'Decimal',
                        'variacion' => $linea['variacion_nombre'] ? $linea['variacion_nombre'].': '.$linea['variacion_valor'] : '',
                    ];
                }
            }
        })();
        $ruta = $excel->generar('Estándares de consumo', [
            ['clave' => 'cliente', 'titulo' => 'Cliente'],
            ['clave' => 'codigo', 'titulo' => 'Código estándar'],
            ['clave' => 'descripcion', 'titulo' => 'Descripción'],
            ['clave' => 'embalaje', 'titulo' => 'Embalaje PT'],
            ['clave' => 'codigo_articulo', 'titulo' => 'Código artículo'],
            ['clave' => 'especie', 'titulo' => 'Especie'],
            ['clave' => 'origen', 'titulo' => 'Origen'],
            ['clave' => 'estado', 'titulo' => 'Estado'],
            ['clave' => 'version', 'titulo' => 'Versión', 'tipo' => 'cantidad'],
            ['clave' => 'vigente_desde', 'titulo' => 'Vigente desde', 'tipo' => 'fecha_dma'],
            ['clave' => 'codigo_insumo', 'titulo' => 'Código insumo'],
            ['clave' => 'insumo', 'titulo' => 'Insumo'],
            ['clave' => 'dueno', 'titulo' => 'Dueño del ítem'],
            ['clave' => 'unidad', 'titulo' => 'Unidad'],
            ['clave' => 'cantidad_por_caja', 'titulo' => 'Cantidad por caja', 'tipo' => 'decimal'],
            ['clave' => 'redondeo', 'titulo' => 'Rebaja'],
            ['clave' => 'variacion', 'titulo' => 'Variación'],
        ], $filas, [
            'fecha_corte' => now(config('app.operational_timezone'))->format('d-m-Y H:i'),
            'usuario' => $request->user()->name,
            'temporada' => $temporada->codigo.' · '.$temporada->nombre,
            'adicionales' => [
                ['Versión', 'La que rige hoy; si todas empiezan después, la más reciente.'],
                ['Rebaja', 'Entero descuenta unidades completas y arrastra la fracción; Decimal descuenta la cantidad exacta.'],
            ],
        ], 'Estándares');

        return response()->download($ruta, 'estandares-consumo-'.$temporada->codigo.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, private',
        ])->deleteFileAfterSend();
    }

    /** @return array<string, mixed> */
    private function reglasVersion(): array
    {
        return [
            'vigente_desde' => ['nullable', 'date_format:Y-m-d'],
            'motivo' => ['nullable', 'string', 'max:500'],
            'lineas' => ['required', 'array', 'min:1', 'max:'.ServicioEstandarMaterial::MAX_LINEAS],
            'lineas.*.item_material_id' => ['required', 'uuid'],
            'lineas.*.cantidad_por_caja' => ['required', 'numeric', 'gt:0'],
            'lineas.*.redondeo' => ['required', Rule::enum(RedondeoEstandarMaterial::class)],
            'lineas.*.variacion_tipo' => ['nullable', Rule::enum(VariacionEstandarMaterial::class)],
            'lineas.*.variacion_valor' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** @return array<string, mixed> */
    private function filtros(Request $request, ServicioTemporadaActiva $temporadaActiva): array
    {
        $datos = $request->validate([
            'temporada_id' => ['nullable', 'uuid'],
            'cliente_id' => ['nullable', 'uuid'],
            'origen' => ['nullable', Rule::enum(OrigenEstandarMaterial::class)],
            'estado' => ['nullable', Rule::in(['activos', 'inactivos', 'todos'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $datos['temporada_id'] ??= $temporadaActiva->obtener()->id;

        return $datos;
    }

    /** @param array<string, mixed> $filtros */
    private function consulta(array $filtros): Builder
    {
        $texto = filled($filtros['q'] ?? null) ? '%'.addcslashes(trim($filtros['q']), '%_\\').'%' : null;

        return EstandarMaterial::query()
            ->where('temporada_id', $filtros['temporada_id'])
            ->when($filtros['cliente_id'] ?? null, fn (Builder $consulta, string $cliente) => $consulta->where('cliente_id', $cliente))
            ->when($filtros['origen'] ?? null, fn (Builder $consulta, string $origen) => $consulta->where('origen', $origen))
            ->when(($filtros['estado'] ?? 'activos') !== 'todos', fn (Builder $consulta) => $consulta->where('activo', ($filtros['estado'] ?? 'activos') === 'activos'))
            ->when($texto, fn (Builder $consulta) => $consulta->where(fn (Builder $busqueda) => $busqueda
                ->where('codigo', 'like', $texto)
                ->orWhere('descripcion', 'like', $texto)
                ->orWhere('codigo_articulo', 'like', $texto)
                ->orWhere('descripcion_articulo', 'like', $texto)));
    }

    /** @return array<int|string, mixed> */
    private function relacionesListado(): array
    {
        return [
            'temporada:id,codigo,nombre,activa',
            'cliente:id,codigo,nombre',
            'envase.especie:id,nombre',
            'base:id,origen,codigo',
            'versiones.lineas.item.cliente.cliente:id,codigo,nombre',
            'versiones.creadoPor:id,name',
            'actualizadoPor:id,name',
        ];
    }
}

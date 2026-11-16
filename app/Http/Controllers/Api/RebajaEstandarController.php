<?php

namespace App\Http\Controllers\Api;

use App\Enums\TipoAlmacenMaterial;
use App\Exceptions\OperacionNoAutorizada;
use App\Http\Controllers\Controller;
use App\Models\AlmacenMaterial;
use App\Models\Cliente;
use App\Models\ConsumoRebajaEstandar;
use App\Models\EnvaseValidacion;
use App\Models\MovimientoAlmacenMaterial;
use App\Models\ProduccionRebajaEstandar;
use App\Models\RebajaEstandarMaterial;
use App\Models\SaldoRebajaEstandar;
use App\Models\VersionEstandarMaterial;
use App\Services\Existencias\GeneradorLibroXlsx;
use App\Services\Materiales\ServicioRebajaEstandarMaterial;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Rebaja por estándar desde Validación PT (Materiales → Rebaja por estándar). */
class RebajaEstandarController extends Controller
{
    public function __construct(private readonly ServicioRebajaEstandarMaterial $rebajas) {}

    public function opciones(Request $request): JsonResponse
    {
        return response()->json([
            'almacenes' => AlmacenMaterial::query()->where('activo', true)->where('tipo', TipoAlmacenMaterial::Virtual->value)
                ->orderBy('nombre')->get(['id', 'codigo', 'nombre', 'centro_costo'])
                ->map->only(['id', 'codigo', 'nombre', 'centro_costo'])->all(),
            'clientes' => Cliente::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre'])->map->only(['id', 'nombre'])->all(),
            'puede_rebajar' => Gate::allows('rebajar-estandares-materiales') && Gate::allows('gestionar-despachos-materiales'),
            'hoy' => now(config('app.operational_timezone'))->toDateString(),
        ]);
    }

    public function previsualizar(Request $request): JsonResponse
    {
        $datos = $this->validar($request, conGrupos: false);

        return response()->json($this->rebajas->previsualizar($datos));
    }

    public function store(Request $request): JsonResponse
    {
        if (! Gate::allows('gestionar-despachos-materiales')) {
            throw new OperacionNoAutorizada('Para rebajar debes poder mover existencias entre almacenes (despachos de Materiales).');
        }
        $datos = $this->validar($request, conGrupos: true);
        $rebaja = $this->rebajas->confirmar($datos, $request->user());

        return response()->json(['data' => $this->detalle($rebaja)], $rebaja->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate(['almacen_id' => ['nullable', 'uuid'], 'page' => ['nullable', 'integer', 'min:1']]);
        $pagina = RebajaEstandarMaterial::query()
            ->with(['almacen:id,codigo,nombre', 'usuario:id,name'])
            ->when($datos['almacen_id'] ?? null, fn ($consulta, string $almacen) => $consulta->where('almacen_material_id', $almacen))
            ->orderByDesc('confirmada_at')
            ->paginate(25);

        return response()->json([
            'data' => collect($pagina->items())->map(fn (RebajaEstandarMaterial $rebaja): array => $this->cabecera($rebaja))->all(),
            'meta' => ['page' => $pagina->currentPage(), 'last_page' => $pagina->lastPage(), 'total' => $pagina->total()],
        ]);
    }

    public function show(RebajaEstandarMaterial $rebajaEstandarMaterial): JsonResponse
    {
        return response()->json(['data' => $this->detalle($rebajaEstandarMaterial)]);
    }

    /** Excel de rebajas confirmadas: una fila por ítem descontado o, con detalle=produccion, por pallet rebajado. */
    public function excel(Request $request, GeneradorLibroXlsx $excel): BinaryFileResponse
    {
        $datos = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'almacen_id' => ['nullable', 'uuid'],
            'detalle' => ['nullable', Rule::in(['consumos', 'produccion'])],
        ]);
        $zona = (string) config('app.operational_timezone');
        $rebajas = RebajaEstandarMaterial::query()
            ->with(['almacen:id,codigo,nombre', 'usuario:id,name'])
            ->whereBetween('confirmada_at', [
                CarbonImmutable::parse($datos['desde'], $zona)->startOfDay()->utc(),
                CarbonImmutable::parse($datos['hasta'], $zona)->endOfDay()->utc(),
            ])
            ->when($datos['almacen_id'] ?? null, fn ($consulta, string $almacen) => $consulta->where('almacen_material_id', $almacen))
            ->orderBy('confirmada_at')
            ->get()->keyBy('id');
        $produccion = ($datos['detalle'] ?? 'consumos') === 'produccion';
        $base = fn (RebajaEstandarMaterial $rebaja): array => [
            'codigo' => $rebaja->codigo,
            'confirmada' => $rebaja->confirmada_at?->setTimezone($zona)->format('Y-m-d H:i:s'),
            'almacen' => $rebaja->almacen?->nombre,
            'usuario' => $rebaja->usuario?->name,
        ];
        if ($produccion) {
            $filas = ProduccionRebajaEstandar::query()->whereIn('rebaja_estandar_material_id', $rebajas->keys())->orderBy('fecha_proceso')->orderBy('numero_folio')->get();
            $clientes = Cliente::query()->whereIn('id', $filas->pluck('cliente_id')->unique())->pluck('nombre', 'id');
            $envases = EnvaseValidacion::query()->whereIn('id', $filas->pluck('envase_validacion_id')->unique())->pluck('nombre', 'id');
            $versiones = VersionEstandarMaterial::query()->with('estandar:id,codigo,origen')->whereIn('id', $filas->pluck('version_estandar_material_id')->unique())->get()->keyBy('id');
            $columnas = [
                ['clave' => 'codigo', 'titulo' => 'Rebaja'], ['clave' => 'confirmada', 'titulo' => 'Confirmada', 'tipo' => 'fecha_hora'],
                ['clave' => 'almacen', 'titulo' => 'Almacén'], ['clave' => 'fecha', 'titulo' => 'Día de proceso', 'tipo' => 'fecha_dma'],
                ['clave' => 'cliente', 'titulo' => 'Cliente'], ['clave' => 'marca', 'titulo' => 'Marca'], ['clave' => 'proceso', 'titulo' => 'Proceso'],
                ['clave' => 'embalaje', 'titulo' => 'Embalaje'], ['clave' => 'variedad', 'titulo' => 'Variedad'], ['clave' => 'calibre', 'titulo' => 'Calibre'],
                ['clave' => 'categoria', 'titulo' => 'Categoría'], ['clave' => 'folio', 'titulo' => 'Folio'], ['clave' => 'cajas', 'titulo' => 'Cajas', 'tipo' => 'cantidad'],
                ['clave' => 'estandar', 'titulo' => 'Estándar'], ['clave' => 'usuario', 'titulo' => 'Usuario'],
            ];
            $registros = $filas->map(fn (ProduccionRebajaEstandar $fila): array => [
                ...$base($rebajas->get($fila->rebaja_estandar_material_id)),
                'fecha' => $fila->fecha_proceso->toDateString(), 'cliente' => $clientes->get($fila->cliente_id), 'marca' => $fila->marca,
                'proceso' => $fila->proceso, 'embalaje' => $envases->get($fila->envase_validacion_id), 'variedad' => $fila->variedad,
                'calibre' => $fila->calibre, 'categoria' => $fila->categoria, 'folio' => $fila->numero_folio, 'cajas' => $fila->cajas,
                'estandar' => ($version = $versiones->get($fila->version_estandar_material_id))
                    ? $version->estandar?->codigo.' v'.$version->numero.' ('.$version->estandar?->origen->etiqueta().')' : null,
            ]);
        } else {
            $columnas = [
                ['clave' => 'codigo', 'titulo' => 'Rebaja'], ['clave' => 'confirmada', 'titulo' => 'Confirmada', 'tipo' => 'fecha_hora'],
                ['clave' => 'almacen', 'titulo' => 'Almacén'], ['clave' => 'item_codigo', 'titulo' => 'Código ítem'], ['clave' => 'item', 'titulo' => 'Ítem'],
                ['clave' => 'unidad', 'titulo' => 'Unidad'], ['clave' => 'redondeo', 'titulo' => 'Rebaja'], ['clave' => 'teorico', 'titulo' => 'Teórico', 'tipo' => 'decimal'],
                ['clave' => 'fraccion_anterior', 'titulo' => 'Fracción anterior', 'tipo' => 'decimal'], ['clave' => 'pendiente_anterior', 'titulo' => 'Pendiente anterior', 'tipo' => 'decimal'],
                ['clave' => 'a_descontar', 'titulo' => 'A descontar', 'tipo' => 'decimal'], ['clave' => 'descontado', 'titulo' => 'Descontado', 'tipo' => 'decimal'],
                ['clave' => 'pendiente', 'titulo' => 'Pendiente (negativo: a favor)', 'tipo' => 'decimal'], ['clave' => 'fraccion_resultante', 'titulo' => 'Fracción que sigue', 'tipo' => 'decimal'],
                ['clave' => 'usuario', 'titulo' => 'Usuario'],
            ];
            $registros = ConsumoRebajaEstandar::query()->with('item:id,codigo,nombre')->whereIn('rebaja_estandar_material_id', $rebajas->keys())->get()
                ->sortBy(fn (ConsumoRebajaEstandar $consumo): string => $rebajas->get($consumo->rebaja_estandar_material_id)->codigo.'|'.$consumo->item?->codigo)
                ->map(fn (ConsumoRebajaEstandar $consumo): array => [
                    ...$base($rebajas->get($consumo->rebaja_estandar_material_id)),
                    'item_codigo' => $consumo->item?->codigo, 'item' => $consumo->item?->nombre, 'unidad' => $consumo->unidad_medida,
                    'redondeo' => $consumo->redondeo === 'entero' ? 'Entero' : 'Decimal',
                    ...collect(['teorico', 'fraccion_anterior', 'pendiente_anterior', 'a_descontar', 'descontado', 'pendiente', 'fraccion_resultante'])
                        ->mapWithKeys(fn (string $campo): array => [$campo => (float) $consumo->{$campo}])->all(),
                ]);
        }
        $ruta = $excel->generar($produccion ? 'Producción rebajada por estándar' : 'Rebajas por estándar', $columnas, $registros->values()->all(), [
            'fecha_corte' => now($zona)->format('d-m-Y H:i'),
            'usuario' => $request->user()->name,
            'periodo' => CarbonImmutable::parse($datos['desde'])->format('d-m-Y').' al '.CarbonImmutable::parse($datos['hasta'])->format('d-m-Y').' (fecha de confirmación)',
        ], $produccion ? 'Produccion' : 'Rebajas');

        return response()->download($ruta, ($produccion ? 'produccion-rebajada-' : 'rebajas-estandar-').$datos['desde'].'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, private',
        ])->deleteFileAfterSend();
    }

    /** Fracciones arrastradas y faltantes por descontar de un almacén. */
    public function saldos(Request $request): JsonResponse
    {
        $datos = $request->validate(['almacen_id' => ['required', 'uuid']]);

        return response()->json(['data' => SaldoRebajaEstandar::query()
            ->with('item:id,codigo,nombre,unidad_medida')
            ->where('almacen_material_id', $datos['almacen_id'])
            ->where(fn ($consulta) => $consulta->where('pendiente', '>', 0)->orWhere('fraccion', '>', 0))
            ->get()
            ->sortBy('item.codigo')
            ->map(fn (SaldoRebajaEstandar $saldo): array => [
                'item' => $saldo->item?->only(['id', 'codigo', 'nombre', 'unidad_medida']),
                'fraccion' => (float) $saldo->fraccion,
                'pendiente' => (float) $saldo->pendiente,
            ])->values()->all()]);
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, bool $conGrupos): array
    {
        $datos = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d'],
            'almacen_id' => ['required', 'uuid'],
            'cliente_id' => ['nullable', 'uuid'],
            'marca' => ['nullable', 'string', 'max:150'],
            'proceso' => ['nullable', 'string', 'max:100'],
            'observacion' => ['nullable', 'string', 'max:500'],
            ...($conGrupos ? [
                'operacion_id' => ['required', 'uuid'],
                'grupos' => ['present', 'array', 'max:2000'],
                'grupos.*.clave' => ['required', 'string', 'max:40'],
                // Un ajuste por corrección puede ser negativo.
                'grupos.*.cajas' => ['required', 'integer', 'not_in:0'],
            ] : [
                'grupos' => ['nullable', 'array', 'max:2000'],
                'grupos.*' => ['string', 'max:40'],
            ]),
        ]);
        ServicioRebajaEstandarMaterial::validarPeriodo($datos['desde'], $datos['hasta']);

        return $datos;
    }

    /** @return array<string, mixed> */
    private function cabecera(RebajaEstandarMaterial $rebaja): array
    {
        return [
            'id' => $rebaja->id,
            'codigo' => $rebaja->codigo,
            'estado' => $rebaja->estado,
            'almacen' => $rebaja->almacen?->only(['id', 'codigo', 'nombre']),
            'desde' => $rebaja->desde?->toDateString(),
            'hasta' => $rebaja->hasta?->toDateString(),
            'filtros' => $rebaja->filtros,
            'cajas' => $rebaja->cajas,
            'pallets' => $rebaja->pallets,
            'resumen' => $rebaja->resumen,
            'observacion' => $rebaja->observacion,
            'usuario' => $rebaja->usuario?->name,
            'confirmada_at' => $rebaja->confirmada_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function detalle(RebajaEstandarMaterial $rebaja): array
    {
        $rebaja->loadMissing(['almacen:id,codigo,nombre', 'usuario:id,name']);
        $producciones = ProduccionRebajaEstandar::query()->where('rebaja_estandar_material_id', $rebaja->id)->get();
        $clientes = Cliente::query()->whereIn('id', $producciones->pluck('cliente_id')->unique())->pluck('nombre', 'id');
        $envases = EnvaseValidacion::query()->whereIn('id', $producciones->pluck('envase_validacion_id')->unique())->pluck('nombre', 'id');

        return [
            ...$this->cabecera($rebaja),
            'produccion' => $producciones
                ->groupBy(fn (ProduccionRebajaEstandar $fila): string => implode('|', [$fila->fecha_proceso->toDateString(), $fila->cliente_id, $fila->marca, $fila->proceso, $fila->envase_validacion_id]))
                ->map(fn ($filas): array => [
                    'fecha' => $filas->first()->fecha_proceso->toDateString(),
                    'cliente' => $clientes->get($filas->first()->cliente_id),
                    'marca' => $filas->first()->marca,
                    'proceso' => $filas->first()->proceso,
                    'embalaje' => $envases->get($filas->first()->envase_validacion_id),
                    'cajas' => $filas->sum('cajas'),
                    'pallets' => $filas->pluck('validacion_pallet_id')->unique()->count(),
                ])->sortBy(fn (array $fila): string => $fila['fecha'].$fila['cliente'].$fila['marca'].$fila['proceso'])->values()->all(),
            'consumos' => ConsumoRebajaEstandar::query()->with('item:id,codigo,nombre')->where('rebaja_estandar_material_id', $rebaja->id)->get()
                ->sortBy('item.codigo')->map(fn (ConsumoRebajaEstandar $consumo): array => [
                    'item' => $consumo->item?->only(['id', 'codigo', 'nombre']),
                    'unidad_medida' => $consumo->unidad_medida,
                    'redondeo' => $consumo->redondeo,
                    'teorico' => (float) $consumo->teorico,
                    'fraccion_anterior' => (float) $consumo->fraccion_anterior,
                    'pendiente_anterior' => (float) $consumo->pendiente_anterior,
                    'a_descontar' => (float) $consumo->a_descontar,
                    'descontado' => (float) $consumo->descontado,
                    'pendiente' => (float) $consumo->pendiente,
                    'fraccion_resultante' => (float) $consumo->fraccion_resultante,
                    'folios' => $consumo->folios,
                ])->values()->all(),
            'movimientos' => MovimientoAlmacenMaterial::query()->where('documento_relacionado', $rebaja->codigo)->count(),
        ];
    }
}

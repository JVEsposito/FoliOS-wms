<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoRecepcionMaterial;
use App\Http\Controllers\Controller;
use App\Http\Requests\ActualizarRecepcionMaterialRequest;
use App\Http\Requests\AdministrarRecepcionMaterialRequest;
use App\Http\Requests\AnularRecepcionMaterialRequest;
use App\Http\Requests\ConfirmarRecepcionMaterialRequest;
use App\Http\Requests\CrearRecepcionMaterialRequest;
use App\Http\Requests\EliminarRecepcionMaterialRequest;
use App\Http\Resources\RecepcionMaterialResource;
use App\Models\ClienteMaterial;
use App\Models\FolioMaterial;
use App\Models\ItemMaterial;
use App\Models\ProveedorMaterial;
use App\Models\RecepcionMaterial;
use App\Models\TemporadaMaterial;
use App\Services\Materiales\MuestreoXlsx;
use App\Services\Materiales\ServicioRecepcionMaterial;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class RecepcionMaterialController extends Controller
{
    public function catalogos(): JsonResponse
    {
        Gate::authorize('consultar-recepciones-materiales');
        $temporada = TemporadaMaterial::query()
            ->with('temporadaGlobal')
            ->where('activa', true)
            ->whereHas('temporadaGlobal', fn ($consulta) => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->first();

        if (! $temporada || ! $temporada->temporadaGlobal) {
            return response()->json([
                'temporada' => null,
                'clientes' => [],
                'proveedores' => [],
                'items' => [],
            ]);
        }

        $clientesMateriales = ClienteMaterial::query()
            ->with('cliente')
            ->where('temporada_material_id', $temporada->id)
            ->where('activo', true)
            ->whereHas('cliente', fn ($consulta) => $consulta->where('activo', true))
            ->orderBy('nombre')
            ->get();
        $clienteMaterialIds = $clientesMateriales->pluck('id');
        $clienteIds = $clientesMateriales->pluck('cliente_id')->unique()->values();
        $clienteIdsPorCatalogo = $clientesMateriales->pluck('cliente_id', 'id');

        $items = ItemMaterial::query()->with('fotoPrincipal')
            ->whereIn('cliente_material_id', $clienteMaterialIds)
            ->where('activo', true)
            ->whereNotNull('categoria')
            ->whereNotNull('categoria_operacional')
            ->orderBy('codigo')
            ->get();
        $categoriasActivasPorCliente = $items
            ->groupBy(fn (ItemMaterial $item): string => (string) $clienteIdsPorCatalogo
                ->get($item->cliente_material_id))
            ->map(fn ($items) => $items->mapWithKeys(function (ItemMaterial $item): array {
                $categoria = trim((string) $item->categoria);

                return $categoria === '' ? [] : [mb_strtolower($categoria) => $categoria];
            }));
        $proveedores = ProveedorMaterial::query()
            ->with(['clientes' => fn ($consulta) => $consulta
                ->whereIn('clientes.id', $clienteIds)
                ->wherePivot('activo', true)
                ->orderBy('clientes.codigo')])
            ->where('activo', true)
            ->whereHas('clientes', fn ($consulta) => $consulta
                ->whereIn('clientes.id', $clienteIds)
                ->where('clientes_proveedores_materiales.activo', true))
            ->orderBy('codigo')
            ->get();

        return response()->json([
            'temporada' => [
                'id' => $temporada->temporadaGlobal->id,
                'catalogo_material_id' => $temporada->id,
                'codigo' => $temporada->temporadaGlobal->codigo,
                'nombre' => $temporada->temporadaGlobal->nombre,
            ],
            'clientes' => $clientesMateriales->map(fn (ClienteMaterial $catalogo): array => [
                'id' => $catalogo->cliente->id,
                'cliente_material_id' => $catalogo->id,
                'codigo' => $catalogo->cliente->codigo,
                'codigo_folio_materiales' => $catalogo->cliente->codigo_folio_materiales,
                'nombre' => $catalogo->cliente->nombre,
            ])->values(),
            'proveedores' => $proveedores->map(function (ProveedorMaterial $proveedor) use ($categoriasActivasPorCliente): array {
                $categorias = $proveedor->clientes->flatMap(function ($cliente) use ($categoriasActivasPorCliente) {
                    $valor = $cliente->pivot?->categorias;
                    if (is_string($valor)) {
                        $valor = json_decode($valor, true);
                    }
                    $categoriasActivas = $categoriasActivasPorCliente
                        ->get((string) $cliente->id, collect());

                    return collect(is_array($valor) ? $valor : [])
                        ->map(function ($categoria) use ($cliente, $categoriasActivas): ?array {
                            $categoriaActiva = $categoriasActivas
                                ->get(mb_strtolower(trim((string) $categoria)));

                            return $categoriaActiva ? [
                                'cliente_id' => $cliente->id,
                                'categoria' => $categoriaActiva,
                            ] : null;
                        })
                        ->filter();
                })->unique(fn (array $asignacion): string => $asignacion['cliente_id'].'|'
                    .mb_strtolower($asignacion['categoria']))
                    ->values();

                return [
                    'id' => $proveedor->id,
                    'codigo' => $proveedor->codigo,
                    'nombre' => $proveedor->nombre,
                    'cliente_ids' => $categorias->pluck('cliente_id')->unique()->values(),
                    'categorias' => $categorias,
                ];
            })->filter(fn (array $proveedor): bool => count($proveedor['cliente_ids']) > 0)->values(),
            'items' => $items->map(fn (ItemMaterial $item): array => [
                'id' => $item->id,
                'cliente_id' => $clienteIdsPorCatalogo->get($item->cliente_material_id),
                'cliente_material_id' => $item->cliente_material_id,
                'foto_principal' => $item->fotoPrincipal?->representar(),
                'codigo' => $item->codigo,
                'nombre' => $item->nombre,
                'categoria' => $item->categoria,
                'categoria_operacional' => $item->categoria_operacional->value,
                'categoria_operacional_etiqueta' => $item->categoria_operacional->etiqueta(),
                'unidad_medida' => $item->unidad_medida,
            ])->values(),
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('consultar-recepciones-materiales');
        $filtros = $request->validate([
            'temporada_id' => ['nullable', 'uuid', 'exists:temporadas,id'],
            'sin_foto_documento' => ['nullable', 'boolean'],
        ]);
        $temporadaId = $filtros['temporada_id'] ?? app(ServicioTemporadaActiva::class)->buscar()?->id;
        $usuario = $request->user();
        $consulta = RecepcionMaterial::query()
            ->when($temporadaId, fn ($query) => $query->where('temporada_id', $temporadaId))
            ->when(! $temporadaId, fn ($query) => $query->whereRaw('1 = 0'))
            ->with(['temporada', 'cliente', 'proveedor', 'creadoPor', 'confirmadoPor', 'anuladoPor'])
            ->withCount(['detalles', 'fotos as fotos_documento' => fn ($q) => $q->where('tipo', 'documento'), 'fotos as fotos_referenciales' => fn ($q) => $q->where('tipo', 'referencial')])
            ->when($request->boolean('sin_foto_documento'), fn ($q) => $q->whereDoesntHave('fotos', fn ($f) => $f->where('tipo', 'documento')))
            ->when($request->query('estado'), fn ($query, $estado) => $query->where('estado', $estado))
            ->when($request->query('cliente_id'), fn ($query, $cliente) => $query->where('cliente_id', $cliente))
            ->when($request->query('proveedor_material_id'), fn ($query, $proveedor) => $query
                ->where('proveedor_material_id', $proveedor))
            ->when($request->query('guia'), fn ($query, $guia) => $query
                ->where('numero_guia_despacho', 'like', '%'.trim((string) $guia).'%'));

        if (! $usuario->can('anular-recepciones-materiales')) {
            if ($usuario->can('gestionar-recepciones-materiales')) {
                $consulta->where(function ($query) use ($usuario): void {
                    $query->where('estado', EstadoRecepcionMaterial::Confirmada->value)
                        ->orWhere('creado_por_user_id', $usuario->id);
                });
            } else {
                $consulta->where('estado', EstadoRecepcionMaterial::Confirmada->value);
            }
        }

        return RecepcionMaterialResource::collection(
            $consulta->latest()->paginate(min(100, max(10, (int) $request->query('per_page', 25)))),
        );
    }

    public function show(
        Request $request,
        RecepcionMaterial $recepcionMaterial,
    ): RecepcionMaterialResource {
        Gate::authorize('consultar-recepciones-materiales');
        abort_unless(
            $this->puedeVerRecepcion($request, $recepcionMaterial),
            Response::HTTP_NOT_FOUND,
        );

        return new RecepcionMaterialResource(
            app(ServicioRecepcionMaterial::class)->cargar($recepcionMaterial),
        );
    }

    public function registroMuestreo(
        Request $request,
        RecepcionMaterial $recepcionMaterial,
        MuestreoXlsx $generador,
    ): BinaryFileResponse {
        Gate::authorize('consultar-recepciones-materiales');
        abort_unless(
            $this->puedeVerRecepcion($request, $recepcionMaterial),
            Response::HTTP_NOT_FOUND,
        );
        abort_unless(
            $recepcionMaterial->estado === EstadoRecepcionMaterial::Confirmada,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'El registro de muestreo solo está disponible para recepciones confirmadas.',
        );

        $ruta = $generador->generar($recepcionMaterial);
        $guia = Str::upper(Str::slug($recepcionMaterial->numero_guia_despacho, '_'));
        $archivo = 'Registro_Muestreo_'.($guia !== '' ? $guia : $recepcionMaterial->id).'.xlsx';

        return response()->download(
            $ruta,
            $archivo,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend();
    }

    public function registroMuestreoEnBlanco(MuestreoXlsx $generador): BinaryFileResponse
    {
        Gate::authorize('consultar-recepciones-materiales');

        return response()->download(
            $generador->generarEnBlanco(),
            'Registro_Muestreo_EN_BLANCO.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend();
    }

    public function store(
        CrearRecepcionMaterialRequest $request,
        ServicioRecepcionMaterial $servicio,
    ): JsonResponse {
        return (new RecepcionMaterialResource(
            $servicio->crear($request->validated(), $request->user()),
        ))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        ActualizarRecepcionMaterialRequest $request,
        RecepcionMaterial $recepcionMaterial,
        ServicioRecepcionMaterial $servicio,
    ): RecepcionMaterialResource {
        abort_unless(
            $this->puedeOperarRecepcion($request, $recepcionMaterial),
            Response::HTTP_NOT_FOUND,
        );

        return new RecepcionMaterialResource($servicio->actualizar(
            $recepcionMaterial,
            $request->validated(),
            $request->user(),
        ));
    }

    public function confirmar(
        ConfirmarRecepcionMaterialRequest $request,
        RecepcionMaterial $recepcionMaterial,
        ServicioRecepcionMaterial $servicio,
    ): RecepcionMaterialResource {
        abort_unless(
            $this->puedeOperarRecepcion($request, $recepcionMaterial),
            Response::HTTP_NOT_FOUND,
        );

        return new RecepcionMaterialResource($servicio->confirmar(
            $recepcionMaterial,
            $request->validated('operacion_id'),
            $request->integer('version_conocida'),
            $request->user(),
        ));
    }

    public function anular(
        AnularRecepcionMaterialRequest $request,
        RecepcionMaterial $recepcionMaterial,
        ServicioRecepcionMaterial $servicio,
    ): RecepcionMaterialResource {
        return new RecepcionMaterialResource($servicio->anular(
            $recepcionMaterial,
            $request->validated('operacion_id'),
            $request->validated('motivo'),
            $request->user(),
        ));
    }

    public function administrar(
        AdministrarRecepcionMaterialRequest $request,
        RecepcionMaterial $recepcionMaterial,
        ServicioRecepcionMaterial $servicio,
    ): RecepcionMaterialResource {
        return new RecepcionMaterialResource($servicio->corregirAdministrativamente(
            $recepcionMaterial,
            $request->validated(),
            $request->user(),
        ));
    }

    public function destroy(
        EliminarRecepcionMaterialRequest $request,
        RecepcionMaterial $recepcionMaterial,
        ServicioRecepcionMaterial $servicio,
    ): JsonResponse {
        $resultado = $servicio->eliminarAdministrativamente(
            $recepcionMaterial,
            $request->validated('operacion_id'),
            $request->integer('version_conocida'),
            $request->validated('motivo'),
            $request->user(),
        );

        return response()->json([
            'message' => 'La recepción fue eliminada y sus folios quedaron disponibles para reutilización.',
            'data' => $resultado,
        ]);
    }

    public function foliosPendientes(Request $request): JsonResponse
    {
        Gate::authorize('consultar-recepciones-materiales');
        $folios = FolioMaterial::query()
            ->with([
                'folio',
                'item.cliente.cliente',
                'bultoRecepcion.detalle.recepcion.proveedor',
            ])
            ->whereHas('folio', fn ($consulta) => $consulta
                ->where('activo', true)
                ->whereIn('estado_operacional', [
                    EstadoOperacionalFolio::PendienteUbicacion->value,
                    EstadoOperacionalFolio::Bloqueado->value,
                ])
                ->whereDoesntHave('ubicacionActual'))
            ->where(fn ($consulta) => $consulta
                ->whereHas('bultoRecepcion.detalle.recepcion', fn ($recepciones) => $recepciones->where('estado', EstadoRecepcionMaterial::Confirmada->value))
                ->orWhereHas('folio', fn ($folios) => $folios->where('origen_sistema', 'transferencia_cliente_materiales')->where('temporada_id', app(ServicioTemporadaActiva::class)->subconsultaId())))
            ->when($request->query('cliente_id'), fn ($consulta, $cliente) => $consulta
                ->whereHas('item.cliente', fn ($catalogo) => $catalogo->where('cliente_id', $cliente)))
            ->orderBy('created_at')
            ->limit(min(1000, max(100, (int) $request->query('limit', 500))))
            ->get()
            ->map(function (FolioMaterial $material): array {
                $recepcion = $material->bultoRecepcion?->detalle?->recepcion;
                $cliente = $material->item?->cliente?->cliente;

                return [
                    'folio_id' => $material->folio_id,
                    'numero_folio' => $material->folio?->numero_folio,
                    'estado_operacional' => $material->folio?->estado_operacional?->value,
                    'cliente' => $cliente ? [
                        'id' => $cliente->id,
                        'codigo' => $cliente->codigo,
                        'nombre' => $cliente->nombre,
                    ] : null,
                    'item' => [
                        'id' => $material->item?->id,
                        'codigo' => $material->item?->codigo,
                        'nombre' => $material->item?->nombre,
                    ],
                    'categoria_operacional' => $material->categoria_operacional?->value,
                    'cantidad_actual' => $material->cantidad_actual,
                    'unidad_medida' => $material->unidad_medida,
                    'lote_proveedor' => $material->lote,
                    'bloqueado' => $material->folio?->estado_operacional === EstadoOperacionalFolio::Bloqueado,
                    'motivo_bloqueo' => $material->motivo_bloqueo,
                    'recepcion' => $recepcion ? [
                        'id' => $recepcion->id,
                        'numero_guia_despacho' => $recepcion->numero_guia_despacho,
                        'proveedor' => $recepcion->proveedor?->nombre,
                        'confirmado_at' => $recepcion->confirmado_at?->toAtomString(),
                    ] : null,
                ];
            });

        return response()->json(['data' => $folios]);
    }

    private function puedeVerRecepcion(Request $request, RecepcionMaterial $recepcion): bool
    {
        if ($recepcion->estado === EstadoRecepcionMaterial::Confirmada) {
            return true;
        }

        return $this->puedeOperarRecepcion($request, $recepcion);
    }

    private function puedeOperarRecepcion(Request $request, RecepcionMaterial $recepcion): bool
    {
        $usuario = $request->user();

        return $usuario->can('anular-recepciones-materiales')
            || ($usuario->can('gestionar-recepciones-materiales')
                && $recepcion->creado_por_user_id === $usuario->id);
    }
}

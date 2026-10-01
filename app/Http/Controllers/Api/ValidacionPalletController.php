<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoValidacionPallet;
use App\Enums\ResultadoValidacionPallet;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConsultarOpcionesRegistroValidacionRequest;
use App\Http\Requests\ConsultarValidacionesPalletRequest;
use App\Http\Requests\CorregirValidacionPalletRequest;
use App\Http\Requests\ExportarRegistroValidacionPalletRequest;
use App\Http\Requests\RegistrarValidacionPalletRequest;
use App\Http\Resources\ValidacionPalletResource;
use App\Models\ArticuloValidacion;
use App\Models\CombinacionValidacion;
use App\Models\OrigenValidacion;
use App\Models\Temporada;
use App\Models\ValidacionPallet;
use App\Services\Autenticacion\ContextoOperacional;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Validacion\ServicioCorreccionValidacionPallet;
use App\Services\Validacion\ServicioExportacionRegistroValidacion;
use App\Services\Validacion\ServicioValidacionPallet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ValidacionPalletController extends Controller
{
    public function index(ConsultarValidacionesPalletRequest $request): AnonymousResourceCollection
    {
        $filtros = $request->validated();
        $consulta = $this->aplicarFiltros(
            ValidacionPallet::query()->with($this->relaciones()),
            $filtros,
            $request->rangoFechaUtc(),
        )
            ->orderByDesc('recibido_servidor_at')
            ->orderByDesc('numero_intento');

        return ValidacionPalletResource::collection(
            $consulta->paginate($filtros['per_page'] ?? 25)->withQueryString(),
        );
    }

    public function resumen(ConsultarValidacionesPalletRequest $request): JsonResponse
    {
        $filtros = $request->validated();
        $temporada = isset($filtros['temporada_id'])
            ? Temporada::query()->findOrFail($filtros['temporada_id'])
            : app(ServicioTemporadaActiva::class)->buscar();

        $observados = $this->aplicarFiltros(
            ValidacionPallet::query(), $filtros, $request->rangoFechaUtc(),
        )->where('resultado', ResultadoValidacionPallet::Observado->value)->count();

        return response()->json([
            'temporada' => $temporada ? [
                'id' => $temporada->id,
                'version_catalogo' => $temporada->version_catalogo,
            ] : null,
            'articulos_activos' => $temporada ? ArticuloValidacion::query()->where('temporada_id', $temporada->id)->where('activo', true)->count() : 0,
            'origenes_activos' => $temporada ? OrigenValidacion::query()->where('temporada_id', $temporada->id)->where('activo', true)->count() : 0,
            'combinaciones_activas' => $temporada ? CombinacionValidacion::query()->where('temporada_id', $temporada->id)->where('activo', true)->count() : 0,
            'observados' => $observados,
        ]);
    }

    public function miSesion(
        Request $request,
        ContextoOperacional $contexto,
    ): AnonymousResourceCollection {
        [$usuario, $dispositivo] = $contexto->obtener($request);
        $token = $usuario->currentAccessToken();
        $inicio = $token?->created_at ?? now();
        $temporada = app(ServicioTemporadaActiva::class)->buscar();

        $base = ValidacionPallet::query()
            ->where('user_id', $usuario->id)
            ->where('dispositivo_id', $dispositivo->id)
            ->where('generado_dispositivo_at', '>=', $inicio)
            ->when(
                $temporada,
                fn (Builder $consulta, Temporada $temporada): Builder => $consulta
                    ->where('temporada_id', $temporada->id),
            );

        $vigentes = (clone $base)
            ->orderByDesc('recibido_servidor_at')
            ->orderByDesc('numero_intento')
            ->get([
                'id',
                'numero_folio',
                'numero_intento',
                'resultado',
                'estado',
                'recibido_servidor_at',
            ])
            ->unique('numero_folio')
            ->values();
        $vigentesAceptadas = $vigentes
            ->where('estado', EstadoValidacionPallet::Aceptada);

        $consulta = (clone $base)
            ->with($this->relaciones())
            ->orderByDesc('recibido_servidor_at')
            ->orderByDesc('numero_intento');
        $porPagina = min(50, max(10, $request->integer('per_page', 25)));

        return ValidacionPalletResource::collection(
            $consulta->paginate($porPagina)->withQueryString(),
        )->additional([
            'sesion' => [
                'id' => (string) $token?->getKey(),
                'iniciada_at' => $inicio->toAtomString(),
                'servidor_at' => now()->toAtomString(),
                'usuario' => [
                    'id' => $usuario->id,
                    'nombre' => $usuario->name,
                ],
                'dispositivo' => [
                    'id' => $dispositivo->id,
                    'codigo' => $dispositivo->codigo,
                    'nombre' => $dispositivo->nombre,
                ],
                'temporada' => $temporada ? [
                    'id' => $temporada->id,
                    'codigo' => $temporada->codigo,
                    'nombre' => $temporada->nombre,
                ] : null,
            ],
            'resumen' => [
                'folios_trabajados' => $vigentes->count(),
                'registros_realizados' => (clone $base)->count(),
                'aprobados' => $vigentesAceptadas
                    ->where('resultado', ResultadoValidacionPallet::Aprobado)
                    ->count(),
                'observados' => $vigentesAceptadas
                    ->where('resultado', ResultadoValidacionPallet::Observado)
                    ->count(),
                'rechazados' => $vigentesAceptadas
                    ->where('resultado', ResultadoValidacionPallet::Rechazado)
                    ->count(),
                'conflictos' => $vigentes
                    ->where('estado', EstadoValidacionPallet::Conflicto)
                    ->count(),
            ],
        ]);
    }

    public function opciones(ConsultarOpcionesRegistroValidacionRequest $request): JsonResponse
    {
        $temporadas = Temporada::query()
            ->productivas()
            ->orderByDesc('activa')
            ->orderByDesc('created_at')
            ->get(['id', 'codigo', 'nombre', 'activa']);
        $temporada = $request->filled('temporada_id')
            ? $temporadas->firstWhere('id', $request->validated('temporada_id'))
            : $temporadas->firstWhere('activa', true);

        $validadores = $temporada
            ? DB::table('validaciones_pallet as validaciones')
                ->join('users', 'users.id', '=', 'validaciones.user_id')
                ->where('validaciones.temporada_id', $temporada->id)
                ->select(['users.id', 'users.name'])
                ->distinct()
                ->orderBy('users.name')
                ->get()
                ->map(fn (object $usuario): array => [
                    'id' => $usuario->id,
                    'nombre' => $usuario->name,
                ])
                ->values()
            : collect();

        return response()->json([
            'temporadas' => $temporadas,
            'temporada' => $temporada,
            'validadores' => $validadores,
        ]);
    }

    public function exportar(
        ExportarRegistroValidacionPalletRequest $request,
        ServicioExportacionRegistroValidacion $exportador,
    ): BinaryFileResponse {
        $filtros = $request->validated();
        $validaciones = $this->aplicarFiltros(
            ValidacionPallet::query()
                ->with(['usuario:id,name'])
                ->where('estado', EstadoValidacionPallet::Aceptada->value)
                ->whereNotNull('linea_proceso')
                ->whereNotNull('turno'),
            $filtros,
            $request->rangoFechaUtc(),
        )
            ->orderBy('generado_dispositivo_at')
            ->orderBy('numero_intento')
            ->get();

        $ruta = $exportador->generar($validaciones);
        $archivo = 'RRPP-01_'.$filtros['fecha'].'.xlsx';

        return response()->download(
            $ruta,
            $archivo,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend();
    }

    public function exportarEnBlanco(
        ServicioExportacionRegistroValidacion $exportador,
    ): BinaryFileResponse {
        return response()->download(
            $exportador->generarEnBlanco(),
            'RRPP-01_EN_BLANCO.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend();
    }

    public function show(ValidacionPallet $validacionPallet): ValidacionPalletResource
    {
        return new ValidacionPalletResource(
            $validacionPallet->load($this->relaciones()),
        );
    }

    public function corregir(
        CorregirValidacionPalletRequest $request,
        ValidacionPallet $validacionPallet,
        ServicioCorreccionValidacionPallet $servicio,
    ): JsonResponse {
        $validacion = $servicio->corregir(
            $validacionPallet,
            $request->validated(),
            $request->user(),
        );

        return (new ValidacionPalletResource($validacion))
            ->additional([
                'message' => 'La validación y su folio fueron corregidos y auditados.',
            ])
            ->response();
    }

    public function store(
        RegistrarValidacionPalletRequest $request,
        ContextoOperacional $contexto,
        ServicioValidacionPallet $servicio,
    ): JsonResponse {
        [$usuario, $dispositivo] = $contexto->obtener($request);
        [$validacion, $creada, $conflicto] = $servicio->registrar($request->validated(), $usuario, $dispositivo);

        $estado = $conflicto
            ? Response::HTTP_CONFLICT
            : ($creada ? Response::HTTP_CREATED : Response::HTTP_OK);

        return (new ValidacionPalletResource($validacion))
            ->additional([
                'catalogo_desactualizado' => $validacion->catalogo_version_dispositivo !== $validacion->catalogo_version_servidor,
                'message' => $conflicto
                    ? 'El folio ya posee una decisión final o existe en inventario. La contradicción quedó auditada.'
                    : null,
            ])
            ->response()
            ->setStatusCode($estado);
    }

    /**
     * @return array<int, string>
     */
    private function relaciones(): array
    {
        return [
            'temporada:id,codigo,nombre,activa',
            'folio:id,numero_folio,estado_operacional,condicion_termica,activo',
            'usuario:id,name',
            'dispositivo:id,codigo,nombre',
            'conflictoCon:id,numero_folio,numero_intento,resultado',
            'correcciones.corregidoPor:id,name',
        ];
    }

    /**
     * @param  Builder<ValidacionPallet>  $consulta
     * @param  array<string, mixed>  $filtros
     * @param  array{0:CarbonImmutable,1:CarbonImmutable}|null  $rangoFecha
     * @return Builder<ValidacionPallet>
     */
    private function aplicarFiltros(Builder $consulta, array $filtros, ?array $rangoFecha): Builder
    {
        return $consulta
            ->when(
                $filtros['temporada_id'] ?? null,
                fn (Builder $consulta, string $temporadaId): Builder => $consulta
                    ->where('temporada_id', $temporadaId),
                fn (Builder $consulta): Builder => $consulta->whereHas(
                    'temporada',
                    fn (Builder $temporada): Builder => $temporada->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id),
                ),
            )
            ->when(
                $filtros['folio'] ?? null,
                fn (Builder $consulta, string $folio): Builder => $consulta
                    ->where('numero_folio', $folio),
            )
            ->when(
                $filtros['resultado'] ?? null,
                fn (Builder $consulta, string $resultado): Builder => $consulta
                    ->where('resultado', $resultado),
            )
            ->when(
                $filtros['estado'] ?? null,
                fn (Builder $consulta, string $estado): Builder => $consulta
                    ->where('estado', $estado),
            )
            ->when(
                $filtros['linea_proceso'] ?? null,
                fn (Builder $consulta, int|string $linea): Builder => $consulta
                    ->where('linea_proceso', (int) $linea),
            )
            ->when(
                $filtros['turno'] ?? null,
                fn (Builder $consulta, string $turno): Builder => $consulta->where('turno', $turno),
            )
            ->when(
                $filtros['user_id'] ?? null,
                fn (Builder $consulta, int|string $usuarioId): Builder => $consulta
                    ->where('user_id', (int) $usuarioId),
            )
            ->when(
                $rangoFecha,
                fn (Builder $consulta, array $rango): Builder => $consulta
                    ->where('generado_dispositivo_at', '>=', $rango[0])
                    ->where('generado_dispositivo_at', '<', $rango[1]),
            );
    }
}

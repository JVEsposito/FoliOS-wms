<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoHidrocoolerMateriaPrima;
use App\Enums\EstadoLoteMateriaPrima;
use App\Http\Controllers\Controller;
use App\Http\Resources\LoteMateriaPrimaResource;
use App\Models\LoteMateriaPrima;
use App\Models\ProcesoHidrocoolerMateriaPrima;
use App\Services\MateriaPrima\RegistroHidrocoolerPdf;
use App\Services\MateriaPrima\RegistroHidrocoolerXlsx;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class HidrocoolerMateriaPrimaController extends Controller
{
    public function resumen(): JsonResponse
    {
        Gate::authorize('consultar-hidrocooler-materia-prima');
        $temporada = app(ServicioTemporadaActiva::class)->buscar();
        if (! $temporada) {
            return response()->json([
                'temporada' => null,
                'pendientes' => 0,
                'en_curso' => 0,
                'retenidos' => 0,
                'completados_hoy' => 0,
                'kilos_en_curso' => 0,
                'duracion_promedio_hoy' => 0,
                'equipos' => [],
            ]);
        }

        $lotes = LoteMateriaPrima::query()->where('temporada_id', $temporada->id);
        $procesos = ProcesoHidrocoolerMateriaPrima::query()
            ->whereHas('lote', fn (Builder $consulta) => $consulta
                ->where('temporada_id', $temporada->id));
        $completadosHoy = (clone $procesos)
            ->where('estado', EstadoHidrocoolerMateriaPrima::Completado->value)
            ->whereDate('termino_at', today());

        return response()->json([
            'temporada' => [
                'id' => $temporada->id,
                'codigo' => $temporada->codigo,
                'nombre' => $temporada->nombre,
            ],
            'pendientes' => (clone $lotes)
                ->where('estado', EstadoLoteMateriaPrima::PendienteHidrocooler->value)
                ->count(),
            'en_curso' => (clone $lotes)
                ->where('estado', EstadoLoteMateriaPrima::HidrocoolerEnCurso->value)
                ->count(),
            'retenidos' => (clone $lotes)
                ->where('estado', EstadoLoteMateriaPrima::HidrocoolerRetenido->value)
                ->count(),
            'completados_hoy' => (clone $completadosHoy)->count(),
            'kilos_en_curso' => round((float) (clone $procesos)
                ->where('estado', EstadoHidrocoolerMateriaPrima::EnCurso->value)
                ->sum('kilos_netos_snapshot'), 3),
            'duracion_promedio_hoy' => (int) round((float) (clone $completadosHoy)
                ->avg('duracion_minutos')),
            'equipos' => (clone $procesos)
                ->whereNotNull('equipo')
                ->distinct()
                ->orderBy('equipo')
                ->pluck('equipo')
                ->values(),
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('consultar-hidrocooler-materia-prima');
        $request->validate([
            'bandeja' => ['nullable', Rule::in(['pendientes', 'en_curso', 'retenidos', 'historial'])],
            'buscar' => ['nullable', 'string', 'max:100'],
            'equipo' => ['nullable', 'string', 'max:100'],
            'turno' => ['nullable', Rule::in(['A', 'B'])],
            'destino' => ['nullable', Rule::in(['camara', 'proceso'])],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
        ]);
        $temporada = app(ServicioTemporadaActiva::class)->buscar();
        $bandeja = $request->string('bandeja', 'pendientes')->toString();

        $consulta = LoteMateriaPrima::query()
            ->when($temporada, fn (Builder $query) => $query
                ->where('temporada_id', $temporada->id))
            ->when(! $temporada, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->where('requiere_hidrocooler', true)
            ->when($bandeja === 'pendientes', fn (Builder $query) => $query
                ->where('estado', EstadoLoteMateriaPrima::PendienteHidrocooler->value))
            ->when($bandeja === 'en_curso', fn (Builder $query) => $query
                ->where('estado', EstadoLoteMateriaPrima::HidrocoolerEnCurso->value))
            ->when($bandeja === 'retenidos', fn (Builder $query) => $query
                ->where('estado', EstadoLoteMateriaPrima::HidrocoolerRetenido->value))
            ->when($bandeja === 'historial', fn (Builder $query) => $query
                ->whereHas('hidrocooler', fn (Builder $hidrocooler) => $hidrocooler
                    ->where('estado', EstadoHidrocoolerMateriaPrima::Completado->value)))
            ->when($request->filled('equipo'), fn (Builder $query) => $query
                ->whereHas('hidrocooler', fn (Builder $hidrocooler) => $hidrocooler
                    ->where('equipo', $request->string('equipo')->toString())))
            ->when($request->filled('turno'), fn (Builder $query) => $query
                ->whereHas('hidrocooler', fn (Builder $hidrocooler) => $hidrocooler
                    ->where('turno', $request->string('turno')->toString())))
            ->when($request->filled('destino'), fn (Builder $query) => $query
                ->whereHas('hidrocooler', fn (Builder $hidrocooler) => $hidrocooler
                    ->where('destino_salida', $request->string('destino')->toString())))
            ->when($request->filled('desde'), fn (Builder $query) => $query
                ->whereHas('hidrocooler', fn (Builder $hidrocooler) => $hidrocooler
                    ->where(
                        'inicio_at',
                        '>=',
                        CarbonImmutable::parse($request->string('desde')->toString())->startOfDay(),
                    )))
            ->when($request->filled('hasta'), fn (Builder $query) => $query
                ->whereHas('hidrocooler', fn (Builder $hidrocooler) => $hidrocooler
                    ->where(
                        'inicio_at',
                        '<',
                        CarbonImmutable::parse($request->string('hasta')->toString())->addDay()->startOfDay(),
                    )))
            ->when($request->filled('buscar'), function (Builder $query) use ($request): void {
                $buscar = '%'.$request->string('buscar')->trim()->toString().'%';
                $query->where(function (Builder $filtro) use ($buscar): void {
                    $filtro->where('numero_lote', 'like', $buscar)
                        ->orWhere('csg_snapshot', 'like', $buscar)
                        ->orWhereHas('recepcion', fn (Builder $recepcion) => $recepcion
                            ->where('numero_recepcion', 'like', $buscar))
                        ->orWhereHas('cliente', fn (Builder $cliente) => $cliente
                            ->where('nombre', 'like', $buscar)
                            ->orWhere('codigo', 'like', $buscar))
                        ->orWhereHas('hidrocooler', fn (Builder $hidrocooler) => $hidrocooler
                            ->where('codigo', 'like', $buscar)
                            ->orWhere('equipo', 'like', $buscar));
                });
            })
            ->with($this->relaciones());

        if ($bandeja === 'historial') {
            $consulta->orderByDesc(
                ProcesoHidrocoolerMateriaPrima::query()
                    ->select('termino_at')
                    ->whereColumn(
                        'procesos_hidrocooler_materia_prima.lote_materia_prima_id',
                        'lotes_materia_prima.id',
                    )
                    ->limit(1),
            );
        } else {
            $consulta->orderBy('confirmado_at')->orderBy('numero_lote');
        }

        return LoteMateriaPrimaResource::collection(
            $consulta->paginate(min(200, max(1, $request->integer('per_page', 100)))),
        );
    }

    public function registro(
        Request $request,
        RegistroHidrocoolerXlsx $xlsx,
        RegistroHidrocoolerPdf $pdf,
    ): BinaryFileResponse|Response {
        Gate::authorize('consultar-hidrocooler-materia-prima');
        $request->validate($this->reglasRegistro());
        $procesos = $this->procesosRegistro($request);

        return $this->descargarRegistro(
            $request->string('formato', 'xlsx')->toString(),
            $procesos,
            $xlsx,
            $pdf,
            false,
        );
    }

    public function registroEnBlanco(
        Request $request,
        RegistroHidrocoolerXlsx $xlsx,
        RegistroHidrocoolerPdf $pdf,
    ): BinaryFileResponse|Response {
        Gate::authorize('consultar-hidrocooler-materia-prima');
        $request->validate([
            'formato' => ['nullable', Rule::in(['xlsx', 'pdf'])],
        ]);

        return $this->descargarRegistro(
            $request->string('formato', 'xlsx')->toString(),
            collect(),
            $xlsx,
            $pdf,
            true,
        );
    }

    /** @return array<string, mixed> */
    private function reglasRegistro(): array
    {
        return [
            'formato' => ['nullable', Rule::in(['xlsx', 'pdf'])],
            'buscar' => ['nullable', 'string', 'max:100'],
            'equipo' => ['nullable', 'string', 'max:100'],
            'turno' => ['nullable', Rule::in(['A', 'B'])],
            'destino' => ['nullable', Rule::in(['camara', 'proceso'])],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ];
    }

    /** @return Collection<int, ProcesoHidrocoolerMateriaPrima> */
    private function procesosRegistro(Request $request): Collection
    {
        $temporada = app(ServicioTemporadaActiva::class)->buscar();
        if (! $temporada) {
            return collect();
        }

        return ProcesoHidrocoolerMateriaPrima::query()
            ->whereIn('estado', [EstadoHidrocoolerMateriaPrima::Completado->value, EstadoHidrocoolerMateriaPrima::EnCurso->value])
            ->whereHas('lote', fn (Builder $lote) => $lote
                ->where('temporada_id', $temporada->id))
            ->when($request->filled('equipo'), fn (Builder $query) => $query
                ->where('equipo', $request->string('equipo')->toString()))
            ->when($request->filled('turno'), fn (Builder $query) => $query
                ->where('turno', $request->string('turno')->toString()))
            ->when($request->filled('destino'), fn (Builder $query) => $query
                ->where('destino_salida', $request->string('destino')->toString()))
            ->when($request->filled('desde'), fn (Builder $query) => $query
                ->where('inicio_at', '>=', CarbonImmutable::parse($request->string('desde')->toString())->startOfDay()))
            ->when($request->filled('hasta'), fn (Builder $query) => $query
                ->where('inicio_at', '<', CarbonImmutable::parse($request->string('hasta')->toString())->addDay()->startOfDay()))
            ->when(
                ! $request->filled('desde') && ! $request->filled('hasta'),
                fn (Builder $query) => $query->where('inicio_at', '>=', CarbonImmutable::today()->startOfDay()),
            )
            ->when($request->filled('buscar'), function (Builder $query) use ($request): void {
                $buscar = '%'.$request->string('buscar')->trim()->toString().'%';
                $query->where(function (Builder $filtro) use ($buscar): void {
                    $filtro->where('codigo', 'like', $buscar)
                        ->orWhere('equipo', 'like', $buscar)
                        ->orWhereHas('lote', fn (Builder $lote) => $lote
                            ->where('numero_lote', 'like', $buscar)
                            ->orWhere('csg_snapshot', 'like', $buscar)
                            ->orWhereHas('recepcion', fn (Builder $recepcion) => $recepcion
                                ->where('numero_recepcion', 'like', $buscar))
                            ->orWhereHas('cliente', fn (Builder $cliente) => $cliente
                                ->where('nombre', 'like', $buscar)
                                ->orWhere('codigo', 'like', $buscar)));
                });
            })
            ->with([
                'lote.temporada',
                'lote.recepcion',
                'lote.cliente',
                'lote.csg.productor',
                'iniciadoPor',
                'completadoPor',
            ])
            ->orderBy('inicio_at')
            ->orderBy('codigo')
            ->get();
    }

    /** @param Collection<int, ProcesoHidrocoolerMateriaPrima> $procesos */
    private function descargarRegistro(
        string $formato,
        Collection $procesos,
        RegistroHidrocoolerXlsx $xlsx,
        RegistroHidrocoolerPdf $pdf,
        bool $enBlanco,
    ): BinaryFileResponse|Response {
        $sufijo = $enBlanco ? 'en-blanco' : now()->format('Ymd-His');
        if ($formato === 'pdf') {
            $contenido = $enBlanco ? $pdf->generarEnBlanco() : $pdf->generar($procesos);

            return response($contenido, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="registro-hidrocooler-'.$sufijo.'.pdf"',
            ]);
        }

        $ruta = $enBlanco ? $xlsx->generarEnBlanco() : $xlsx->generar($procesos);

        return response()->download(
            $ruta,
            'registro-hidrocooler-'.$sufijo.'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend(true);
    }

    /** @return array<int, string> */
    private function relaciones(): array
    {
        return [
            'segmento.envases',
            'recepcion',
            'temporada',
            'cliente',
            'csg',
            'especie',
            'variedad',
            'calibre',
            'creadoPor',
            'actualizadoPor',
            'confirmadoPor',
            'hidrocooler.iniciadoPor',
            'hidrocooler.completadoPor',
            'hidrocooler.liberadoPor',
            'asignacionCamara.camara',
        ];
    }
}

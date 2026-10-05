<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsultarEtiquetasPtRequest;
use App\Http\Requests\GenerarEtiquetasPtRequest;
use App\Models\ImpresionEtiquetaPt;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Validacion\GeneradorEtiquetaPtPdf;
use App\Services\Validacion\ServicioEtiquetasPt;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class EtiquetaPtController extends Controller
{
    public function index(ConsultarEtiquetasPtRequest $request, ServicioEtiquetasPt $servicio): JsonResponse
    {
        $temporada = app(ServicioTemporadaActiva::class)->buscar();
        $filtros = $request->validated();
        $consulta = $servicio->consulta()->with(['folio', 'usuario', 'temporada'])
            ->where('temporada_id', $temporada?->id ?? 'sin-temporada');
        if ($filtros['folio'] ?? null) {
            // El folio admite caracteres literales %, _ y barras; no son comodines de búsqueda.
            $consulta->where('numero_folio', $filtros['folio']);
        }
        foreach (['linea_proceso', 'turno', 'user_id'] as $campo) {
            if ($filtros[$campo] ?? null) {
                $consulta->where($campo, $filtros[$campo]);
            }
        }
        if ($rango = $request->rangoFechaUtc()) {
            $consulta->where('generado_dispositivo_at', '>=', $rango[0])->where('generado_dispositivo_at', '<', $rango[1]);
        }
        $pagina = $consulta->orderByDesc('recibido_servidor_at')->orderBy('id')->paginate($filtros['per_page'] ?? 25);

        return response()->json([
            'temporada' => $temporada ? ['id' => $temporada->id, 'codigo' => $temporada->codigo, 'nombre' => $temporada->nombre] : null,
            'data' => $pagina->getCollection()->map(fn ($validacion) => [
                ...$servicio->etiqueta($validacion), 'version' => $servicio->version($validacion),
            ])->all(),
            'meta' => ['current_page' => $pagina->currentPage(), 'last_page' => $pagina->lastPage(), 'total' => $pagina->total()],
        ]);
    }

    public function historial(ConsultarEtiquetasPtRequest $request): JsonResponse
    {
        $temporada = app(ServicioTemporadaActiva::class)->buscar();
        $pagina = ImpresionEtiquetaPt::query()->with('usuario:id,name')
            ->where('temporada_id', $temporada?->id ?? 'sin-temporada')->latest()->orderByDesc('id')->paginate(25);

        return response()->json([
            'data' => $pagina->getCollection()->map(fn ($impresion) => [
                'id' => $impresion->id, 'fecha' => $impresion->created_at->toAtomString(),
                'usuario' => $impresion->usuario?->name, 'tipo' => $impresion->tipo, 'copias' => $impresion->copias,
                'motivo' => $impresion->motivo_reimpresion,
                'folios' => array_column($impresion->etiquetas_snapshot, 'numero_folio'),
            ])->all(),
            'meta' => ['current_page' => $pagina->currentPage(), 'last_page' => $pagina->lastPage()],
        ]);
    }

    public function store(GenerarEtiquetasPtRequest $request, ServicioEtiquetasPt $servicio, GeneradorEtiquetaPtPdf $pdf): Response
    {
        $impresion = $servicio->generar($request->validated(), $request->user());

        return response($pdf->generarPt($impresion->etiquetas_snapshot, $impresion->tipo, $impresion->copias), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="etiquetas-pt-'.$impresion->id.'.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Impresion-Id' => $impresion->id,
        ]);
    }
}

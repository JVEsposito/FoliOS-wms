<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Services\MateriaPrima\GeneradorInformeRecepcionesCsgPdf;
use App\Services\MateriaPrima\InformeRecepcionesCsg;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class InformeRecepcionesCsgController extends Controller
{
    public function __construct(
        private readonly ServicioTemporadaActiva $temporadas,
        private readonly InformeRecepcionesCsg $informe,
    ) {}

    public function opciones(): JsonResponse
    {
        $temporada = $this->temporadas->buscar();

        return response()->json(['data' => [
            'temporada' => $temporada?->only(['codigo', 'nombre']),
            'hoy' => CarbonImmutable::now(config('app.operational_timezone'))->toDateString(),
            'clientes' => $temporada ? $this->informe->clientes($temporada) : [],
        ]])->header('Cache-Control', 'no-store, private');
    }

    public function index(Request $request, Cliente $cliente): JsonResponse
    {
        return response()->json(['data' => $this->consultar($request, $cliente, true)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function pdf(Request $request, Cliente $cliente, GeneradorInformeRecepcionesCsgPdf $pdf): Response
    {
        $informe = $this->consultar($request, $cliente, false);
        $periodo = $informe['periodo'];
        $nombre = 'recepcion-csg-'.(Str::slug((string) $cliente->codigo) ?: 'cliente').'-'.$periodo['desde'];
        if ($periodo['desde'] !== $periodo['hasta']) {
            $nombre .= '_'.$periodo['hasta'];
        }

        return response($pdf->generar($informe), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$nombre.'.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function consultar(Request $request, Cliente $cliente, bool $paginar): array
    {
        $datos = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $temporada = $this->temporadas->buscar() ?? abort(404, 'No hay una temporada activa.');

        return $this->informe->generar(
            $temporada,
            $cliente,
            $datos['desde'],
            $datos['hasta'] ?? $datos['desde'],
            $paginar ? (int) ($datos['page'] ?? 1) : null,
        );
    }
}

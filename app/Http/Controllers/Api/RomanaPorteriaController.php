<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IngresoPorteria;
use App\Services\Porteria\GeneradorDiarioPorteriaPdf;
use App\Services\Porteria\ServicioIngresosPorteria;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RomanaPorteriaController extends Controller
{
    /** Consulta liviana, independiente de la lista de recepciones y del corte de medianoche. */
    public function resumen(): JsonResponse
    {
        $pendientes = IngresoPorteria::query()->whereNull('anulado_at')->whereNull('revisado_at');

        return response()->json([
            'pendientes' => (clone $pendientes)->count(),
            'ultimo_ingreso_id' => IngresoPorteria::query()->max('id') ?? 0,
            'recientes' => (clone $pendientes)->orderByDesc('id')->limit(3)->get(['id', 'patente', 'cliente_nombre', 'guias', 'ingreso_at']),
            'consultado_at' => now()->toAtomString(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function revisar(Request $request, IngresoPorteria $ingresoPorteria, ServicioIngresosPorteria $servicio): JsonResponse
    {
        $datos = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $servicio->presentar($servicio->revisar($ingresoPorteria, $datos['version'], $request->user()))]);
    }

    public function diario(Request $request, GeneradorDiarioPorteriaPdf $pdf): Response
    {
        $datos = $request->validate(['fecha' => ['required', 'date_format:Y-m-d']]);
        $desde = CarbonImmutable::parse($datos['fecha'], 'America/Santiago')->startOfDay();
        $hasta = $desde->addDay();
        $ingresos = IngresoPorteria::query()->where('ingreso_at', '>=', $desde->utc())->where('ingreso_at', '<', $hasta->utc())->orderBy('ingreso_at')->orderBy('id')->get();

        return response($pdf->generar($datos['fecha'], $ingresos))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="ingresos-porteria-'.$datos['fecha'].'.pdf"')
            ->header('Cache-Control', 'no-store, private');
    }
}

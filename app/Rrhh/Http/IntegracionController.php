<?php

namespace App\Rrhh\Http;

use App\Http\Controllers\Controller;
use App\Rrhh\Integracion\FechaGeoVictoria;
use App\Rrhh\Integracion\IntercambioNomina;
use App\Rrhh\Integracion\RecepcionGeoVictoria;
use App\Rrhh\Models\EntregaNomina;
use App\Rrhh\Models\FuenteMarcaje;
use App\Rrhh\Models\LoteIntegracion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IntegracionController extends Controller
{
    public function consultar(Request $r, FuenteMarcaje $fuente, RecepcionGeoVictoria $g): JsonResponse
    {
        $d = $r->validate(['desde' => ['required', 'string', 'regex:/^\d{14}$/'], 'identificadores' => ['required', 'array', 'min:1', 'max:20'],
            'identificadores.*' => ['required', 'string', 'max:100', 'distinct:strict', 'not_regex:/[,\r\n]/']]);
        FechaGeoVictoria::normalizar($d['desde'], $fuente->configuracion['zona_origen'] ?? null);

        return response()->json(['data' => $g->consultar($fuente, $d, $r->user())], 201);
    }

    public function lotes(Request $r, FuenteMarcaje $fuente): JsonResponse
    {
        $r->validate(['page' => ['nullable', 'integer', 'min:1']]);

        return response()->json(['data' => LoteIntegracion::query()->where('fuente_id', $fuente->id)->orderByDesc('created_at')->orderBy('id')->paginate(25)]);
    }

    public function reintentar(Request $r, LoteIntegracion $lote, RecepcionGeoVictoria $g): JsonResponse
    {
        return response()->json(['data' => $g->procesar($lote, $r->user())]);
    }

    public function entregas(Request $r, IntercambioNomina $g): JsonResponse
    {
        $r->validate(['page' => ['nullable', 'integer', 'min:1']]);

        return response()->json(['data' => $g->pendientes()]);
    }

    public function acuse(Request $r, EntregaNomina $entrega, IntercambioNomina $g): JsonResponse
    {
        $d = $r->validate(['huella' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'recibo_externo' => ['required', 'string', 'min:3', 'max:200']]);

        return response()->json(['data' => $g->acusar($entrega, $d, $r->user())]);
    }
}

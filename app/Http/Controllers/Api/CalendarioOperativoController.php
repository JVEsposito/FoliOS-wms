<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Jornadas\CalendarioOperativo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarioOperativoController extends Controller
{
    public function mostrar(CalendarioOperativo $calendario): JsonResponse
    {
        return response()->json(['data' => $calendario->contexto()])->header('Cache-Control', 'private, no-store');
    }

    public function index(CalendarioOperativo $calendario): JsonResponse
    {
        $historial = $calendario->reglas()->whereNotNull('vigente_desde')->values();

        return response()->json([
            'data' => $calendario->contexto(),
            'historial' => $historial,
            'version_conocida' => (int) ($historial->first()->id ?? 0),
            'sugerido' => $calendario->sugerido(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, CalendarioOperativo $calendario): JsonResponse
    {
        $datos = $request->validate([
            'vigente_desde' => ['required', 'date_format:Y-m-d'],
            'inicio_jornada' => ['required', 'date_format:H:i'],
            'inicio_turno_b' => ['required', 'date_format:H:i', 'different:inicio_jornada'],
            'version_conocida' => ['required', 'integer', 'min:0'],
        ]);
        $calendario->publicar($datos, $request->user()->id);

        return $this->index($calendario);
    }
}

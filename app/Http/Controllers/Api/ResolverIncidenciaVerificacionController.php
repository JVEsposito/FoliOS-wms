<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IncidenciaVerificacionUbicacion;
use App\Services\Verificaciones\ServicioResolverIncidenciaVerificacion;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ResolverIncidenciaVerificacionController extends Controller
{
    public function __invoke(Request $r, IncidenciaVerificacionUbicacion $incidencia, ServicioResolverIncidenciaVerificacion $servicio)
    {
        $datos = $r->validate(['operacion_id' => ['required', 'uuid'], 'motivo' => ['required', 'string', 'max:2000', 'regex:/\S/u'], 'tipo_resolucion' => ['required', Rule::in(['reubicado', 'error_de_conteo', 'otro'])]]);
        $resuelta = $servicio->resolver($incidencia, $datos, $r->user());

        return response()->json(['data' => ['id' => $resuelta->id, 'estado' => $resuelta->estado, 'resolucion' => $resuelta->resolucion, 'tipo_resolucion' => $resuelta->tipo_resolucion]]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Integraciones\ServicioLecturaStockOrigen;
use App\Services\Integraciones\ServicioOperacionesIntegracion;
use App\Services\Validacion\ServicioPrecargaValidacionOrigen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Datos de planta de un pallet para que la PDA parta con ellos al validarlo. Solo lectura. */
class PrecargaValidacionOrigenController extends Controller
{
    /** La PDA nueva solicita automáticamente una lectura puntual cuando falta la copia vigente. */
    public function consultar(Request $request, ServicioPrecargaValidacionOrigen $precarga): JsonResponse
    {
        $datos = $request->validate([
            'numero_folio' => ['required', 'string', 'max:50', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'temporada_id' => ['required', 'uuid'],
        ]);
        $numero = mb_strtoupper(trim($datos['numero_folio']));
        $precarga->comprobarFolioNuevo($numero, $datos['temporada_id']);
        $config = app(ServicioLecturaStockOrigen::class)->configuracion();
        if (! $config['habilitada'] || ! $config['conexion']) {
            return response()->json(['data' => $precarga->paraFolio($numero)])->header('Cache-Control', 'private, no-store');
        }
        $copia = $precarga->paraFolio($numero);
        if ($copia['disponible'] && ! $copia['desactualizada']) {
            return response()->json(['data' => $copia])->header('Cache-Control', 'private, no-store');
        }
        $operaciones = app(ServicioOperacionesIntegracion::class);

        return $operaciones->aceptada($operaciones->solicitar('precarga', $config['conexion'], [
            'numero_folio' => $numero, 'temporada_id' => $datos['temporada_id'],
            'fuente_hash' => app(ServicioLecturaStockOrigen::class)->huellaConfiguracion($config),
        ], $request->user()));
    }

    public function __invoke(Request $request, ServicioPrecargaValidacionOrigen $precarga): JsonResponse
    {
        $datos = $request->validate([
            'numero_folio' => ['required', 'string', 'max:50'],
        ]);

        return response()->json(['data' => $precarga->paraFolio($datos['numero_folio'])])->header('Cache-Control', 'private, no-store');
    }
}

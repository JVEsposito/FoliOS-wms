<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RecepcionRomana;
use App\Services\Romana\GeneradorControlEnvasesPdf;
use App\Services\Romana\ServicioInspeccionEnvases;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class InspeccionEnvasesController extends Controller
{
    public function documento(RecepcionRomana $recepcion, string $tipo, ServicioInspeccionEnvases $servicio, GeneradorControlEnvasesPdf $generador): Response
    {
        abort_unless(in_array($tipo, ['recepcion', 'despacho'], true) && $servicio->disponible($recepcion, $tipo), 404);

        return $this->pdf($generador->generar($recepcion, $tipo), 'rc02-'.$tipo.'-'.strtolower($recepcion->numero_recepcion));
    }

    public function enBlanco(GeneradorControlEnvasesPdf $generador): Response
    {
        return $this->pdf($generador->generarEnBlanco(), 'rc02-en-blanco');
    }

    public function corregir(Request $request, RecepcionRomana $recepcion, string $tipo, ServicioInspeccionEnvases $servicio)
    {
        abort_unless(in_array($tipo, ['recepcion', 'despacho'], true), 404);
        $datos = $request->validate(['operacion_id' => ['required', 'uuid'], 'version_conocida' => ['required', 'integer', 'min:0'], 'motivo' => ['required', 'string', 'max:2000'], ...ServicioInspeccionEnvases::reglas($tipo === 'recepcion')]);

        return response()->json(['data' => $servicio->serializar($servicio->corregir($recepcion, $tipo, $datos, $request->user()))]);
    }

    private function pdf(string $contenido, string $nombre): Response
    {
        return response($contenido, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$nombre.'.pdf"', 'Cache-Control' => 'no-store, private']);
    }
}

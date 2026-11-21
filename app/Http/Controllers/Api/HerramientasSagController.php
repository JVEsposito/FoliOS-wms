<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InspeccionSag\GeneradorRevisionSag;
use App\Services\InspeccionSag\LectorLoteInsSag;
use App\Services\InspeccionSag\RegistroCerezasChinaSag;
use App\Services\InspeccionSag\RevisionPackingMexicoSag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class HerramientasSagController extends Controller
{
    private const INS = ['required', 'file', 'extensions:ins', 'max:512'];

    public function leerLote(Request $request, LectorLoteInsSag $lector): JsonResponse
    {
        $datos = $request->validate(['archivo' => self::INS]);

        return response()->json($lector->leer($datos['archivo']->get()));
    }

    public function lotePdf(Request $request, LectorLoteInsSag $lector, GeneradorRevisionSag $pdf): Response
    {
        $texto = ['required', 'string', 'max:60', 'not_regex:/[\x00-\x1f\x7f]/u'];
        $datos = $request->validate([
            'archivo' => self::INS,
            'lote' => $texto,
            'especie' => $texto,
            'muestreo' => ['required', 'in:N,PE'],
            'seleccion' => ['required', 'in:T,M,A'],
            'despacho' => ['required', 'in:I,P,D'],
            'certificados' => ['required', 'string', 'max:2000000'],
        ]);
        $lista = Validator::make(['certificados' => json_decode($datos['certificados'], true)], [
            'certificados' => ['present', 'array', 'list', 'max:9999'],
            'certificados.*' => ['required', 'array:folio,premuestreo,cajas_premuestreo,inspeccion,cajas_inspeccion'],
            'certificados.*.folio' => ['required', 'string', 'regex:/^[0-9]{10}$/D', 'distinct:strict'],
            'certificados.*.premuestreo' => ['nullable', ...array_slice($texto, 1)],
            'certificados.*.inspeccion' => ['nullable', ...array_slice($texto, 1)],
            'certificados.*.cajas_premuestreo' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'certificados.*.cajas_inspeccion' => ['nullable', 'integer', 'min:1', 'max:9999'],
        ])->validate()['certificados'];
        $lote = $lector->conCertificados($lector->leer($datos['archivo']->get()), $lista);

        return $this->pdf($pdf->descripcionLote($lote, $datos), 'Descripcion-lote-'.$lote['sif'].'.pdf');
    }

    public function iniciarMexico(Request $request, RevisionPackingMexicoSag $servicio): JsonResponse
    {
        $datos = $request->validate(['archivo' => ['required', 'file', 'extensions:xlsx,csv,txt', 'max:5120']]);

        return response()->json($servicio->iniciar($datos['archivo'], (string) $request->user()->getAuthIdentifier()), 201);
    }

    public function consultarMexico(Request $request, RevisionPackingMexicoSag $servicio, string $revision, int $grupo): Response
    {
        $servicio->consultarGrupo($revision, (string) $request->user()->getAuthIdentifier(), $grupo);

        return response()->noContent();
    }

    public function resultadoMexico(Request $request, RevisionPackingMexicoSag $servicio, string $revision): JsonResponse
    {
        return response()->json($servicio->resultado($revision, (string) $request->user()->getAuthIdentifier()));
    }

    public function mexicoPdf(Request $request, RevisionPackingMexicoSag $servicio, GeneradorRevisionSag $pdf, string $revision): Response
    {
        $resultado = $servicio->resultado($revision, (string) $request->user()->getAuthIdentifier());

        return $this->pdf($pdf->mexico($resultado), 'Revision-SdP-Mexico.pdf');
    }

    public function consultarChina(Request $request, RegistroCerezasChinaSag $servicio): JsonResponse
    {
        $datos = $request->validate([
            'csg' => ['nullable', 'string', 'max:3500'],
            'csp' => ['nullable', 'string', 'max:3500'],
        ]);

        return response()->json($servicio->consultar($datos));
    }

    private function pdf(string $contenido, string $nombre): Response
    {
        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

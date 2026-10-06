<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EtiquetasRecepcionFrutaEmbaladaRequest;
use App\Models\RecepcionFrutaEmbalada;
use App\Services\RecepcionEmbalada\ServicioDocumentoRecepcionEmbalada;
use App\Services\Validacion\GeneradorEtiquetaPtPdf;
use App\Services\Validacion\ServicioEtiquetasPt;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class DocumentoRecepcionEmbaladaController extends Controller
{
    public function blanco(ServicioDocumentoRecepcionEmbalada $documentos): Response
    {
        return $this->archivo($documentos->blanco(), 'rrfe-01-blanco.pdf');
    }

    public function emitir(string $recepcion, Request $request, ServicioDocumentoRecepcionEmbalada $documentos): Response
    {
        Gate::authorize('gestionar-recepciones-fruta-embalada');

        return $this->archivo($documentos->emitir($recepcion, $request->user()), 'rrfe-01-'.$recepcion.'.pdf');
    }

    public function etiquetas(string $recepcion, EtiquetasRecepcionFrutaEmbaladaRequest $request, ServicioEtiquetasPt $etiquetas, GeneradorEtiquetaPtPdf $pdf): Response
    {
        $datos = $request->validated();
        $r = RecepcionFrutaEmbalada::findOrFail($recepcion);
        $ids = array_column($datos['folios'], 'id');
        $cantidad = DB::table('recepcion_fruta_embalada_folios as rf')->join('aceptaciones_fruta_embalada as a', 'a.id', '=', 'rf.aceptacion_id')
            ->where('a.recepcion_id', $recepcion)->whereIn('rf.folio_id', $ids)->count();
        if ($r->estado->value !== 'aceptada' || $r->temporada_id !== $datos['temporada_id'] || $cantidad !== count($ids)) {
            throw new DomainException('Selecciona únicamente folios de esta recepción aceptada.');
        }
        $impresion = $etiquetas->generar($datos, $request->user());

        return $this->archivo($pdf->generarPt($impresion->etiquetas_snapshot, 'planta', $impresion->copias), 'etiquetas-externas-'.$impresion->id.'.pdf', ['X-Impresion-Id' => $impresion->id]);
    }

    private function archivo(string $contenido, string $nombre, array $cabeceras = []): Response
    {
        return response($contenido, 200, [...$cabeceras, 'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nombre.'"', 'Cache-Control' => 'private, no-store']);
    }
}

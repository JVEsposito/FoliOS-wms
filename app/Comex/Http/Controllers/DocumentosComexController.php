<?php

namespace App\Comex\Http\Controllers;

use App\Comex\Expediente\EmbarquesOperacion;
use App\Comex\Expediente\Expedientes;
use App\Comex\Expediente\RegistroDocumentos;
use App\Comex\Models\ArchivoDocumento;
use App\Comex\Models\Documento;
use App\Comex\Models\TipoDocumento;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Registro de documentos del expediente y descarga de sus respaldos. */
class DocumentosComexController extends Controller
{
    public function __construct(
        private readonly EmbarquesOperacion $embarques,
        private readonly RegistroDocumentos $registro,
        private readonly Expedientes $expedientes,
    ) {}

    public function store(Request $request, string $embarque, TipoDocumento $tipo): JsonResponse
    {
        $maximoKb = intdiv((int) config('comex.bytes_maximos_archivo', 10 * 1024 * 1024), 1024);
        $datos = Validator::make($request->all(), [
            'estado' => ['required', Rule::in(Documento::ESTADOS)],
            'numero' => ['nullable', 'string', 'max:120'],
            'fecha_emision' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'observacion' => ['nullable', 'string', 'max:1000'],
            'motivo' => ['nullable', 'string', 'max:500'],
            'version' => ['required', 'integer', 'min:0'],
            'archivo' => ['nullable', 'file', "max:{$maximoKb}"],
        ], [
            'estado.required' => 'Indique el estado del documento.',
            'estado.in' => 'El estado indicado no es válido.',
            'numero.max' => 'El número no puede superar 120 caracteres.',
            'fecha_emision.date_format' => 'La fecha de emisión no es válida.',
            'fecha_emision.after_or_equal' => 'La fecha de emisión no es válida.',
            'fecha_emision.before_or_equal' => 'La fecha de emisión no es válida.',
            'observacion.max' => 'La observación no puede superar 1000 caracteres.',
            'motivo.max' => 'El motivo no puede superar 500 caracteres.',
            'version.required' => 'Falta la versión del documento. Recargue el expediente.',
            'archivo.file' => 'El respaldo no se pudo leer. Súbalo de nuevo.',
            'archivo.max' => 'El respaldo supera el tamaño máximo de '.intdiv($maximoKb, 1024).' MB.',
        ])->validate();

        $datosEmbarque = $this->embarques->uno($embarque);
        abort_if($datosEmbarque === null, 404, 'El embarque no existe.');

        $documento = $this->registro->registrar($datosEmbarque, $tipo, $datos, $request->file('archivo'), $request->user());

        return response()->json([
            'message' => match ($documento->estado) {
                Documento::LISTO => "{$tipo->nombre}: listo.",
                Documento::NO_APLICA => "{$tipo->nombre}: no aplica a este embarque.",
                default => "{$tipo->nombre}: en trámite.",
            },
            'data' => $this->expedientes->detalle($embarque),
        ]);
    }

    public function archivoVigente(string $embarque, TipoDocumento $tipo): StreamedResponse
    {
        $documento = Documento::query()->where('embarque_id', $embarque)->where('tipo_documento_id', $tipo->id)->first();
        $archivo = $documento?->archivoVigente;
        abort_if($archivo === null, 404, 'El documento no tiene respaldo.');

        return $this->registro->descargar($archivo, $this->nombre($documento, $tipo));
    }

    public function archivo(ArchivoDocumento $archivo): StreamedResponse
    {
        $documento = Documento::query()->with('tipo')->findOrFail($archivo->documento_id);

        return $this->registro->descargar($archivo, $this->nombre($documento, $documento->tipo).($archivo->reemplazado_at !== null ? '-reemplazado' : ''));
    }

    private function nombre(Documento $documento, TipoDocumento $tipo): string
    {
        return implode('-', array_filter([$documento->embarque_codigo, $tipo->codigo, $documento->numero]));
    }
}

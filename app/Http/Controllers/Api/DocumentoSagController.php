<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentoSag;
use App\Services\InspeccionSag\DatosDocumentoSag;
use App\Services\InspeccionSag\GeneradorDocumentoSag;
use App\Services\InspeccionSag\ServicioDocumentosSag;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DocumentoSagController extends Controller
{
    public function index(Request $request)
    {
        $datos = $request->validate([
            'tipo' => ['nullable', Rule::in(array_keys(ServicioDocumentosSag::FORMATOS))],
            'estado' => ['nullable', Rule::in(['borrador', 'emitido', 'enviado', 'aprobado', 'rechazado', 'cancelado'])],
            'desde' => ['nullable', 'date'], 'hasta' => ['nullable', 'date'],
            'temporada_id' => ['nullable', 'uuid', 'exists:temporadas,id'],
        ]);
        $q = DocumentoSag::where('temporada_id', $datos['temporada_id'] ?? app(ServicioTemporadaActiva::class)->buscar()?->id);
        foreach (['tipo', 'estado'] as $campo) {
            if (filled($datos[$campo] ?? null)) {
                $q->where($campo, $datos[$campo]);
            }
        }
        if (filled($datos['desde'] ?? null)) {
            $q->where('fecha_actividad', '>=', $datos['desde']);
        }
        if (filled($datos['hasta'] ?? null)) {
            $q->where('fecha_actividad', '<=', $datos['hasta']);
        }

        return response()->json($q->orderByDesc('fecha_actividad')->orderByDesc('id')->paginate(20));
    }

    public function show(DocumentoSag $documento)
    {
        return response()->json([
            ...$documento->toArray(),
            'historial' => DB::table('eventos_contraparte_sag')->where('referencia', $documento->id)->orderBy('created_at')->orderBy('id')->get()
                ->map(fn ($e) => [...(array) $e, 'datos' => json_decode($e->datos, true)]),
            'archivos' => DB::table('archivos_documento_sag')->where('documento_id', $documento->id)->orderBy('created_at')->get(['id', 'nombre', 'mime', 'created_at']),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function fuente(string $tipo, string $referencia, DatosDocumentoSag $datos)
    {
        abort_unless($tipo === 'repaletizaje', 404);

        return response()->json($datos->repaletizaje($referencia));
    }

    public function folio(string $numero, DatosDocumentoSag $datos)
    {
        return response()->json($datos->folio($numero));
    }

    public function store(Request $request, ServicioDocumentosSag $servicio)
    {
        return response()->json($servicio->guardar($this->validar($request), $request->user()), 201);
    }

    public function update(DocumentoSag $documento, Request $request, ServicioDocumentosSag $servicio)
    {
        return response()->json($servicio->guardar($this->validar($request), $request->user(), $documento));
    }

    public function actuar(DocumentoSag $documento, string $accion, Request $request, ServicioDocumentosSag $servicio)
    {
        $datos = $request->validate([
            'operacion_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1'],
            'referencia' => ['nullable', 'string', 'max:1000'], 'resuelto_por' => ['nullable', 'string', 'max:250'],
        ]);

        return response()->json($servicio->actuar($documento, $accion, $datos, $request->user()));
    }

    public function blanco(string $tipo, string $formato, GeneradorDocumentoSag $generador)
    {
        abort_unless(array_key_exists($tipo, ServicioDocumentosSag::FORMATOS), 404);

        return $this->descarga(null, $tipo, $formato, $generador);
    }

    public function archivo(DocumentoSag $documento, string $formato, GeneradorDocumentoSag $generador)
    {
        return $this->descarga($documento, $documento->tipo, $formato, $generador);
    }

    private function descarga(?DocumentoSag $documento, string $tipo, string $formato, GeneradorDocumentoSag $generador)
    {
        abort_unless(in_array($formato, ['pdf', 'xlsx'], true), 404);
        $nombre = 'sag-'.$tipo.'-'.($documento?->numero ?? ($documento ? 'borrador' : 'blanco')).'.'.$formato;
        if ($formato === 'xlsx') {
            return response()->download($generador->xlsx($documento, $tipo), $nombre,
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
        }

        return response($generador->pdf($documento, $tipo))->withHeaders([
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$nombre.'"', 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function adjuntar(DocumentoSag $documento, Request $request)
    {
        $request->validate(['archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240']]);
        $archivo = $request->file('archivo');
        $hash = hash_file('sha256', $archivo->getRealPath());
        $anterior = DB::table('archivos_documento_sag')->where('documento_id', $documento->id)->where('sha256', $hash)->first();
        if ($anterior) {
            return response()->json(['id' => $anterior->id, 'nombre' => $anterior->nombre]);
        }
        $id = (string) Str::uuid();
        $ruta = $archivo->storeAs('sag/documentos/'.$documento->id, $id.'.'.$archivo->extension(), 'local');
        try {
            DB::transaction(function () use ($documento, $archivo, $request, $hash, $id, $ruta): void {
                $temporada = app(ServicioTemporadaActiva::class)->obtener(bloquear: true);
                if ($documento->temporada_id !== $temporada->id) {
                    abort(409, 'El documento no pertenece a la temporada activa.');
                }
                DB::table('archivos_documento_sag')->insert([
                    'id' => $id, 'documento_id' => $documento->id, 'nombre' => mb_substr(basename($archivo->getClientOriginalName()), 0, 200),
                    'ruta' => $ruta, 'mime' => $archivo->getMimeType(), 'sha256' => $hash, 'user_id' => $request->user()->id, 'created_at' => now(),
                ]);
                DB::table('eventos_contraparte_sag')->insert([
                    'id' => (string) Str::uuid(), 'tipo' => 'documento.archivo', 'referencia' => $documento->id, 'user_id' => $request->user()->id,
                    'datos' => json_encode(['archivo_id' => $id, 'sha256' => $hash], JSON_THROW_ON_ERROR), 'created_at' => now(),
                ]);
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($ruta);
            throw $error;
        }

        return response()->json(['id' => $id], 201);
    }

    public function respaldo(DocumentoSag $documento, string $archivo)
    {
        $registro = DB::table('archivos_documento_sag')->where('id', $archivo)->where('documento_id', $documento->id)->first();
        abort_unless($registro && Storage::disk('local')->exists($registro->ruta), 404);

        return Storage::disk('local')->download($registro->ruta, $registro->nombre, ['Content-Type' => $registro->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'operacion_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'],
            'tipo' => ['required', Rule::in(array_keys(ServicioDocumentosSag::FORMATOS))],
            'responsable_id' => ['required', 'uuid', 'exists:responsables_contraparte_sag,id'],
            'fecha_actividad' => ['required', 'date'],
            'repaletizaje_id' => ['required_if:tipo,repaletizaje', 'nullable', 'uuid'],
            'encabezado' => ['present', 'array:observaciones,solicitud,certificados,certificados_devueltos,certificados_asignados,nota'],
            'encabezado.observaciones' => ['nullable', 'string', 'max:2000'],
            'encabezado.solicitud' => ['nullable', 'string', 'max:250'], 'encabezado.nota' => ['nullable', 'string', 'max:500'],
            'encabezado.certificados' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'encabezado.certificados_devueltos' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'encabezado.certificados_asignados' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'folios' => ['present_if:tipo,anulacion', 'array', 'max:500'],
            'folios.*.folio_id' => ['required', 'uuid', 'distinct'],
            'folios.*.cantidad' => ['required', 'integer', 'min:1', 'max:100000'],
            'folios.*.causal' => ['required', 'string', 'max:250'],
            'folios.*.tipo_actividad' => ['nullable', 'string', 'max:100'],
            'folios.*.numero_actividad' => ['nullable', 'string', 'max:100'],
            'actividades' => ['nullable', 'array', 'max:500'],
            'actividades.*.tipo' => ['nullable', 'string', 'max:100'],
            'actividades.*.numero' => ['nullable', 'string', 'max:100'],
        ]);
    }
}

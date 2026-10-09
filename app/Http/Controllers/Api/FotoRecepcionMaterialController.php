<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoRecepcionMaterial;
use App\Http\Controllers\Controller;
use App\Models\EliminacionRecepcionMaterial;
use App\Models\FotoRecepcionMaterial;
use App\Models\RecepcionMaterial;
use App\Services\Materiales\ServicioFotosRecepcionMaterial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class FotoRecepcionMaterialController extends Controller
{
    public function index(Request $request, RecepcionMaterial $recepcionMaterial)
    {
        $this->autorizarRecepcion($request, $recepcionMaterial);

        return response()->json(['data' => $recepcionMaterial->fotos()->orderBy('tipo')->orderBy('orden')->get()->map->representar()])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, RecepcionMaterial $recepcionMaterial, ServicioFotosRecepcionMaterial $servicio)
    {
        $this->autorizarRecepcion($request, $recepcionMaterial);
        $datos = $request->validate(['archivo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'tipo' => ['required', 'in:documento,referencial'], 'operacion_id' => ['required', 'uuid']]);
        [$foto, $nueva] = $servicio->subir($recepcionMaterial, $request->file('archivo'), $datos['tipo'], $datos['operacion_id'], $request->user());

        return response()->json(['data' => $foto->representar()], $nueva ? 201 : 200)->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request, RecepcionMaterial $recepcionMaterial, FotoRecepcionMaterial $foto, ServicioFotosRecepcionMaterial $servicio)
    {
        $this->autorizarRecepcion($request, $recepcionMaterial);
        abort_unless($foto->recepcion_material_id === $recepcionMaterial->id, 404);
        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:2000']]);
        $servicio->eliminar($recepcionMaterial, $foto, $request->user(), $datos['motivo'] ?? null);

        return response()->noContent();
    }

    public function archivo(Request $request, RecepcionMaterial $recepcionMaterial, FotoRecepcionMaterial $foto, string $variante)
    {
        $this->autorizarRecepcion($request, $recepcionMaterial);
        abort_unless($foto->recepcion_material_id === $recepcionMaterial->id && in_array($variante, ['original', 'miniatura'], true), 404);

        return $this->servir($variante === 'miniatura' ? $foto->ruta_miniatura : $foto->ruta_original, $variante === 'miniatura' ? 'image/jpeg' : $foto->mime);
    }

    public function zip(Request $request, RecepcionMaterial $recepcionMaterial)
    {
        $this->autorizarRecepcion($request, $recepcionMaterial);
        $fotos = $recepcionMaterial->fotos()->orderBy('tipo')->orderBy('orden')->get();
        abort_if($fotos->isEmpty(), 404);
        $ruta = tempnam(sys_get_temp_dir(), 'recepcion-fotos-');
        $zip = new ZipArchive;
        $abierto = false;
        try {
            if ($zip->open($ruta, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No se pudo crear el ZIP.');
            }
            $abierto = true;
            $guia = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $recepcionMaterial->numero_guia_despacho) ?: $recepcionMaterial->id;
            foreach ($fotos as $foto) {
                abort_unless(Storage::disk('local')->exists($foto->ruta_original), 404);
                $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$foto->mime];
                if (! $zip->addFile(Storage::disk('local')->path($foto->ruta_original), $guia.'_'.$foto->tipo.'_'.$foto->orden.'.'.$ext)) {
                    throw new RuntimeException('No se pudo agregar la evidencia al ZIP.');
                }
            }
            $abierto = false;
            if (! $zip->close()) {
                throw new RuntimeException('No se pudo finalizar el ZIP.');
            }
        } catch (\Throwable $error) {
            if ($abierto) {
                $zip->close();
            }
            if (is_file($ruta)) {
                @unlink($ruta);
            }
            throw $error;
        }
        $respuesta = response()->download($ruta, $guia.'_fotos.zip', ['Content-Type' => 'application/zip', 'X-Content-Type-Options' => 'nosniff'])->deleteFileAfterSend();
        $respuesta->setPrivate();
        $respuesta->headers->set('Cache-Control', 'no-store, private');

        return $respuesta;
    }

    public function eliminaciones(Request $request)
    {
        $registros = EliminacionRecepcionMaterial::query()
            ->when($request->query('guia'), fn ($q, $guia) => $q->where('numero_guia_despacho', 'like', '%'.trim($guia).'%'))
            ->latest('eliminado_at')->paginate(25);
        $registros->through(fn ($eliminacion) => [
            'id' => $eliminacion->id, 'numero_guia_despacho' => $eliminacion->numero_guia_despacho,
            'motivo' => $eliminacion->motivo, 'eliminado_at' => $eliminacion->eliminado_at?->toAtomString(),
            'fotos' => collect($eliminacion->snapshot['fotos'] ?? [])->map(fn ($foto, $indice) => [
                'tipo' => $foto['tipo'], 'orden' => $foto['orden'], 'sha256' => $foto['sha256'], 'bytes' => $foto['bytes'],
                'url' => '/api/materiales/recepciones/eliminaciones/'.$eliminacion->id.'/fotos/'.$indice,
            ])->values()->all(),
        ]);

        return response()->json($registros)->header('Cache-Control', 'no-store, private');
    }

    public function eliminada(EliminacionRecepcionMaterial $eliminacion, string $indice)
    {
        abort_unless(ctype_digit($indice), 404);
        $foto = $eliminacion->snapshot['fotos'][(int) $indice] ?? null;
        abort_unless($foto, 404);
        // Si falló el traslado posterior al commit, la evidencia sigue disponible en su ruta original.
        $ruta = Storage::disk('local')->exists($foto['ruta']) ? $foto['ruta'] : $foto['ruta_origen'];

        return $this->servir($ruta, $foto['mime']);
    }

    private function servir(string $ruta, string $mime)
    {
        abort_unless(Storage::disk('local')->exists($ruta), 404);
        $respuesta = response()->file(Storage::disk('local')->path($ruta), ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff']);
        $respuesta->setPrivate();
        $respuesta->headers->set('Cache-Control', 'no-store, private');

        return $respuesta;
    }

    private function autorizarRecepcion(Request $request, RecepcionMaterial $recepcion): void
    {
        abort_unless($recepcion->estado === EstadoRecepcionMaterial::Confirmada
            || $request->user()->can('anular-recepciones-materiales')
            || ($request->user()->can('gestionar-recepciones-materiales') && $recepcion->creado_por_user_id === $request->user()->id), 404);
    }
}

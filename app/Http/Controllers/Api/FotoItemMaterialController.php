<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FotoItemMaterial;
use App\Models\ItemMaterial;
use App\Services\Materiales\ServicioFotosItemMaterial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FotoItemMaterialController extends Controller
{
    public function index(ItemMaterial $item)
    {
        return response()->json(['data' => $item->fotos()->orderBy('orden')->orderBy('id')->get()->map->representar(), 'version' => (int) $item->fotos_version])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $r, ItemMaterial $item, ServicioFotosItemMaterial $servicio)
    {
        $datos = $r->validate(['fotografias' => ['required', 'array', 'min:1', 'max:10'],
            'fotografias.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'version_conocida' => ['nullable', 'integer', 'min:0']]);
        $servicio->subir($item, $r->file('fotografias'), $r->user(), $datos['version_conocida'] ?? null);

        return $this->index($item->refresh())->setStatusCode(201);
    }

    public function update(Request $r, ItemMaterial $item, ServicioFotosItemMaterial $servicio)
    {
        $datos = $r->validate(['orden' => ['required', 'array', 'min:1', 'max:10'], 'orden.*' => ['required', 'uuid', 'distinct'],
            'principal_id' => ['required', 'uuid'], 'version_conocida' => ['nullable', 'integer', 'min:0']]);
        $servicio->ordenar($item, $datos['orden'], $datos['principal_id'], $r->user(), $datos['version_conocida'] ?? null);

        return $this->index($item->refresh());
    }

    public function destroy(Request $r, ItemMaterial $item, FotoItemMaterial $foto, ServicioFotosItemMaterial $servicio)
    {
        abort_unless($foto->item_material_id === $item->id, 404);
        $datos = $r->validate(['version_conocida' => ['nullable', 'integer', 'min:0']]);
        $servicio->eliminar($item, $foto, $r->user(), $datos['version_conocida'] ?? null);

        return $this->index($item->refresh());
    }

    public function archivo(FotoItemMaterial $foto, string $variante)
    {
        abort_unless(in_array($variante, ['archivo', 'miniatura'], true), 404);
        $ruta = $variante === 'miniatura' ? $foto->ruta_miniatura : $foto->ruta;
        abort_unless(Storage::disk('local')->exists($ruta), 404);
        $respuesta = response()->file(Storage::disk('local')->path($ruta), ['Content-Type' => $variante === 'miniatura' ? 'image/jpeg' : $foto->mime, 'X-Content-Type-Options' => 'nosniff']);
        $respuesta->setPrivate();
        $respuesta->headers->set('Cache-Control', 'no-store, private');

        return $respuesta;
    }
}

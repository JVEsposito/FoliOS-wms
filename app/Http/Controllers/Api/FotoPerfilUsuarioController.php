<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Autorizacion\ServicioFotoPerfilUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FotoPerfilUsuarioController extends Controller
{
    public function perfil(Request $request): JsonResponse
    {
        return $this->estado($this->usuario($request));
    }

    public function show(Request $request): BinaryFileResponse
    {
        $ruta = $this->usuario($request)->foto_perfil_ruta;
        abort_unless($ruta && Storage::disk('local')->exists($ruta), 404);
        $respuesta = response()->file(Storage::disk('local')->path($ruta), [
            'Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff',
        ]);
        $respuesta->setPrivate();
        $respuesta->headers->set('Cache-Control', 'no-store, private');

        return $respuesta;
    }

    public function store(Request $request, ServicioFotoPerfilUsuario $servicio): JsonResponse
    {
        $usuario = $this->usuario($request);
        $request->validate(['foto' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']], [
            'foto.required' => 'Selecciona una foto.',
            'foto.image' => 'Selecciona una imagen JPG, PNG o WebP válida.',
            'foto.mimes' => 'Solo se admiten imágenes JPG, PNG o WebP.',
            'foto.max' => 'La foto debe pesar como máximo 5 MB.',
        ]);

        return $this->estado($servicio->guardar($usuario, $request->file('foto')));
    }

    public function destroy(Request $request, ServicioFotoPerfilUsuario $servicio): JsonResponse
    {
        return $this->estado($servicio->quitar($this->usuario($request)));
    }

    private function usuario(Request $request): User
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User, 403);
        $usuario = $usuario->fresh();
        abort_unless($usuario?->activo, 403);

        return $usuario;
    }

    private function estado(User $usuario): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $usuario->id,
            'nombre' => $usuario->name,
            'foto_perfil_version' => $usuario->foto_perfil_ruta ? pathinfo($usuario->foto_perfil_ruta, PATHINFO_FILENAME) : null,
        ]])->header('Cache-Control', 'no-store, private');
    }
}

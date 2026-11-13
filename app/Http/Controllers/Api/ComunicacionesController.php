<?php

namespace App\Http\Controllers\Api;

use App\Enums\RolUsuario;
use App\Http\Controllers\Controller;
use App\Models\StickerChat;
use App\Models\User;
use App\Services\Comunicaciones\ReferenciasComunicaciones;
use App\Services\Comunicaciones\ServicioChatInterno;
use App\Services\Comunicaciones\ServicioCorreoInterno;
use App\Services\Comunicaciones\ServicioStickersChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ComunicacionesController extends Controller
{
    public function resumen(Request $request, ServicioChatInterno $chat): JsonResponse
    {
        $usuario = $request->user();
        $nuevos = $chat->conversaciones($usuario)->whereHas('mensajes', fn ($q) => $chat->sinLeer($q, $usuario))->exists();
        $menciones = $chat->conversaciones($usuario)
            ->join('com_mensajes', 'com_mensajes.conversacion_id', '=', 'com_conversaciones.id')
            ->where(fn ($q) => $chat->mencionesSinLeer($chat->sinLeer($q, $usuario), $usuario))
            ->count('com_mensajes.id');
        $correos = DB::table('com_destinatarios')->where('user_id', $usuario->id)->whereNull('leido_at')->whereNull('archivado_at')->count();

        return $this->json(['data' => ['chat_no_leido' => $nuevos, 'menciones_sin_leer' => $menciones, 'correos_no_leidos' => $correos]]);
    }

    public function usuarios(Request $request): JsonResponse
    {
        $datos = $request->validate(['buscar' => ['nullable', 'string', 'max:100'], 'pagina' => ['nullable', 'integer', 'min:1', 'max:10000']]);
        $consulta = User::where('activo', true)->where('rol', '!=', RolUsuario::Cliente->value)->where('id', '!=', $request->user()->id);
        if ($buscar = trim($datos['buscar'] ?? '')) {
            $consulta->where('name', 'like', '%'.addcslashes(ltrim($buscar, '@'), '%_\\').'%');
        }
        $lista = $consulta->orderBy('name')->orderBy('id')->paginate(50, ['id', 'name'], 'pagina', $datos['pagina'] ?? 1);

        return $this->json(['data' => $lista->getCollection()->map(fn ($u) => ['id' => $u->id, 'nombre' => $u->name])->all(),
            'pagina' => $lista->currentPage(), 'ultima_pagina' => $lista->lastPage()]);
    }

    public function conversaciones(Request $request, ServicioChatInterno $chat): JsonResponse
    {
        $datos = $request->validate(['pagina' => ['nullable', 'integer', 'min:1', 'max:10000']]);

        return $this->json($chat->listar($request->user(), $datos['pagina'] ?? 1));
    }

    public function privado(Request $request, ServicioChatInterno $chat): JsonResponse
    {
        $datos = $request->validate(['usuario_id' => ['required', 'integer', 'min:1']]);
        $conversacion = $chat->abrirPrivado($request->user(), (int) $datos['usuario_id']);

        return $this->json(['data' => $chat->representarConversacion($conversacion, $request->user())]);
    }

    public function mensajes(Request $request, int $conversacion, ServicioChatInterno $chat): JsonResponse
    {
        $datos = $request->validate(['despues_id' => ['nullable', 'integer', 'min:0'], 'antes_id' => ['nullable', 'integer', 'min:1'], 'limite' => ['nullable', 'integer', 'min:1', 'max:100']]);
        abort_if(isset($datos['despues_id'], $datos['antes_id']), 422, 'Consulta mensajes nuevos o anteriores, no ambos a la vez.');

        return $this->json($chat->mensajes($request->user(), $conversacion, isset($datos['despues_id']) ? (int) $datos['despues_id'] : null,
            isset($datos['antes_id']) ? (int) $datos['antes_id'] : null, (int) ($datos['limite'] ?? 50)));
    }

    public function enviarMensaje(Request $request, int $conversacion, ServicioChatInterno $chat): JsonResponse
    {
        $datos = $request->validate(['texto' => ['nullable', 'string', 'max:4000'], 'sticker_id' => ['nullable', 'integer', 'min:1'], 'operacion_id' => ['required', 'uuid']]);
        $mensaje = $chat->enviar($request->user(), $conversacion, $datos);

        return $this->json(['data' => $chat->representarMensaje($mensaje, $request->user())]);
    }

    public function leerChat(Request $request, int $conversacion, ServicioChatInterno $chat): JsonResponse
    {
        $datos = $request->validate(['hasta_id' => ['required', 'integer', 'min:0']]);
        $chat->leer($request->user(), $conversacion, (int) $datos['hasta_id']);

        return $this->json(['data' => ['confirmado' => true]]);
    }

    /** Registros que el usuario puede citar con «#», según sus permisos de cada módulo. */
    public function referencias(Request $request, ReferenciasComunicaciones $referencias): JsonResponse
    {
        $tipos = $referencias->tiposDisponibles($request->user());
        $datos = $request->validate(['buscar' => ['nullable', 'string', 'max:60'],
            'tipo' => ['nullable', 'string', Rule::in(array_column($tipos, 'clave'))]]);

        return $this->json(['data' => $referencias->buscar($request->user(), (string) ($datos['buscar'] ?? ''), $datos['tipo'] ?? null), 'tipos' => $tipos]);
    }

    public function fichaReferencia(Request $request, string $tipo, string $id, ReferenciasComunicaciones $referencias): JsonResponse
    {
        return $this->json(['data' => $referencias->ficha($request->user(), $tipo, $id)]);
    }

    public function stickers(Request $request): JsonResponse
    {
        $datos = $request->validate(['buscar' => ['nullable', 'string', 'max:80'], 'pagina' => ['nullable', 'integer', 'min:1', 'max:10000'], 'mios' => ['nullable', 'boolean']]);
        $consulta = StickerChat::where('activo', true);
        if ($datos['mios'] ?? false) {
            $consulta->where('user_id', $request->user()->id);
        }
        if ($buscar = trim($datos['buscar'] ?? '')) {
            $consulta->where('nombre', 'like', '%'.addcslashes($buscar, '%_\\').'%');
        }
        $lista = $consulta->orderByDesc('id')->paginate(30, ['*'], 'pagina', $datos['pagina'] ?? 1);

        return $this->json(['data' => $lista->getCollection()->map(fn ($s) => ServicioStickersChat::representar($s, $request->user()))->all(),
            'pagina' => $lista->currentPage(), 'ultima_pagina' => $lista->lastPage()]);
    }

    public function subirSticker(Request $request, ServicioStickersChat $stickers): JsonResponse
    {
        $datos = $request->validate(['nombre' => ['required', 'string', 'max:80'], 'operacion_id' => ['required', 'uuid'],
            'archivo' => ['required', 'file', 'image', 'mimes:png,webp,gif', 'max:2048', Rule::dimensions()->maxWidth(1024)->maxHeight(1024)]], [
                'archivo.image' => 'Selecciona una imagen PNG, WebP o GIF válida.', 'archivo.mimes' => 'Solo se admiten stickers PNG, WebP o GIF.',
                'archivo.max' => 'El sticker debe pesar como máximo 2 MB.', 'archivo.dimensions' => 'El sticker puede medir hasta 1024 × 1024 píxeles.',
            ]);
        $sticker = $stickers->subir($request->user(), $request->file('archivo'), $datos['nombre'], $datos['operacion_id']);

        return $this->json(['data' => ServicioStickersChat::representar($sticker, $request->user())]);
    }

    public function ocultarSticker(Request $request, int $sticker): JsonResponse
    {
        StickerChat::whereKey($sticker)->where('user_id', $request->user()->id)->firstOrFail()->update(['activo' => false]);

        return $this->json(['data' => ['confirmado' => true]]);
    }

    public function archivoSticker(int $sticker): BinaryFileResponse
    {
        $foto = StickerChat::findOrFail($sticker);
        abort_unless(Storage::disk('local')->exists($foto->ruta), 404);
        $respuesta = response()->file(Storage::disk('local')->path($foto->ruta), ['Content-Type' => $foto->mime,
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
        $respuesta->setPrivate();
        $respuesta->headers->set('Cache-Control', 'no-store, private');

        return $respuesta;
    }

    public function correos(Request $request, ServicioCorreoInterno $correo): JsonResponse
    {
        $datos = $request->validate(['bandeja' => ['nullable', Rule::in(['recibidos', 'enviados', 'archivados'])],
            'buscar' => ['nullable', 'string', 'max:100'], 'pagina' => ['nullable', 'integer', 'min:1', 'max:10000']]);

        return $this->json($correo->listar($request->user(), $datos['bandeja'] ?? 'recibidos', trim($datos['buscar'] ?? ''), (int) ($datos['pagina'] ?? 1)));
    }

    public function verCorreo(Request $request, int $mensaje, ServicioCorreoInterno $correo): JsonResponse
    {
        return $this->json(['data' => $correo->representar($correo->obtener($request->user(), $mensaje), $request->user())]);
    }

    public function enviarCorreo(Request $request, ServicioCorreoInterno $correo): JsonResponse
    {
        $datos = $request->validate(['destinatarios' => ['required', 'array', 'min:1', 'max:50'], 'destinatarios.*' => ['required', 'integer', 'min:1', 'distinct'],
            'asunto' => ['required', 'string', 'max:160'], 'texto' => ['required', 'string', 'max:10000'],
            'operacion_id' => ['required', 'uuid'], 'respuesta_a_id' => ['nullable', 'integer', 'min:1']]);
        $mensaje = $correo->enviar($request->user(), $datos);

        return $this->json(['data' => $correo->representar($mensaje, $request->user())]);
    }

    public function leerCorreo(Request $request, int $mensaje, ServicioCorreoInterno $correo): JsonResponse
    {
        $datos = $request->validate(['leido' => ['required', 'boolean']]);
        $correo->lectura($request->user(), $mensaje, $datos['leido']);

        return $this->json(['data' => ['confirmado' => true]]);
    }

    public function archivarCorreo(Request $request, int $mensaje, ServicioCorreoInterno $correo): JsonResponse
    {
        $datos = $request->validate(['archivado' => ['required', 'boolean']]);
        $correo->archivar($request->user(), $mensaje, $datos['archivado']);

        return $this->json(['data' => ['confirmado' => true]]);
    }

    private function json(array $datos): JsonResponse
    {
        return response()->json($datos)->header('Cache-Control', 'no-store, private');
    }
}

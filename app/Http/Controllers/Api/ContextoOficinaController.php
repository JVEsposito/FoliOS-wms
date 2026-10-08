<?php

namespace App\Http\Controllers\Api;

use App\Enums\TipoNotificacionOperacional;
use App\Http\Controllers\Controller;
use App\Models\NotificacionOperacional;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Notificaciones\ServicioNotificacionesOperacionales;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContextoOficinaController extends Controller
{
    public function __invoke(
        Request $request,
        AlcanceOperacionalUsuario $alcance,
        ServicioTemporadaActiva $temporadas,
        ServicioNotificacionesOperacionales $notificaciones,
    ): JsonResponse {
        abort_unless($alcance->puedeAccederOficina($request->user()), 403);
        $temporada = $temporadas->buscar();

        return response()->json(['data' => [
            'planta' => config('oficina.planta') ?: null,
            'temporada' => $temporada ? [
                'id' => $temporada->id,
                'codigo' => $temporada->codigo,
                'nombre' => $temporada->nombre,
            ] : null,
            'avisos_materiales' => $notificaciones->consultaVisibles($request->user())->where('tipo', TipoNotificacionOperacional::ReposicionMaterial->value)
                ->whereDoesntHave('lecturas', fn ($l) => $l->where('user_id', $request->user()->id)->whereNotNull('leida_at'))
                ->latest()->limit(10)->get(['id', 'titulo', 'mensaje', 'created_at'])->toArray(),
            // Avisos de cierre de temporada sin leer, dirigidos a este usuario.
            'avisos_cierre' => $notificaciones->consultaVisibles($request->user())
                ->where('tipo', TipoNotificacionOperacional::CierreTemporadaPendiente->value)
                ->whereDoesntHave('lecturas', fn ($lectura) => $lectura
                    ->where('user_id', $request->user()->id)
                    ->whereNotNull('leida_at'))
                ->latest()
                ->limit(3)
                ->get(['id', 'titulo', 'mensaje', 'created_at'])
                ->map(fn (NotificacionOperacional $aviso): array => [
                    'id' => $aviso->id,
                    'titulo' => $aviso->titulo,
                    'mensaje' => $aviso->mensaje,
                    'created_at' => $aviso->created_at?->toAtomString(),
                ])
                ->all(),
        ]])->header('Cache-Control', 'no-store, private');
    }
}

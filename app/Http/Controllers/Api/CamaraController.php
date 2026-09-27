<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoCamara;
use App\Enums\EstadoPosicion;
use App\Http\Controllers\Controller;
use App\Http\Resources\CamaraPlanoResource;
use App\Http\Resources\CamaraResumenResource;
use App\Models\Camara;
use App\Models\PersonalAccessToken;
use App\Models\Temporada;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Camaras\ServicioBandasOperacionales;
use App\Services\Estiba\ServicioReservasTareasMovimiento;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CamaraController extends Controller
{
    public function index(
        Request $request,
        AlcanceOperacionalUsuario $alcance,
    ): Response {
        $contenidos = collect($alcance->contenidosVisibles($request->user()))
            ->map->value
            ->all();
        $camaras = Camara::query()
            ->where('estado', EstadoCamara::Activa->value)
            ->whereIn('contenido', $contenidos)
            ->with($this->relacionesBloqueo())
            ->orderBy('codigo')
            ->get();
        $etag = $this->etagListado($request, $camaras);
        $respuestaCondicional = $this->configurarCache(response('', 200), $etag);

        if ($respuestaCondicional->isNotModified($request)) {
            return $respuestaCondicional;
        }

        $camaras->loadCount([
            'posiciones' => fn ($consulta) => $consulta
                ->where('estado', EstadoPosicion::Activa->value),
            'posiciones as posiciones_ocupadas_count' => fn ($consulta) => $consulta
                ->where('estado', EstadoPosicion::Activa->value)
                ->whereHas('ubicacionesActuales'),
            'ubicacionesSinPosicion',
        ]);

        return $this->configurarCache(
            CamaraResumenResource::collection($camaras)->response(),
            $etag,
        );
    }

    public function plano(
        Request $request,
        Camara $camara,
        AlcanceOperacionalUsuario $alcance,
        ServicioBandasOperacionales $bandasOperacionales,
        ServicioReservasTareasMovimiento $reservasTareas,
    ): Response {
        abort_unless($camara->estado === EstadoCamara::Activa, 404);
        abort_unless($alcance->puedeVerCamara($request->user(), $camara), 403);
        if ($reservasTareas->expirarVencidas() > 0) {
            $camara->refresh();
        }

        $camara->load('bloqueo.sesionEstiba');
        $etag = $this->etagPlano($request, $camara);
        $respuestaCondicional = $this->configurarCache(response('', 200), $etag);

        if ($respuestaCondicional->isNotModified($request)) {
            return $respuestaCondicional;
        }

        $camara->loadCount([
            'posiciones' => fn ($consulta) => $consulta
                ->where('estado', EstadoPosicion::Activa->value),
        ]);
        $camara->loadCount([
            'posiciones as posiciones_ocupadas_count' => fn ($consulta) => $consulta
                ->where('estado', EstadoPosicion::Activa->value)
                ->whereHas('ubicacionesActuales'),
        ]);
        $camara->loadCount('ubicacionesSinPosicion');
        $camara->loadMissing($this->relacionesBloqueo());
        $camara->load([
            'bandasOperacionales' => fn ($consulta) => $consulta
                ->where('numero', '<=', $camara->cantidad_bandas)
                ->with('actualizadoPor:id,name'),
            'ubicacionesSinPosicion' => fn ($consulta) => $consulta
                ->with([
                    'folio.condicionSag',
                    'folio.temporada:id,codigo,tipo',
                    'folio.material.item.cliente.temporada',
                    'folio.asignacionCargaActual.carga',
                ])
                ->orderBy('ubicado_at')
                ->orderBy('folio_id'),
            'posiciones' => fn ($consulta) => $consulta
                ->with([
                    'ubicacionesActuales.folio.condicionSag',
                    'ubicacionesActuales.folio.temporada:id,codigo,tipo',
                    'ubicacionesActuales.folio.material.item.cliente.temporada',
                    'ubicacionesActuales.folio.asignacionCargaActual.carga',
                    'reservaTareaActiva' => fn ($reservas) => $reservas
                        ->where('vence_at', '>', now())
                        ->with([
                            'tareaMovimiento:id,prioridad',
                            'usuario:id,name',
                            'dispositivo:id,nombre',
                        ]),
                ])
                ->where('banda', '<=', $camara->cantidad_bandas)
                ->where('posicion', '<=', $camara->posiciones_por_banda)
                ->where('nivel', '<=', $camara->cantidad_niveles)
                ->orderBy('banda')
                ->orderBy('nivel')
                ->orderBy('posicion'),
        ]);
        $bandasOperacionales->enriquecer($camara);

        return $this->configurarCache(
            (new CamaraPlanoResource($camara))->response(),
            $etag,
        );
    }

    /** @param Collection<int, Camara> $camaras */
    private function etagListado(Request $request, Collection $camaras): string
    {
        $token = $request->user()?->currentAccessToken();
        $dispositivoId = $token instanceof PersonalAccessToken
            ? $token->dispositivo_id
            : null;
        $huella = json_encode([
            'usuario_id' => $request->user()?->getAuthIdentifier(),
            'dispositivo_id' => $dispositivoId,
            'camaras' => $camaras->map(function (Camara $camara): array {
                $bloqueo = $camara->bloqueo;
                $sesion = $bloqueo?->sesionEstiba;

                return [
                    'id' => $camara->id,
                    'codigo' => $camara->codigo,
                    'nombre' => $camara->nombre,
                    'tipo' => $camara->tipo,
                    'contenido' => $camara->contenido->value,
                    'estado' => $camara->estado->value,
                    'version_plano' => $camara->version_plano,
                    'actualizada_at' => $camara->updated_at?->toAtomString(),
                    'bloqueo_id' => $bloqueo?->getKey(),
                    'bloqueo_adquirido_at' => $bloqueo?->adquirido_at?->toAtomString(),
                    'sesion_id' => $sesion?->id,
                    'sesion_usuario_id' => $sesion?->user_id,
                    'sesion_usuario_nombre' => $sesion?->usuario?->name,
                    'sesion_dispositivo_id' => $sesion?->dispositivo_id,
                    'sesion_dispositivo_nombre' => $sesion?->dispositivo?->nombre,
                    'sesion_estado' => $sesion?->estado?->value,
                    'sesion_iniciada_at' => $sesion?->iniciada_at?->toAtomString(),
                    'sesion_ultima_actividad_at' => $sesion?->ultima_actividad_at?->toAtomString(),
                ];
            })->values()->all(),
        ], JSON_THROW_ON_ERROR);

        return 'camaras-'.hash('sha256', $huella);
    }

    private function etagPlano(Request $request, Camara $camara): string
    {
        $bloqueo = $camara->bloqueo;
        $sesion = $bloqueo?->sesionEstiba;
        $token = $request->user()?->currentAccessToken();
        $dispositivoId = $token instanceof PersonalAccessToken
            ? $token->dispositivo_id
            : null;
        $huella = json_encode([
            'version_perfiles_afinidad' => 2,
            'camara_id' => $camara->id,
            'camara_actualizada_at' => $camara->updated_at?->toAtomString(),
            'version_plano' => $camara->version_plano,
            'usuario_id' => $request->user()?->getAuthIdentifier(),
            'dispositivo_id' => $dispositivoId,
            'bloqueo_id' => $bloqueo?->getKey(),
            'bloqueo_adquirido_at' => $bloqueo?->adquirido_at?->toAtomString(),
            'sesion_id' => $sesion?->id,
            'sesion_usuario_id' => $sesion?->user_id,
            'sesion_dispositivo_id' => $sesion?->dispositivo_id,
            'sesion_estado' => $sesion?->estado?->value,
            'sesion_ultima_actividad_at' => $sesion?->ultima_actividad_at?->toAtomString(),
            'revision_reservas' => $camara->revision_reservas,
            // Al cambiar la temporada activa cambia qué folios son registros sin cerrar.
            'temporada_activa_id' => Temporada::query()->where('activa', true)->value('id'),
        ], JSON_THROW_ON_ERROR);

        return 'plano-'.hash('sha256', $huella);
    }

    private function configurarCache(Response $respuesta, string $etag): Response
    {
        $respuesta->setEtag($etag);
        $respuesta->setPrivate();
        $respuesta->headers->addCacheControlDirective('no-cache');
        $respuesta->setVary('Authorization');
        $respuesta->headers->set('Access-Control-Expose-Headers', 'ETag');

        return $respuesta;
    }

    /**
     * @return array<int, string>
     */
    private function relacionesBloqueo(): array
    {
        return [
            'bloqueo.sesionEstiba.usuario:id,name',
            'bloqueo.sesionEstiba.dispositivo:id,nombre',
        ];
    }
}

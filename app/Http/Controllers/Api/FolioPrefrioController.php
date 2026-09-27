<?php

namespace App\Http\Controllers\Api;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoProcesoPrefrio;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\TipoBulto;
use App\Http\Controllers\Concerns\RespondeConEtagOperacional;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConsultarFoliosPrefrioRequest;
use App\Http\Resources\FolioPrefrioResource;
use App\Models\Folio;
use App\Services\Prefrio\RevisionPrefrioOperacional;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\Response;

class FolioPrefrioController extends Controller
{
    use RespondeConEtagOperacional;

    public function index(
        ConsultarFoliosPrefrioRequest $request,
        RevisionPrefrioOperacional $revision,
    ): Response {
        $datos = $request->validated();
        $folio = mb_strtoupper(trim((string) ($datos['folio'] ?? '')));
        $limite = (int) ($datos['limit'] ?? 500);
        $etag = null;

        if ($folio === '') {
            $etag = 'prefrio-folios-'.$revision->foliosElegibles($limite);
            $respuestaCondicional = $this->conEtagOperacional(response('', 200), $etag);

            if ($respuestaCondicional->isNotModified($request)) {
                return $respuestaCondicional;
            }
        }

        $estadosActivos = collect(EstadoProcesoPrefrio::cases())
            ->filter->esActivo()
            ->map->value
            ->all();

        $consulta = Folio::query()
            ->where('activo', true)
            ->whereHas('temporada', fn ($consulta) => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->whereIn('tipo_bulto', [TipoBulto::Pallet->value, TipoBulto::Saldo->value])
            ->whereIn('condicion_termica', [
                CondicionTermicaFolio::PendientePrefrio->value,
                CondicionTermicaFolio::RequiereReproceso->value,
                CondicionTermicaFolio::Retenido->value,
            ])
            ->whereIn('habilitacion_almacenamiento', [
                HabilitacionAlmacenamientoFolio::NoHabilitado->value,
                HabilitacionAlmacenamientoFolio::Retenido->value,
            ])
            ->whereDoesntHave('ubicacionActual')
            ->whereDoesntHave('procesosPrefrio.proceso', fn ($consulta) => $consulta
                ->whereIn('estado', $estadosActivos))
            ->when($folio !== '', fn ($consulta) => $consulta
                ->where('numero_folio', 'like', "%{$folio}%"))
            ->orderBy('fecha_ingreso')
            ->orderBy('numero_folio');

        $respuesta = FolioPrefrioResource::collection(
            $this->obtenerFolios($consulta, $limite),
        )->response();

        return $etag === null
            ? $respuesta
            : $this->conEtagOperacional($respuesta, $etag);
    }

    private function obtenerFolios(Builder $consulta, int $limite): Collection
    {
        return (clone $consulta)->limit($limite)->get();
    }
}

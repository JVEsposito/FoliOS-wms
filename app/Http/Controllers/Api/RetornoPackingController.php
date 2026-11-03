<?php

namespace App\Http\Controllers\Api;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\TipoEnvaseRetornoPacking;
use App\Enums\TurnoProceso;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnularRetornoPackingRequest;
use App\Http\Requests\RegistrarRetornoPackingRequest;
use App\Http\Requests\UbicarSubloteRetornoPackingRequest;
use App\Http\Resources\FrutaProcesoLoteResource;
use App\Models\Camara;
use App\Models\EntregaFrutaProceso;
use App\Models\LineaProceso;
use App\Models\RetornoPacking;
use App\Models\SubloteRetornoPacking;
use App\Models\TipoResultadoPacking;
use App\Services\MateriaPrima\ServicioRetornoPacking;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class RetornoPackingController extends Controller
{
    public function catalogos(): JsonResponse
    {
        Gate::authorize('consultar-fruta-proceso');

        return response()->json([
            'envases_retorno' => TipoEnvaseRetornoPacking::catalogo(),
            'tipos_resultado' => TipoResultadoPacking::query()
                ->where('activo', true)
                ->orderBy('orden')
                ->orderBy('nombre')
                ->get(['id', 'codigo', 'nombre', 'prefijo_sublote']),
            'camaras' => Camara::query()
                ->where('contenido', ContenidoCamara::MateriaPrima->value)
                ->where('estado', EstadoCamara::Activa->value)
                ->orderBy('codigo')
                ->get(['id', 'codigo', 'nombre']),
            'lineas' => LineaProceso::query()
                ->where('activa', true)
                ->ordenadas()
                ->get(['id', 'codigo', 'nombre']),
            'turnos' => TurnoProceso::catalogo(),
        ]);
    }

    public function store(
        RegistrarRetornoPackingRequest $request,
        EntregaFrutaProceso $entregaFrutaProceso,
        ServicioRetornoPacking $servicio,
    ): FrutaProcesoLoteResource {
        Gate::authorize('entregar-fruta-proceso');

        return new FrutaProcesoLoteResource(
            $servicio->registrar(
                $entregaFrutaProceso,
                $request->validated(),
                $request->user(),
            ),
        );
    }

    public function ubicar(
        UbicarSubloteRetornoPackingRequest $request,
        SubloteRetornoPacking $subloteRetornoPacking,
        ServicioRetornoPacking $servicio,
    ): FrutaProcesoLoteResource {
        Gate::authorize('entregar-fruta-proceso');
        $datos = $request->validated();

        return new FrutaProcesoLoteResource(
            $servicio->ubicar(
                $subloteRetornoPacking,
                $datos['operacion_id'],
                $datos['camara_id'],
                $datos['observacion'] ?? null,
                $request->user(),
            ),
        );
    }

    public function anular(
        AnularRetornoPackingRequest $request,
        RetornoPacking $retornoPacking,
        ServicioRetornoPacking $servicio,
    ): FrutaProcesoLoteResource {
        Gate::authorize('anular-entregas-fruta-proceso');
        $datos = $request->validated();

        return new FrutaProcesoLoteResource(
            $servicio->anular(
                $retornoPacking,
                $datos['operacion_id'],
                $datos['motivo'],
                $request->user(),
            ),
        );
    }
}

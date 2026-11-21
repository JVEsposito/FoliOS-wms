<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarRetencionLoteSagRequest;
use App\Models\BloqueMercado;
use App\Models\Cliente;
use App\Models\Pais;
use App\Models\RetencionLoteSag;
use App\Services\InspeccionSag\GeneradorRetencionLoteSag;
use App\Services\InspeccionSag\ServicioRetencionesLoteSag;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RetencionLoteSagController extends Controller
{
    public function catalogo()
    {
        return response()->json([
            'temporada' => app(ServicioTemporadaActiva::class)->obtener()->only(['id', 'codigo', 'nombre']),
            'fecha_planta' => now()->setTimezone(config('app.operational_timezone'))->toDateString(),
            'clientes' => Cliente::where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            'paises' => Pais::where('activo', true)->orderBy('nombre_es')->get(['id', 'iso_alpha2', 'nombre_es']),
            'bloques' => BloqueMercado::where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre']),
        ]);
    }

    public function index(Request $request)
    {
        $datos = $request->validate(['cliente_id' => ['nullable', 'uuid'], 'lote' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(RetencionLoteSag::ESTADOS)], 'page' => ['nullable', 'integer', 'min:1']]);
        $consulta = RetencionLoteSag::with('cliente:id,nombre,codigo')
            ->where('temporada_id', app(ServicioTemporadaActiva::class)->obtener()->id);
        if (filled($datos['cliente_id'] ?? null)) {
            $consulta->where('cliente_id', $datos['cliente_id']);
        }
        if (filled($datos['lote'] ?? null)) {
            $consulta->where('numero_lote', mb_strtoupper(trim($datos['lote'])));
        }
        if (filled($datos['estado'] ?? null)) {
            $consulta->conEstado($datos['estado']);
        }

        return response()->json($consulta->orderByDesc('updated_at')->orderBy('id')->paginate(25));
    }

    public function store(GuardarRetencionLoteSagRequest $request, ServicioRetencionesLoteSag $servicio)
    {
        $registro = $servicio->guardar($request->validated(), $request->user());

        return $this->detalle($registro, $servicio);
    }

    public function update(GuardarRetencionLoteSagRequest $request, RetencionLoteSag $retencion, ServicioRetencionesLoteSag $servicio)
    {
        return $this->detalle($servicio->guardar($request->validated(), $request->user(), $retencion), $servicio);
    }

    public function show(RetencionLoteSag $retencion, ServicioRetencionesLoteSag $servicio)
    {
        $this->activa($retencion);

        return $this->detalle($retencion, $servicio);
    }

    public function archivo(RetencionLoteSag $retencion, string $formato, GeneradorRetencionLoteSag $generador)
    {
        $this->activa($retencion);
        $nombre = 'SAG-lote-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $retencion->numero_lote).'-v'.$retencion->version;
        if ($formato === 'pdf') {
            return response($generador->pdf($retencion), 200, ['Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$nombre.'.pdf"']);
        }

        return response()->download($generador->xlsx($retencion), $nombre.'.xlsx')->deleteFileAfterSend(true);
    }

    private function activa(RetencionLoteSag $retencion): void
    {
        abort_unless($retencion->temporada_id === app(ServicioTemporadaActiva::class)->obtener()->id, 404);
    }

    private function detalle(RetencionLoteSag $retencion, ServicioRetencionesLoteSag $servicio)
    {
        return response()->json(['registro' => $retencion->load('cliente:id,nombre,codigo'),
            'paises_restringidos' => $servicio->paisesRestringidos($retencion),
            'folios' => $servicio->foliosDelLote($retencion)->all(),
            'historial' => DB::table('eventos_retencion_lote_sag')->where('retencion_id', $retencion->id)->orderByDesc('version')->get()
                ->map(fn ($evento) => ['id' => $evento->id, 'version' => $evento->version, 'usuario' => $evento->usuario_nombre,
                    'fecha' => $evento->created_at, 'antes' => json_decode($evento->antes ?? 'null', true),
                    'despues' => json_decode($evento->despues, true),
                    'folios_afectados' => json_decode($evento->folios_afectados, true)])]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TrasladarLoteMateriaPrimaRequest;
use App\Models\Camara;
use App\Models\LoteMateriaPrima;
use App\Services\Existencias\GeneradorLibroXlsx;
use App\Services\MateriaPrima\RegistroMovimientosCamaraMp;
use App\Services\MateriaPrima\ServicioTrasladoLoteMateriaPrima;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MovimientoCamaraMpController extends Controller
{
    public function ubicacion(LoteMateriaPrima $loteMateriaPrima): JsonResponse
    {
        Gate::authorize('consultar-materia-prima');
        abort_unless($loteMateriaPrima->temporada_id === app(ServicioTemporadaActiva::class)->buscar()?->id, 404);
        $camara = $loteMateriaPrima->asignacionCamara?->camara;

        return response()->json(['data' => ['id' => $loteMateriaPrima->id, 'version' => $loteMateriaPrima->version,
            'camara' => $camara ? ['id' => $camara->id, 'codigo' => $camara->codigo, 'nombre' => $camara->nombre] : null,
            'saldo' => max(0, $loteMateriaPrima->cantidad_envases_primarios - (int) $loteMateriaPrima->entregasProceso()->whereNull('anulado_at')->sum('cantidad_envases')),
            'tipo_envase' => $loteMateriaPrima->envase_primario->value]]);
    }

    public function index(Request $request, RegistroMovimientosCamaraMp $registro): JsonResponse
    {
        Gate::authorize('consultar-materia-prima');
        $pagina = $registro->consulta($this->filtros($request))->paginate(50);
        $pagina->setCollection($registro->filas($pagina->getCollection()));

        return response()->json([...$pagina->toArray(), 'zona_horaria' => config('app.operational_timezone', 'America/Santiago'), 'camaras' => Camara::where('contenido', 'materia_prima')
            ->orderBy('codigo')->get(['id', 'codigo', 'nombre', 'estado'])]);
    }

    public function exportar(Request $request, RegistroMovimientosCamaraMp $registro, GeneradorLibroXlsx $excel): BinaryFileResponse
    {
        Gate::authorize('consultar-materia-prima');
        $filtros = $this->filtros($request);
        $corte = now();
        $zona = config('app.operational_timezone', 'America/Santiago');
        $filas = $registro->filasExportacion($filtros, $corte);
        $titulos = ['fecha' => 'Fecha y hora de planta', 'numero_lote' => 'Lote', 'numero_sublote' => 'Sublote',
            'cliente' => 'Cliente', 'recepcion' => 'Recepción', 'csg' => 'CSG', 'variedad' => 'Variedad', 'lotes_origen' => 'Lotes de origen del sublote',
            'movimiento' => 'Movimiento', 'cantidad_envases' => 'Cantidad', 'tipo_envase' => 'Envase',
            'origen' => 'Origen', 'destino' => 'Destino', 'usuario' => 'Responsable', 'observacion' => 'Motivo / observación', 'detalle' => 'Detalle'];
        $ruta = $excel->generar('Movimientos de cámaras de materia prima', collect($titulos)
            ->map(fn ($titulo, $clave) => ['clave' => $clave, 'titulo' => $titulo, 'ancho' => 26])->values()->all(),
            $filas, ['usuario' => $request->user()->name, 'fecha_corte' => $corte->copy()->setTimezone($zona)->toAtomString(),
                'temporada' => app(ServicioTemporadaActiva::class)->buscar()?->codigo,
                'adicionales' => [['Filtros', json_encode($filtros, JSON_UNESCAPED_UNICODE)],
                    ['Zona horaria', $zona],
                    ['Alcance', 'Temporada activa. Las anulaciones son correcciones del registro.']]], 'Movimientos MP');

        return response()->download($ruta, 'Movimientos_Camaras_MP_'.now()->format('Ymd_His').'.xlsx')
            ->deleteFileAfterSend(true);
    }

    public function trasladar(TrasladarLoteMateriaPrimaRequest $request, LoteMateriaPrima $loteMateriaPrima, ServicioTrasladoLoteMateriaPrima $servicio): JsonResponse
    {
        $lote = $servicio->trasladar($loteMateriaPrima, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $lote->id, 'version' => $lote->version,
            'camara_id' => $lote->asignacionCamara?->camara_id]]);
    }

    private function filtros(Request $request): array
    {
        return $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'], 'lote_id' => ['nullable', 'uuid'],
            'camara_id' => ['nullable', 'uuid'], 'tipo' => ['nullable', Rule::in(array_keys(RegistroMovimientosCamaraMp::TIPOS))],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', ...($request->filled('desde') ? ['after_or_equal:desde'] : [])],
        ]);
    }
}

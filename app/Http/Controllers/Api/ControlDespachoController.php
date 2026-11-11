<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Carga;
use App\Models\ControlDespacho;
use App\Models\FotoControlDespacho;
use App\Models\PersonalAccessToken;
use App\Models\PosicionControlDespacho;
use App\Services\Autenticacion\ContextoOperacional;
use App\Services\ControlDespacho\FormularioControlDespacho;
use App\Services\ControlDespacho\GeneradorPlanillaControlDespacho;
use App\Services\ControlDespacho\PlanillaControlDespacho;
use App\Services\ControlDespacho\ServicioControlDespacho;
use App\Services\ControlDespacho\ServicioFotosControlDespacho;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ControlDespachoController extends Controller
{
    public function __construct(
        private readonly PlanillaControlDespacho $planilla,
        private readonly ServicioControlDespacho $servicio,
    ) {}

    public function catalogo(): JsonResponse
    {
        return $this->json(['data' => FormularioControlDespacho::catalogo()]);
    }

    /** Cargas en andén para la PDA de consolidación. */
    public function cargas(ServicioTemporadaActiva $temporadas): JsonResponse
    {
        $temporada = $temporadas->buscar();
        $cargas = $temporada ? $this->planilla->cargasEnAnden($temporada->id) : collect();

        return $this->json(['data' => $cargas->map(fn (Carga $carga): array => $this->planilla->resumenCarga($carga))->values()]);
    }

    public function index(Request $request, ServicioTemporadaActiva $temporadas): JsonResponse
    {
        $filtros = $request->validate([
            'estado' => ['nullable', Rule::in([ControlDespacho::BORRADOR, ControlDespacho::CERRADO])],
            'buscar' => ['nullable', 'string', 'max:60'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $zona = config('app.operational_timezone', config('app.timezone'));
        $buscar = trim((string) ($filtros['buscar'] ?? ''));
        $pagina = ControlDespacho::query()
            ->where('temporada_id', $temporadas->buscar()?->id)
            ->when($filtros['estado'] ?? null, fn (Builder $consulta, string $estado): Builder => $consulta->where('estado', $estado))
            ->when($filtros['desde'] ?? null, fn (Builder $consulta, string $desde): Builder => $consulta
                ->where('iniciado_at', '>=', CarbonImmutable::parse($desde, $zona)->startOfDay()->utc()))
            ->when($filtros['hasta'] ?? null, fn (Builder $consulta, string $hasta): Builder => $consulta
                ->where('iniciado_at', '<', CarbonImmutable::parse($hasta, $zona)->addDay()->startOfDay()->utc()))
            ->when($buscar !== '', fn (Builder $consulta): Builder => $consulta->where(fn (Builder $texto): Builder => $texto
                ->whereHas('carga', fn (Builder $carga): Builder => $carga->where('codigo', 'like', "%{$buscar}%")
                    ->orWhereHas('embarque', fn (Builder $embarque): Builder => $embarque->where('codigo', 'like', "%{$buscar}%")->orWhere('contenedor', 'like', "%{$buscar}%")))
                ->orWhereHas('posiciones.folio', fn (Builder $folio): Builder => $folio->where('numero_folio', 'like', "%{$buscar}%"))))
            ->with(['carga:id,codigo,estado', 'carga.embarque:id,carga_id,codigo,contenedor,cliente_id', 'carga.embarque.cliente:id,nombre', 'iniciadoPor:id,name', 'cerradoPor:id,name'])
            ->withCount('posiciones')
            ->orderByDesc('iniciado_at')
            ->paginate(25)
            ->withQueryString();

        return $this->json([
            'data' => collect($pagina->items())->map(fn (ControlDespacho $control): array => [
                'id' => $control->id,
                'estado' => $control->estado,
                'carga' => ['id' => $control->carga?->id, 'codigo' => $control->carga?->codigo, 'estado' => $control->carga?->estado?->value],
                'embarque' => $control->carga?->embarque?->codigo,
                'exportadora' => ($control->encabezado ?? [])['exportadora'] ?? $control->carga?->embarque?->cliente?->nombre,
                'contenedor' => ($control->encabezado ?? [])['contenedor'] ?? $control->carga?->embarque?->contenedor,
                'tipo_camion' => ($control->encabezado ?? [])['tipo_camion'] ?? null,
                'pallets' => $control->posiciones_count,
                'iniciado_at' => $control->iniciado_at?->toAtomString(),
                'iniciado_por' => $control->iniciadoPor?->name,
                'cerrado_at' => $control->cerrado_at?->toAtomString(),
                'cerrado_por' => $control->cerradoPor?->name,
            ])->all(),
            'meta' => ['current_page' => $pagina->currentPage(), 'last_page' => $pagina->lastPage(), 'total' => $pagina->total()],
        ]);
    }

    public function show(ControlDespacho $controlDespacho): JsonResponse
    {
        return $this->detalle($controlDespacho);
    }

    public function iniciar(Request $request, Carga $carga): JsonResponse
    {
        $datos = $request->validate(['operacion_id' => ['required', 'uuid']]);
        $token = $request->user()->currentAccessToken();
        $dispositivo = $token instanceof PersonalAccessToken && $token->dispositivo_id ? $token->dispositivo : null;
        $control = $this->servicio->iniciar($datos['operacion_id'], $carga, $request->user(), $dispositivo);

        return $this->detalle($control);
    }

    public function update(Request $request, ControlDespacho $controlDespacho): JsonResponse
    {
        $datos = $request->validate([
            'operacion_id' => ['required', 'uuid'],
            'version' => ['required', 'integer', 'min:1'],
            ...FormularioControlDespacho::reglas(),
        ]);
        $control = $this->servicio->guardar(
            $datos['operacion_id'],
            $controlDespacho,
            (int) $datos['version'],
            collect($datos)->only(['encabezado', 'inspeccion', 'cierre'])->all(),
            $request->user(),
        );

        return $this->detalle($control);
    }

    public function registrarPosicion(Request $request, ControlDespacho $controlDespacho, ContextoOperacional $contexto): JsonResponse
    {
        $datos = $request->validate([
            'operacion_id' => ['required', 'uuid'],
            'numero_folio' => ['required', 'string', 'max:50'],
            'posicion' => ['required', 'integer', 'min:1', 'max:'.FormularioControlDespacho::POSICION_MAXIMA],
            'codigo_pe' => ['nullable', 'string', 'max:10'],
            'cajas' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'temperatura' => ['nullable', 'numeric', 'between:-10,40'],
            'compartir_posicion' => ['nullable', 'boolean'],
            'generado_dispositivo_at' => ['required', 'date'],
        ]);
        [$usuario, $dispositivo] = $contexto->obtener($request);
        $control = $this->servicio->registrarPosicion(
            $datos['operacion_id'],
            $controlDespacho,
            $datos,
            $usuario,
            $dispositivo,
            CarbonImmutable::parse($datos['generado_dispositivo_at']),
        );

        return $this->detalle($control);
    }

    public function quitarPosicion(Request $request, ControlDespacho $controlDespacho, PosicionControlDespacho $posicion): JsonResponse
    {
        abort_unless($posicion->control_despacho_id === $controlDespacho->id, 404);
        $datos = $request->validate(['operacion_id' => ['required', 'uuid']]);

        return $this->detalle($this->servicio->quitarPosicion($datos['operacion_id'], $controlDespacho, $posicion, $request->user()));
    }

    public function cerrar(Request $request, ControlDespacho $controlDespacho): JsonResponse
    {
        $datos = $request->validate(['operacion_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1']]);

        return $this->detalle($this->servicio->cerrar($datos['operacion_id'], $controlDespacho, (int) $datos['version'], $request->user()));
    }

    public function reabrir(Request $request, ControlDespacho $controlDespacho): JsonResponse
    {
        $datos = $request->validate([
            'operacion_id' => ['required', 'uuid'],
            'version' => ['required', 'integer', 'min:1'],
            'motivo' => ['required', 'string', 'max:1000'],
        ]);

        return $this->detalle($this->servicio->reabrir($datos['operacion_id'], $controlDespacho, (int) $datos['version'], $datos['motivo'], $request->user()));
    }

    public function subirFoto(Request $request, ControlDespacho $controlDespacho, ServicioFotosControlDespacho $fotos): JsonResponse
    {
        $datos = $request->validate([
            'archivo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'tipo' => ['required', Rule::in(array_keys(FormularioControlDespacho::tiposFoto()))],
            'operacion_id' => ['required', 'uuid'],
        ]);
        [$foto, $nueva] = $fotos->subir($controlDespacho, $request->file('archivo'), $datos['tipo'], $datos['operacion_id'], $request->user());

        return $this->json(['data' => $foto->representar()], $nueva ? 201 : 200);
    }

    public function eliminarFoto(Request $request, ControlDespacho $controlDespacho, FotoControlDespacho $foto, ServicioFotosControlDespacho $fotos): Response
    {
        abort_unless($foto->control_despacho_id === $controlDespacho->id, 404);
        $fotos->eliminar($controlDespacho, $foto, $request->user());

        return response()->noContent();
    }

    public function foto(ControlDespacho $controlDespacho, FotoControlDespacho $foto, string $variante): Response
    {
        abort_unless($foto->control_despacho_id === $controlDespacho->id, 404);
        $ruta = $variante === 'miniatura' ? $foto->ruta_miniatura : $foto->ruta_original;
        abort_unless(Storage::disk('local')->exists($ruta), 404);
        $respuesta = response()->file(Storage::disk('local')->path($ruta), [
            'Content-Type' => $variante === 'miniatura' ? 'image/jpeg' : $foto->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $respuesta->setPrivate();
        $respuesta->headers->set('Cache-Control', 'no-store, private');

        return $respuesta;
    }

    public function planilla(ControlDespacho $controlDespacho, GeneradorPlanillaControlDespacho $generador): Response
    {
        $ruta = $generador->generar($this->planilla->detalle($controlDespacho));
        $nombre = 'R-24_'.preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $controlDespacho->carga()->value('codigo') ?: $controlDespacho->id).'.xlsx';

        return response()->download($ruta, $nombre, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, private',
        ])->deleteFileAfterSend();
    }

    private function detalle(ControlDespacho $control): JsonResponse
    {
        return $this->json(['data' => $this->planilla->detalle($control)]);
    }

    /** @param  array<string, mixed>  $datos */
    private function json(array $datos, int $estado = 200): JsonResponse
    {
        return response()->json($datos, $estado)->header('Cache-Control', 'no-store, private');
    }
}

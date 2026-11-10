<?php

namespace App\Http\Controllers\Api;

use App\Enums\ModalidadEmbarque;
use App\Http\Controllers\Controller;
use App\Models\SolicitudCargaCliente;
use App\Services\Autorizacion\IdentidadOficina;
use App\Services\Portal\AlcancePortalCliente;
use App\Services\Portal\ServicioPortalCliente;
use App\Services\Portal\ServicioSolicitudesCargaCliente;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Portal de clientes: todo se responde para una exportadora asignada al usuario. */
class PortalClienteController extends Controller
{
    public function __construct(
        private readonly AlcancePortalCliente $alcance,
        private readonly ServicioPortalCliente $portal,
        private readonly ServicioSolicitudesCargaCliente $solicitudes,
    ) {}

    public function contexto(Request $request, IdentidadOficina $identidad): JsonResponse
    {
        $temporada = $this->portal->temporada();

        return $this->json(['usuario' => $identidad->para($request->user()), 'planta' => config('oficina.planta'),
            'temporada' => $temporada ? ['nombre' => $temporada->nombre, 'codigo' => $temporada->codigo] : null]);
    }

    public function resumen(Request $request, string $cliente): JsonResponse
    {
        return $this->json(['data' => $this->portal->resumen($this->alcance->cliente($request->user(), $cliente))]);
    }

    public function recepciones(Request $request, string $cliente): JsonResponse
    {
        $cliente = $this->alcance->cliente($request->user(), $cliente);
        $filtros = $request->validate(['desde' => ['nullable', 'date'], 'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'page' => ['nullable', 'integer', 'min:1'], 'por_pagina' => ['nullable', 'integer', 'min:1', 'max:50']]);

        return $this->json(['data' => $this->portal->recepciones($cliente, $filtros)]);
    }

    public function embarques(Request $request, string $cliente): JsonResponse
    {
        $cliente = $this->alcance->cliente($request->user(), $cliente);
        $filtros = $request->validate(['desde' => ['nullable', 'date'], 'hasta' => ['nullable', 'date', 'after_or_equal:desde']]);

        return $this->json(['data' => $this->portal->embarques($cliente, $filtros)]);
    }

    public function folios(Request $request, string $cliente): JsonResponse
    {
        $cliente = $this->alcance->cliente($request->user(), $cliente);
        $filtros = $request->validate(['q' => ['nullable', 'string', 'max:50'], 'situacion' => ['nullable', Rule::in(['en_planta', 'despachados', 'todos'])],
            'page' => ['nullable', 'integer', 'min:1'], 'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $pagina = $this->portal->folios($cliente, $filtros);

        return $this->json(['data' => $pagina->items(), 'meta' => ['pagina_actual' => $pagina->currentPage(),
            'ultima_pagina' => $pagina->lastPage(), 'total' => $pagina->total()]]);
    }

    public function materiales(Request $request, string $cliente): JsonResponse
    {
        return $this->json(['data' => $this->portal->materiales($this->alcance->cliente($request->user(), $cliente))]);
    }

    public function horarios(Request $request, string $cliente): JsonResponse
    {
        $cliente = $this->alcance->cliente($request->user(), $cliente);
        $datos = $request->validate(['desde' => ['nullable', 'date'], 'dias' => ['nullable', 'integer', 'min:1', 'max:'.ServicioSolicitudesCargaCliente::DIAS_VISIBLES]]);
        $hoy = CarbonImmutable::now('America/Santiago')->startOfDay();
        $desde = isset($datos['desde']) ? CarbonImmutable::parse($datos['desde'], 'America/Santiago')->startOfDay() : $hoy;
        $desde = $desde->lt($hoy) ? $hoy : $desde;
        $hasta = $desde->addDays(($datos['dias'] ?? 7) - 1);

        return $this->json(['data' => $this->solicitudes->horarios($cliente, $desde, $hasta),
            'intervalo_minutos' => (int) ($this->portal->temporada()?->intervalo_embarques_minutos ?? 0)]);
    }

    public function solicitudes(Request $request, string $cliente): JsonResponse
    {
        $cliente = $this->alcance->cliente($request->user(), $cliente);

        return $this->json(['data' => SolicitudCargaCliente::where('cliente_id', $cliente->id)
            ->orderByDesc('fecha')->orderByDesc('hora')->limit(100)->get()
            ->map(fn (SolicitudCargaCliente $s): array => $this->solicitudes->serializar($s))->values()]);
    }

    public function solicitar(Request $request, string $cliente): JsonResponse
    {
        $cliente = $this->alcance->cliente($request->user(), $cliente);
        $datos = $request->validate([
            'operacion_id' => ['required', 'uuid'], 'fecha' => ['required', 'date_format:Y-m-d'], 'hora' => ['required', 'date_format:H:i'],
            'modalidad' => ['nullable', Rule::enum(ModalidadEmbarque::class)],
            'cantidad_pallets' => ['nullable', 'integer', 'min:1', 'max:60'],
            'referencia' => ['nullable', 'string', 'max:150'], 'observacion' => ['nullable', 'string', 'max:1000'],
        ]);
        $solicitud = $this->solicitudes->solicitar($request->user(), $cliente, $datos);

        return $this->json(['data' => $this->solicitudes->serializar($solicitud)], $solicitud->wasRecentlyCreated ? 201 : 200);
    }

    public function anular(Request $request, string $cliente, SolicitudCargaCliente $solicitud): JsonResponse
    {
        $cliente = $this->alcance->cliente($request->user(), $cliente);
        if ($solicitud->cliente_id !== $cliente->id) {
            throw new NotFoundHttpException;
        }
        $datos = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->json(['data' => $this->solicitudes->serializar(
            $this->solicitudes->anular($request->user(), $cliente, $solicitud, (int) $datos['version']),
        )]);
    }

    private function json(array $datos, int $estado = 200): JsonResponse
    {
        return response()->json($datos, $estado)->header('Cache-Control', 'no-store, private');
    }
}
